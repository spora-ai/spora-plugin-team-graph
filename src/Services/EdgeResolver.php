<?php

declare(strict_types=1);

namespace Spora\Plugins\TeamGraph\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use JsonException;
use Spora\Services\Exceptions\PrincipalNotAccessibleException;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\SubAgentTool;

/**
 * Edges come from each source agent's *configured* `allowed_target_agents`
 * list — the same list {@see SubAgentTool::isTargetOnAllowlist()} checks at
 * runtime, read through the same
 * {@see ToolConfigServiceInterface::getEffectiveSettings()} cascade. So the
 * graph shows the connections the tool would let an agent fire, including
 * configured-but-never-used ones.
 *
 * "The same cascade" means the same *call shape*, not the same arguments as
 * an earlier revision of this class: like the runtime, we pass a user id and
 * **no** `PrincipalContext`. A context would collapse
 * `ToolConfigPrincipalCascade::resolvePrincipalIdsWithUserRef()` to the single
 * principal being graphed, so a group agent would be drawn against a
 * different allowlist than the one the tool actually enforces.
 *
 * That list is then enriched with last-24h activity from executed
 * `sub_agent` calls recorded in `tool_calls`. The enrichment is
 * best-effort: it never removes a configured edge.
 *
 * Cross-principal targets are dropped, mirroring
 * `SubAgentTool::sharePrincipal()`, so the visual graph never advertises a
 * connection that would be refused at execution time.
 */
final class EdgeResolver
{
    /**
     * `tool_calls.operation` value for the delegating op. `SubAgentTool`
     * declares a second operation, `handover`, on the same tool — a
     * materially different relationship (the source chat closes instead of
     * waiting) that must not be folded into the same aggregate.
     */
    private const OPERATION_SUB_AGENT = 'sub_agent';

    public function __construct(
        private readonly ToolConfigServiceInterface $toolConfig,
        /**
         * Ownership gate for callers that reach this resolver directly.
         * Required rather than nullable: a defaulted dependency here
         * would let the gate silently no-op for whoever constructed the
         * resolver without it, and a security check that can be
         * switched off by omission is not one. The autowired container
         * supplies it; tests must pass it too.
         */
        private readonly PrincipalService $principals,
    ) {}

    /**
     * @throws PrincipalNotAccessibleException When the caller doesn't control the principal.
     *
     * @return list<array{
     *     id: string,
     *     source: int,
     *     target: int,
     *     op: string,
     *     configured: true,
     *     count_24h: int,
     *     last_invoked_at: string|null,
     * }>
     */
    public function resolveEdges(int $principalId, int $callerUserId): array
    {
        $this->assertCallerControlsPrincipal($callerUserId, $principalId);

        $sourceAgentIds = $this->principalAgentIds($principalId);
        if ($sourceAgentIds === []) {
            return [];
        }

        $configuredTargets = $this->resolveConfiguredTargets(
            $sourceAgentIds,
            $principalId,
            $callerUserId,
        );
        if ($configuredTargets === []) {
            return [];
        }

        $activity = $this->resolveRecentActivity($principalId);

        $edges = [];
        foreach ($configuredTargets as $sourceId => $targetIds) {
            foreach ($targetIds as $targetId) {
                $key = $sourceId . '->' . $targetId;
                $stat = $activity[$key] ?? null;
                $edges[] = [
                    'id'              => $key,
                    'source'          => $sourceId,
                    'target'          => $targetId,
                    'op'              => $stat === null ? self::OPERATION_SUB_AGENT : $stat['op'],
                    'configured'      => true,
                    'count_24h'       => $stat['count_24h'] ?? 0,
                    'last_invoked_at' => $stat === null
                        ? null
                        : (new DateTimeImmutable($stat['last_invoked_at']))->format(DateTimeInterface::ATOM),
                ];
            }
        }

        return $edges;
    }

    /**
     * Defence in depth: this class is autowired into the host container, so
     * any plugin can reach it through `\DI\get()` and would otherwise be
     * able to read another principal's allowlists. Mirrors the gate
     * `SubAgentService` and `AgentTargetResolver` apply on the runtime path.
     */
    private function assertCallerControlsPrincipal(int $callerUserId, int $principalId): void
    {
        if (!$this->principals->callerControlsPrincipal($callerUserId, $principalId)) {
            throw new PrincipalNotAccessibleException(
                "Caller {$callerUserId} does not control principal {$principalId}.",
            );
        }
    }

    /**
     * @return list<int>
     */
    private function principalAgentIds(int $principalId): array
    {
        $rows = Capsule::table('agents')
            ->where('principal_id', $principalId)
            ->where('is_archived', 0)
            ->select(['id'])
            ->get();

        return array_values(array_map(static fn(object $r): int => (int) $r->id, $rows->all()));
    }

    /**
     * Keep only targets that are non-archived and share the source's
     * principal, so the rendered set matches what `SubAgentTool` would let
     * through.
     *
     * @param  list<int> $sourceAgentIds
     * @return array<int, list<int>>
     */
    private function resolveConfiguredTargets(array $sourceAgentIds, int $principalId, int $callerUserId): array
    {
        $candidateTargetIds = [];
        $allowlists = [];
        // N+1 by design: `getEffectiveSettings()` is a service call that
        // walks global → group[0..N] → user-principal → agent override
        // behind crypto decode and schema normalisation, so batching it
        // would mean re-implementing that cascade here. ~4 round-trips per
        // source agent (~800 on a 200-agent principal) is accepted for v1's
        // single admin-panel GET rather than trading correctness for it.
        foreach ($sourceAgentIds as $sourceId) {
            $settings = $this->toolConfig->getEffectiveSettings(
                SubAgentTool::class,
                $sourceId,
                $callerUserId,
            );
            $allowed = $this->coerceToIntList($settings['allowed_target_agents'] ?? []);
            if ($allowed === []) {
                continue;
            }
            $allowlists[$sourceId] = $allowed;
            foreach ($allowed as $tid) {
                $candidateTargetIds[$tid] = true;
            }
        }
        if ($candidateTargetIds === []) {
            return [];
        }

        $candidateTargetIds = array_keys($candidateTargetIds);
        $targetPrincipalById = [];
        $rows = Capsule::table('agents')
            ->whereIn('id', $candidateTargetIds)
            ->where('is_archived', 0)
            ->select(['id', 'principal_id'])
            ->get();
        foreach ($rows as $row) {
            $targetPrincipalById[(int) $row->id] = (int) $row->principal_id;
        }

        $filtered = [];
        foreach ($allowlists as $sourceId => $targets) {
            $kept = [];
            foreach ($targets as $targetId) {
                if (($targetPrincipalById[$targetId] ?? null) !== $principalId) {
                    continue;
                }
                $kept[] = $targetId;
            }
            if ($kept !== []) {
                $filtered[$sourceId] = array_values(array_unique($kept));
            }
        }

        return $filtered;
    }

    /**
     * Best-effort activity per `"source->target"` for every executed
     * `sub_agent` invocation whose parent task belongs to the principal.
     * The 7-day window is the `last_invoked_at` watermark, so a dormant edge
     * keeps a useful "last seen"; the separate 24h count labels the edge.
     *
     * Only the `sub_agent` operation counts — a `handover` row on the same
     * `tool_name` closes the source chat rather than delegating and waiting,
     * so folding it in would inflate both numbers for an edge that never
     * happened. `APPROVED` + a stamped `executed_at` is the pair
     * `ToolCallExecutor` writes once a call leaves the proposal state;
     * `PENDING_APPROVAL`, `REJECTED` and `DISABLED` rows never get there.
     *
     * `last_invoked_at` stays a raw `Y-m-d H:i:s` column value here so the
     * watermark comparisons below are plain string compares in the same
     * timezone the rows were written in; the caller formats it as ATOM.
     *
     * @return array<string, array{op: string, count_24h: int, last_invoked_at: string}>
     */
    private function resolveRecentActivity(int $principalId): array
    {
        $cutoff7d  = \Illuminate\Support\Carbon::now()->subDays(7)->format('Y-m-d H:i:s');
        $cutoff24h = \Illuminate\Support\Carbon::now()->subHours(24)->format('Y-m-d H:i:s');

        $rows = Capsule::connection()->select(
            <<<'SQL'
                SELECT
                    tc.created_at         AS created_at,
                    tc.operation          AS operation,
                    tc.proposed_arguments AS proposed_arguments,
                    t.agent_id            AS parent_agent_id
                  FROM tool_calls tc
                  JOIN tasks t ON t.id = tc.task_id
                 WHERE tc.tool_name = 'sub_agent'
                   AND tc.operation = ?
                   AND tc.status = 'APPROVED'
                   AND tc.executed_at IS NOT NULL
                   AND tc.created_at >= ?
                   AND t.principal_id = ?
            SQL,
            [self::OPERATION_SUB_AGENT, $cutoff7d, $principalId],
        );

        $activity = [];
        foreach ($rows as $row) {
            $targetId = $this->extractTargetAgentId($row->proposed_arguments);
            if ($targetId === null) {
                continue;
            }
            $createdAt = (string) $row->created_at;
            if ($createdAt === '') {
                continue;
            }
            $key = ((int) $row->parent_agent_id) . '->' . $targetId;
            if (!isset($activity[$key])) {
                $activity[$key] = [
                    'op'              => (string) $row->operation,
                    'count_24h'       => 0,
                    'last_invoked_at' => $createdAt,
                ];
            }
            if ($createdAt > $activity[$key]['last_invoked_at']) {
                $activity[$key]['last_invoked_at'] = $createdAt;
            }
            if ($createdAt >= $cutoff24h) {
                $activity[$key]['count_24h']++;
            }
        }

        return $activity;
    }

    /**
     * @param  mixed $raw
     * @return list<int>
     */
    private function coerceToIntList(mixed $raw): array
    {
        if (is_array($raw) === false || $raw === []) {
            return [];
        }
        $out = [];
        foreach ($raw as $value) {
            if (is_int($value)) {
                $out[] = $value;
            } elseif (is_string($value) && $value !== '' && ctype_digit($value)) {
                $out[] = (int) $value;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * The agent id a `proposed_arguments` payload names, or `null`.
     *
     * The three string forms are the LLM's, in the order
     * `SubAgentTool::resolveTargetAgentId()` tries them: a name with the
     * id in parentheses ("Research Agent (#3)"), a bare "#3", and a plain
     * integer string. Mirroring the order matters — the parenthesised
     * form must win over the bare-hash form, and a name like
     * "Agent #3" only parses under the first.
     */
    private function extractTargetAgentId(mixed $raw): ?int
    {
        $decoded = $this->decodeJson($raw);
        $value = is_array($decoded) ? ($decoded['target_agent_id'] ?? null) : null;

        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }

        foreach (['/.*\(#(\d+)\)\s*$/', '/^#(\d+)\s*$/'] as $pattern) {
            if (preg_match($pattern, $value, $m) === 1) {
                return (int) $m[1];
            }
        }

        return ctype_digit($value) ? (int) $value : null;
    }

    /**
     * `proposed_arguments` arrives as a JSON string, but a decoded array
     * is accepted too so a caller that already parsed it is not forced
     * to re-encode. Anything unparseable is `null`, not an exception:
     * the column holds model-authored text and a malformed row must not
     * take the whole graph down.
     *
     * @return mixed
     */
    private function decodeJson(mixed $raw) // NOSONAR php:S1142 — three returns for three input shapes (already an array / not a string / unparseable), where the unparseable case is a catch rather than a branch and cannot be folded into the guard
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            return null;
        }

        try {
            return json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // An empty string is not malformed JSON, it is an absent
            // value; it decodes to null, which is the same answer.
            return null;
        }
    }
}
