<?php

declare(strict_types=1);

namespace Spora\Plugins\TeamGraph\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use JsonException;

/**
 * Edges are derived from `tool_calls` rows where the LLM invoked
 * `sub_agent`. `sub_agent` is principal-scoped by spec — the parent
 * task and the target agent must belong to the same principal — but
 * the resolver drops any edge whose target sits in a different
 * principal as defence-in-depth so a future spec drift can't leak
 * edges across teams.
 *
 * The plan specifies `tool_calls.arguments`; the live schema stores
 * the LLM-proposed invocation payload in `tool_calls.proposed_arguments`
 * (JSON-cast column). Approved invocations land in `approved_arguments`,
 * but for `sub_agent` rows the LLM only proposes a target_agent_id by
 * NAME (e.g. `"Research Agent (#1)"`); the actual integer id is
 * resolved later by the tool's allowlist cross-check, not persisted in
 * the row. To stay compatible with both fresh (proposed-only) and
 * approved (proposed + approved) rows, we read `proposed_arguments` —
 * it is populated on every state transition the LLM made and is the
 * only column that always contains the caller's intent.
 *
 * The plan also assumed `target_agent_id` is always an integer; the
 * live tool accepts both bare integers (e.g. `"1"`) and the human-readable
 * form (`"Name (#N)"` / `"#N"`) and the LLM chooses between them.
 * {@see Spora\Tools\SubAgentTool::resolveTargetAgentId()} — we mirror
 * its two regexes so the graph resolves exactly what the tool would have.
 *
 * The query decodes in PHP rather than via SQL JSON functions so the
 * same code path works on SQLite and MySQL / MariaDB.
 *
 * Dedup happens in PHP because the canonical `(parent_agent_id,
 * target_agent_id)` key has no UNIQUE index; the same pair may be
 * invoked many times across the 7-day window. The dedup keeps
 * `count_24h` and `last_invoked_at` per pair so the canvas can label
 * edges with the live activity.
 *
 * Edges with `count_24h = 0` AND `last_invoked_at < 24h ago` are
 * dropped — no zero-weight edges (a relationship neither used nor
 * touched today isn't worth showing).
 */
final class EdgeResolver
{
    /**
     * @return list<array{
     *     id: string,
     *     source: int,
     *     target: int,
     *     op: string,
     *     count_24h: int,
     *     last_invoked_at: string,
     * }>
     */
    public function resolveEdges(int $principalId): array
    {
        $cutoff7d = \Illuminate\Support\Carbon::now()->subDays(7)->format('Y-m-d H:i:s');
        $cutoff24h = \Illuminate\Support\Carbon::now()->subHours(24)->format('Y-m-d H:i:s');

        $sql = <<<'SQL'
            SELECT
                tc.id                  AS tool_call_id,
                tc.created_at          AS created_at,
                tc.proposed_arguments  AS proposed_arguments,
                tc.tool_name           AS tool_name,
                t.agent_id             AS parent_agent_id,
                t.principal_id         AS parent_principal_id
              FROM tool_calls tc
              JOIN tasks t ON t.id = tc.task_id
             WHERE tc.tool_name = 'sub_agent'
               AND tc.created_at >= ?
               AND t.principal_id = ?
        SQL;

        $rows = Capsule::connection()->select($sql, [$cutoff7d, $principalId]);

        $targetIds = [];
        foreach ($rows as $row) {
            $targetId = $this->extractTargetAgentId($row->proposed_arguments);
            if ($targetId !== null) {
                $targetIds[] = $targetId;
            }
        }
        $targetIds = array_values(array_unique($targetIds));

        $principalByAgentId = [];
        if ($targetIds !== []) {
            $agentRows = Capsule::table('agents')
                ->whereIn('id', $targetIds)
                ->select(['id', 'principal_id', 'is_archived'])
                ->get();
            foreach ($agentRows as $agent) {
                if ((int) $agent->is_archived === 1) {
                    continue;
                }
                $principalByAgentId[(int) $agent->id] = (int) $agent->principal_id;
            }
        }

        $aggregates = [];
        foreach ($rows as $row) {
            $parentAgentId = (int) $row->parent_agent_id;
            $targetId = $this->extractTargetAgentId($row->proposed_arguments);
            if ($targetId === null) {
                continue;
            }
            $targetPrincipalId = $principalByAgentId[$targetId] ?? null;
            if ($targetPrincipalId !== (int) $row->parent_principal_id) {
                continue;
            }

            $createdAt = (string) $row->created_at;
            $key = $parentAgentId . '->' . $targetId;
            if (!isset($aggregates[$key])) {
                $aggregates[$key] = [
                    'source'          => $parentAgentId,
                    'target'          => $targetId,
                    'op'              => 'sub_agent',
                    'count_24h'       => 0,
                    'last_invoked_at' => $createdAt,
                ];
            }
            if ($createdAt > $aggregates[$key]['last_invoked_at']) {
                $aggregates[$key]['last_invoked_at'] = $createdAt;
            }
            if ($createdAt >= $cutoff24h) {
                $aggregates[$key]['count_24h']++;
            }
        }

        $edges = [];
        foreach ($aggregates as $key => $agg) {
            $within24h = $agg['count_24h'] > 0;
            $recentlyTouched = $agg['last_invoked_at'] >= $cutoff24h;
            if (!$within24h && !$recentlyTouched) {
                continue;
            }
            $edges[] = [
                'id'              => $key,
                'source'          => $agg['source'],
                'target'          => $agg['target'],
                'op'              => $agg['op'],
                'count_24h'       => $agg['count_24h'],
                'last_invoked_at' => $agg['last_invoked_at'],
            ];
        }

        return $edges;
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
