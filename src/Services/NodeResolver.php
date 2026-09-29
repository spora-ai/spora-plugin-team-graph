<?php

declare(strict_types=1);

namespace Spora\Plugins\TeamGraph\Services;

use DateTimeInterface;
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
 * `AgentPictureService::avatarWireShape()` / `imageWireShape()` output:
 *
 *   - `kind === 'image'` when `agent_pictures.media_asset_id` is set
 *     (operator uploaded a picture); `image_url` comes from the joined
 *     `media_assets.asset_url` and `image_updated_at` from
 *     `media_assets.updated_at` (cache buster).
 *   - `kind === 'avatar'` for the picked-archetype path;
 *     `bg_color` / `fg_color` are resolved server-side from
 *     `palette_key` via `Palette::background()/foreground()`, and a
 *     missing `variant_key` is **auto-derived** the way the host does —
 *     see `resolveVariantKey()` below. That last part is load-bearing:
 *     the shared `Avatar` takes its archetype branch only when
 *     `variant_key` is a string, so forwarding the raw column (as this
 *     resolver used to) turned every agent whose
 *     `agent_pictures.variant_key` is NULL into a pair of initials.
 *   - An agent with no `agent_pictures` row at all still gets the
 *     `Slate` palette and a derived variant, so the canvas never goes
 *     blank because of a missing avatar.
 *
 * Replicating the host's wire-shape here means a single SQL pass
 * covers the whole principal (no N+1 against `agent_pictures` or
 * `media_assets`).
 */
final class NodeResolver
{
    /**
     * `variant_key` is still nullable on the type — the `kind === 'image'`
     * branch sends null, because an uploaded picture replaces the
     * archetype entirely. On the `kind === 'avatar'` branch it is
     * always a string; see `resolveVariantKey()`.
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

                /*
                 * `variant_key` is *resolved*, never passed through raw.
                 *
                 * The host's own `AgentPictureService::avatarWireShape()`
                 * auto-derives a missing one —
                 * `$picture->variant_key ?? $this->resolveVariantKey($id)` —
                 * so `/api/v1/agents` never emits `kind: 'avatar'` with a
                 * null variant, and the shared `Avatar` (whose archetype
                 * branch requires `typeof variant_key === 'string'`)
                 * always takes the archetype tile. This resolver used to
                 * forward the raw column, so any agent whose
                 * `agent_pictures.variant_key` was NULL shipped a
                 * `profile_picture` the host contract says cannot occur and
                 * the canvas fell through to the package's initials
                 * branch: "Spora Core Agent" (archetype `analyst`,
                 * palette `teal`) and "Spora Typst Expert" (archetype
                 * `writer`, palette `orange`) both rendered as bare "SC"
                 * / "ST" letters on the node card while the dashboard,
                 * fed by the same row through the real service, showed
                 * their glyphs.
                 *
                 * The derivation is the host's, re-implemented rather than
                 * re-invented: FNV-1a over the agent id, modulo the
                 * variant count, exactly `ProfilePictureService::
                 * resolveVariantKey()`. Both sides are deterministic, so
                 * the canvas and the dashboard cannot disagree. Changing
                 * it means changing the host too — the host documents
                 * this algorithm as the contract, "the server is the
                 * source of truth".
                 */
                $variantKey = $hasImage || $row->variant_key !== null
                    ? ($hasImage ? null : (string) $row->variant_key)
                    : self::resolveVariantKey((int) $row->id);

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

    /**
     * The variant count the host's archetype table has
     * (`@spora-ai/components → archetypeSvgs`: `v0`, `v1`, `v2`).
     */
    private const VARIANT_COUNT = 3;

    /**
     * Deterministic 3-bucket variant selection, byte-identical to
     * `Spora\Services\ProfilePictures\ProfilePictureService::resolveVariantKey()`
     * and its 32-bit FNV-1a helper.
     *
     * Duplicated rather than reached for through DI on purpose: the
     * resolver's whole job is to be a single SQL pass that needs no
     * service graph, and a wrong variant is a wrong glyph, not a wrong
     * number. `@see ProfilePictureService::$doc` for the "same algorithm
     * on both sides" contract this keeps.
     */
    private static function resolveVariantKey(int $agentId): string
    {
        // The id is stringified *before* it is indexed: `$int[$i]` is not
        // offset access on an int (PHP warns and yields the first digit),
        // so hashing `$agentId` directly would hash "1" for every id.
        $s    = (string) $agentId;
        $hash = 0x811C9DC5;
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $hash ^= ord($s[$i]);
            $hash = ($hash * 0x01000193) & 0xFFFFFFFF;
        }

        return 'v' . ($hash % self::VARIANT_COUNT);
    }
}
