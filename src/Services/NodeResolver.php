<?php

declare(strict_types=1);

namespace Spora\Plugins\TeamGraph\Services;

use DateTimeInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Services\AgentPictures\Palette;

/**
 * One SQL pass over the principal's agents — per-agent `active_chats`,
 * `recent_chats_24h`, and a latest "in flight" status.
 *
 * The active-chats set mirrors the operator dashboard's RUNNING column
 * (RUNNING, AWAITING_SUB_AGENTS, PENDING_APPROVAL); everything else is
 * excluded so finished runs don't inflate the count. The 24h window uses
 * `tasks.created_at` — the schema has no `completed_at` — and the cutoff is
 * bound from PHP so the query needs no engine-specific date arithmetic.
 * Archived agents are dropped here, not in the controller, so every
 * downstream consumer sees the same set.
 */
final class NodeResolver
{
    /**
     * `profile_picture` mirrors the host's `AgentPictureService` wire
     * shape, replicated in the same pass so there is no N+1 against
     * `agent_pictures` or `media_assets`. `variant_key` is null only on the
     * `kind === 'image'` branch. See `resolveVariantKey()`.
     *
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
                 * Invalid palette keys (drift, partial migrations, an
                 * upstream rename) fall back to Slate rather than 500-ing
                 * the endpoint. Mirrors the host's
                 * `AgentPictureService` `tryFrom() ?? DEFAULT_PALETTE`.
                 */
                $palette ??= Palette::Slate;

                $hasImage = $row->media_asset_id !== null;

                /*
                 * A missing `variant_key` is *derived*, never forwarded as
                 * null: the host does the same, and the shared `Avatar`
                 * takes its archetype branch only when
                 * `typeof variant_key === 'string'`. Both sides run the same
                 * FNV-1a derivation, so the canvas and the dashboard cannot
                 * disagree on the glyph. See `resolveVariantKey()`.
                 */
                $variantKey = $hasImage || $row->variant_key !== null
                    ? ($hasImage ? null : (string) $row->variant_key)
                    : self::resolveVariantKey((int) $row->id);

                return [
                    'id'              => (int) $row->id,
                    'name'            => (string) $row->name,
                    'role'            => $row->role !== null ? (string) $row->role : null,
                    'picture_url'     => $row->picture_url !== null ? (string) $row->picture_url : null,
                    // The COALESCE makes the `??` unreachable; defensive only.
                    'status'          => (string) ($row->status ?? 'COMPLETED'),
                    'active_chats'    => (int) $row->active_chats,
                    'recent_chats_24h' => (int) $row->recent_chats_24h,
                    'profile_picture' => [
                        'kind'             => $hasImage ? 'image' : 'avatar',
                        'archetype'        => $hasImage ? null : ($row->archetype !== null ? (string) $row->archetype : null),
                        'variant_key'      => $variantKey,
                        'palette_key'      => $hasImage ? null : $palette->value,
                        'bg_color'         => $hasImage ? null : $palette->background(),
                        'fg_color'         => $hasImage ? null : $palette->foreground(),
                        'image_url'        => $hasImage && $row->image_url !== null ? (string) $row->image_url : null,
                        'image_updated_at' => $hasImage && $row->image_updated_at !== null
                            ? \Carbon\Carbon::parse((string) $row->image_updated_at)->format(DateTimeInterface::ATOM)
                            : null,
                    ],
                ];
            },
            $rows,
        );
    }

    /** The variant count the host's archetype table has (`v0`, `v1`, `v2`). */
    private const VARIANT_COUNT = 3;

    /**
     * Deterministic 3-bucket variant selection, byte-identical to the host's
     * `ProfilePictureService::resolveVariantKey()`.
     *
     * Duplicated rather than reached for through DI on purpose: the resolver's
     * job is a single SQL pass needing no service graph, and a wrong variant is
     * a wrong glyph, not a wrong number. Changing this means changing the
     * host's too.
     */
    private static function resolveVariantKey(int $agentId): string
    {
        $s    = (string) $agentId;
        $hash = 0x811C9DC5;
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $hash ^= ord($s[$i]);
            $hash = ($hash * 0x01000193) & 0xFFFFFFFF;
        }

        return 'v' . ($hash % self::VARIANT_COUNT);
    }
}
