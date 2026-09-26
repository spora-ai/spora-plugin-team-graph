<?php

declare(strict_types=1);

namespace Spora\Plugins\TeamGraph\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use JsonException;
use Spora\Services\PrincipalContext;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\SubAgentTool;

/**
 * Edges are derived from each source agent's *configured*
 * `allowed_target_agents` list — the same list that
 * {@see SubAgentTool::isTargetOnAllowlist()} checks at runtime, so the
 * graph shows exactly the connections the tool would let an agent fire.
 *
 * The configured graph is then enriched with last-24h activity from
 * `tool_calls.proposed_arguments` so the operator can see *which*
 * configured edges are actually being used today (vs. just sitting on
 * the allowlist). The historical enrichment is best-effort — the
 * configured edge always shows up even if `tool_calls` returns nothing,
 * so unconfigured-and-never-used connections still appear.
 *
 *   - **Source** = `agent_tool_overrides.settings` (encrypted JSON) for
 *     the source agent, read via the same
 *     {@see ToolConfigServiceInterface::getEffectiveSettings()} cascade
 *     the runtime uses (defaults → global → group cascade → user
 *     principal → agent override). Schema defaults (an empty list) are
 *     applied last, matching `SubAgentTool`'s `required: true` contract.
 *
 *   - **Intra-principal invariant** — the runtime rejects any
 *     configured target in a different principal via
 *     `SubAgentTool::sharePrincipal()`. We apply the same filter here
 *     so the visual graph never advertises a connection that would be
 *     refused at execution time.
 *
 *   - **Historical enrichment** — a single SQL pass over
 *     `tool_calls` (last 7 days, `tool_name = 'sub_agent'`) builds a
 *     `(parent_agent_id, target_agent_id) → {count_24h, last_invoked_at}`
 *     map. This is folded into the configured edge list so the canvas
 *     can label edges with recent activity without an N+1 query.
 */
final class EdgeResolver
{
    public function __construct(
        private readonly ToolConfigServiceInterface $toolConfig,
    ) {}

    /**
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
                    'op'              => 'sub_agent',
                    'configured'      => true,
                    'count_24h'       => $stat['count_24h'] ?? 0,
                    'last_invoked_at' => $stat['last_invoked_at'] ?? null,
                ];
            }
        }

        return $edges;
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
     * For each source agent in the principal, read its effective
     * `allowed_target_agents` list and keep only targets that (a) are
     * non-archived and (b) live in the same principal as the source.
     *
     * The host's `ToolConfigService` cascade is principal-agnostic by
     * design (it returns the merged settings from defaults → global →
     * group cascade → user principal → agent override), so the
     * intra-principal filter is what keeps the team-graph view
     * consistent with what `SubAgentTool::execute()` would let through
     * at runtime.
     *
     * @param  list<int> $sourceAgentIds
     * @return array<int, list<int>>
     */
    private function resolveConfiguredTargets(array $sourceAgentIds, int $principalId, int $callerUserId): array
    {
        $candidateTargetIds = [];
        $allowlists = [];
        foreach ($sourceAgentIds as $sourceId) {
            $settings = $this->toolConfig->getEffectiveSettings(
                SubAgentTool::class,
                $sourceId,
                $callerUserId,
                new PrincipalContext(
                    principalId: $principalId,
                    type: 'principal',
                    ownerUserId: $callerUserId,
                    runnerUserId: $callerUserId,
                ),
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
     * Best-effort 24h activity for every `sub_agent` invocation whose
     * parent task belongs to the principal. Returns a per-pair aggregate
     * keyed `"source->target"` so the caller can fold it onto the
     * configured-edge list in O(1).
     *
     * Uses a 7-day window for the `last_invoked_at` watermark (so
     * recently-touched edges retain a useful "last seen" timestamp even
     * when they fell off the 24h count) and a separate 24h count to
     * label the edge on the canvas.
     *
     * @return array<string, array{count_24h: int, last_invoked_at: string}>
     */
    private function resolveRecentActivity(int $principalId): array
    {
        $cutoff7d  = \Illuminate\Support\Carbon::now()->subDays(7)->format('Y-m-d H:i:s');
        $cutoff24h = \Illuminate\Support\Carbon::now()->subHours(24)->format('Y-m-d H:i:s');

        $rows = Capsule::connection()->select(
            <<<'SQL'
                SELECT
                    tc.created_at         AS created_at,
                    tc.proposed_arguments AS proposed_arguments,
                    t.agent_id            AS parent_agent_id
                  FROM tool_calls tc
                  JOIN tasks t ON t.id = tc.task_id
                 WHERE tc.tool_name = 'sub_agent'
                   AND tc.created_at >= ?
                   AND t.principal_id = ?
            SQL,
            [$cutoff7d, $principalId],
        );

        $activity = [];
        foreach ($rows as $row) {
            $targetId = $this->extractTargetAgentId($row->proposed_arguments);
            if ($targetId === null) {
                continue;
            }
            $key = ((int) $row->parent_agent_id) . '->' . $targetId;
            $createdAt = (string) $row->created_at;
            if (!isset($activity[$key])) {
                $activity[$key] = ['count_24h' => 0, 'last_invoked_at' => $createdAt];
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

    private function extractTargetAgentId(mixed $raw): ?int
    {
        $decoded = $this->decodeJson($raw);
        if (!is_array($decoded)) {
            return null;
        }
        $value = $decoded['target_agent_id'] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            if (preg_match('/.*\(#(\d+)\)\s*$/', $value, $m)) {
                return (int) $m[1];
            }
            if (preg_match('/^#(\d+)\s*$/', $value, $m)) {
                return (int) $m[1];
            }
            if (ctype_digit($value)) {
                return (int) $value;
            }
        }
        return null;
    }

    /**
     * @return mixed
     */
    private function decodeJson(mixed $raw)
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
