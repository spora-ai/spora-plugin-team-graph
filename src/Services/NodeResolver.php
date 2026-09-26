<?php

declare(strict_types=1);

namespace Spora\Plugins\TeamGraph\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Services\AgentPictures\Palette;

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
 *
 * The `profile_picture` block mirrors the host's
 * `ProfilePictureService::pictureToWire()` output:
 *
 *   - `kind === 'image'` when `agent_pictures.media_asset_id` is set
 *     (operator uploaded a picture); `image_url` comes from the joined
 *     `media_assets.asset_url` and `image_updated_at` from
 *     `media_assets.updated_at` (cache buster).
 *   - `kind === 'avatar'` for the picked-archetype path;
 *     `bg_color` / `fg_color` are resolved server-side from
 *     `palette_key` via `Palette::background()/foreground()`.
 *   - All fields are null in the inactive branch (no agent_pictures
 *     row → Avatar.vue's initials-fallback fires client-side).
 *
 * Replicating the host's wire-shape here means a single SQL pass
 * covers the whole principal (no N+1 against `agent_pictures` or
 * `media_assets`). Agents with no picture row default to the
 * `Slate` palette so the canvas never goes blank because of a
 * missing avatar — the host's `defaultWireShape()` does the same.
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
     *     profile_picture: array{
     *         kind: 'avatar'|'image',
     *         archetype: string|null,
     *         variant_key: string|null,
     *         palette_key: string|null,
     *         bg_color: string|null,
     *         fg_color: string|null,
     *         image_url: string|null,
     *         image_updated_at: string|null,
     *     },
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
                ap.id AS picture_id,
                ap.archetype,
                ap.variant_key,
                ap.palette_key,
                ap.media_asset_id,
                ma.asset_url AS image_url,
                ma.updated_at AS image_updated_at,
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
              LEFT JOIN agent_pictures ap ON ap.agent_id = a.id
              LEFT JOIN media_assets ma   ON ma.id = ap.media_asset_id
             WHERE a.principal_id = ?
               AND a.is_archived = 0
             GROUP BY a.id, ap.id, ma.id
        SQL;

        $rows = Capsule::connection()->select($sql, [$cutoff, $principalId]);

        return array_map(
            function (object $row): array {
                $paletteKey = $row->palette_key !== null ? (string) $row->palette_key : null;
                $palette    = $paletteKey !== null ? Palette::tryFrom($paletteKey) : null;
                /*
                 * Invalid palette keys (drift, partial migrations, a
                 * palette renamed upstream) silently fall back to
                 * Slate rather than 500-ing the graph endpoint —
                 * the operator should never see the canvas go blank
                 * because of a stale key. The host's
                 * `AgentPictureService::toWireShape()` does the same
                 * via `tryFrom() ?? self::DEFAULT_PALETTE`.
                 */
                $palette ??= Palette::Slate;

                $hasImage = $row->media_asset_id !== null;

                return [
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
                    'profile_picture' => [
                        'kind'             => $hasImage ? 'image' : 'avatar',
                        'archetype'        => $hasImage ? null : ($row->archetype !== null ? (string) $row->archetype : null),
                        'variant_key'      => $hasImage ? null : ($row->variant_key !== null ? (string) $row->variant_key : null),
                        'palette_key'      => $hasImage ? null : $palette->value,
                        'bg_color'         => $hasImage ? null : $palette->background(),
                        'fg_color'         => $hasImage ? null : $palette->foreground(),
                        'image_url'        => $hasImage && $row->image_url !== null ? (string) $row->image_url : null,
                        'image_updated_at' => $hasImage && $row->image_updated_at !== null
                            ? \Carbon\Carbon::parse((string) $row->image_updated_at)->format(\DateTimeInterface::ATOM)
                            : null,
                    ],
                ];
            },
            $rows,
        );
    }
}
