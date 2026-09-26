<?php

declare(strict_types=1);

namespace Spora\Plugins\TeamGraph\Services;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * One SQL pass over the principal's agents — aggregates per-agent
 * `active_chats`, `recent_chats_24h`, and a latest "in flight" status.
 *
 * Active-chats enum mirrors the operator dashboard's RUNNING column:
 *   RUNNING, AWAITING_SUB_AGENTS, PENDING_APPROVAL.
 * Anything else (COMPLETED, FAILED, ABORTED, QUEUED…) is excluded so
 * finished runs don't inflate the count. The 24h window for
 * `recent_chats_24h` uses `tasks.created_at` (the schema has no
 * `completed_at`) so a task created in the last day counts as
 * "recent", regardless of whether it has terminated.
 *
 * The 24h cutoff is computed in PHP (Carbon) and passed as a binding
 * so the same query runs on SQLite and MySQL/MariaDB without
 * engine-specific date arithmetic.
 *
 * The latest-status sub-select picks the most-recent in-flight task per
 * agent (`ORDER BY created_at DESC LIMIT 1`) so the canvas can colour
 * the node even when no chats are active. Archived agents
 * (`is_archived = 1`, added by migration 0056) are dropped here
 * rather than in the controller so every downstream consumer sees
 * the same filtered set.
 */
final class NodeResolver
{
    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     role: string|null,
     *     picture_url: string|null,
     *     status: string,
     *     active_chats: int,
     *     recent_chats_24h: int,
     * }>
     */
    public function resolveNodes(int $principalId): array
    {
        $cutoff = \Illuminate\Support\Carbon::now()->subHours(24)->format('Y-m-d H:i:s');

        $sql = <<<'SQL'
            SELECT
                a.id,
                a.name,
                NULL AS role,
                NULL AS picture_url,
                COALESCE(
                    (SELECT status
                       FROM tasks
                      WHERE agent_id = a.id
                        AND status IN ('RUNNING','AWAITING_SUB_AGENTS','PENDING_APPROVAL')
                      ORDER BY created_at DESC
                      LIMIT 1),
                    'COMPLETED'
                ) AS status,
                (SELECT COUNT(*)
                   FROM tasks t
                  WHERE t.agent_id = a.id
                    AND t.status IN ('RUNNING','AWAITING_SUB_AGENTS','PENDING_APPROVAL')) AS active_chats,
                (SELECT COUNT(*)
                   FROM tasks t
                  WHERE t.agent_id = a.id
                    AND t.created_at >= ?) AS recent_chats_24h
              FROM agents a
             WHERE a.principal_id = ?
               AND a.is_archived = 0
             GROUP BY a.id
        SQL;

        $rows = Capsule::connection()->select($sql, [$cutoff, $principalId]);

        return array_map(
            static fn(object $row): array => [
                'id'              => (int) $row->id,
                'name'            => (string) $row->name,
                'role'            => $row->role !== null ? (string) $row->role : null,
                'picture_url'     => $row->picture_url !== null ? (string) $row->picture_url : null,
                /* COALESCE guarantees the SQL never returns NULL here; the
                 * null-coalesce operator is defensive against engine
                 * surprises but should be unreachable in practice. */
                'status'          => (string) ($row->status ?? 'COMPLETED'),
                'active_chats'    => (int) $row->active_chats,
                'recent_chats_24h' => (int) $row->recent_chats_24h,
            ],
            $rows,
        );
    }
}
