<?php

declare(strict_types=1);

namespace Spora\Plugins\TeamGraph\Services;

use DateTimeInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use Spora\Services\AgentPictures\Archetype;
use Spora\Services\AgentPictures\Palette;

/**
 * One SQL pass over the principal's agents — per-agent `active_chats`,
 * `recent_chats_24h`, and a latest "in flight" status.
 *
 * The in-flight set is the three statuses where the run still belongs to
 * the agent and the operator can still act on it (RUNNING,
 * AWAITING_SUB_AGENTS, PENDING_APPROVAL); every terminal status is
 * excluded so finished runs don't inflate the count. `active_chats` is a
 * raw per-agent task count, not a conversation count — the dashboard's KPI
 * chips dedupe by `agent_id` because they answer a different question
 * ("how many agents look busy?"), while the canvas wants to know how much
 * load one node carries. The 24h window uses `tasks.created_at` — the
 * schema has no `completed_at` — and the cutoff is bound from PHP so the
 * query needs no engine-specific date arithmetic. Archived agents are
 * dropped here, not in the controller, so every downstream consumer sees
 * the same set.
 */
final class NodeResolver
{
    /**
     * The host's `AgentPictureService::DEFAULT_ARCHETYPE`, pinned so a
     * picture row can never leave the canvas without a glyph. See the
     * `Archetype::tryFrom()` call in `resolveNodes()`.
     */
    private const DEFAULT_ARCHETYPE = Archetype::Assistant;

    /**
     * `profile_picture` mirrors the host's `AgentPictureService` wire
     * shape, replicated in the same pass so there is no N+1 against
     * `agent_pictures` or `media_assets`. `archetype` and `variant_key`
     * are null only on the `kind === 'image'` branch — on the avatar
     * branch both are always strings, because the shared `Avatar` takes
     * its archetype tile only then. See `resolveVariantKey()`.
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
        $cutoff = Carbon::now()->subHours(24)->format('Y-m-d H:i:s');

        $sql = <<<'SQL'
            SELECT
                a.id,
                a.name,
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
                      ORDER BY created_at DESC, id DESC
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
        SQL;

        $rows = Capsule::connection()->select($sql, [$cutoff, $principalId]);

        return array_map(static fn(object $row): array => self::nodeFromRow($row), $rows);
    }

    /**
     * One selected row to one node on the envelope.
     *
     * Extracted from the query's `array_map` so the row-shaping rules —
     * which are the part worth reading — are not buried under the SQL
     * that feeds them.
     *
     * @return array<string, mixed>
     */
    private static function nodeFromRow(object $row): array
    {
        return [
            'id'   => (int) $row->id,
            'name' => (string) $row->name,
            /*
             * Wire-compat placeholders, not columns: `agents` has
             * neither one (000003) and the picture data moved to
             * `agent_pictures` in 0062, but the frontend's
             * `GraphNode` type still declares both as required
             * fields, so they stay on the envelope.
             */
            'role'            => null,
            'picture_url'     => null,
            // The COALESCE makes the `??` unreachable; defensive only.
            'status'          => (string) ($row->status ?? 'COMPLETED'),
            'active_chats'    => (int) $row->active_chats,
            'recent_chats_24h' => (int) $row->recent_chats_24h,
            'profile_picture' => self::pictureWireShape($row),
        ];
    }

    /**
     * The `AgentPictureService` wire shape, from columns already joined
     * into the row.
     *
     * The two branches are not variants of one shape: an image carries
     * the picture and nothing else, while an avatar carries a glyph
     * identity (archetype + variant) and its resolved colours. Mixing
     * them is what the shared `Avatar` keys off, so each branch nulls
     * the other's fields rather than emitting both.
     *
     * @return array{
     *     kind: 'image'|'avatar',
     *     archetype: string|null,
     *     variant_key: string|null,
     *     palette_key: string|null,
     *     bg_color: string|null,
     *     fg_color: string|null,
     *     image_url: string|null,
     *     image_updated_at: string|null,
     * }
     */
    private static function pictureWireShape(object $row): array
    {
        $imageUrl = $row->image_url;
        $updatedAt = $row->image_updated_at;
        if ($row->media_asset_id !== null) {
            return [
                'kind'             => 'image',
                'archetype'        => null,
                'variant_key'      => null,
                'palette_key'      => null,
                'bg_color'         => null,
                'fg_color'         => null,
                'image_url'        => $imageUrl !== null ? (string) $imageUrl : null,
                'image_updated_at' => $updatedAt !== null ? self::atom($updatedAt) : null,
            ];
        }

        $paletteKey = $row->palette_key !== null ? (string) $row->palette_key : null;
        $palette = $paletteKey !== null ? Palette::tryFrom($paletteKey) : null;
        /*
         * Invalid palette keys (drift, partial migrations, an upstream
         * rename) fall back to Slate rather than 500-ing the endpoint.
         * Mirrors the host's `AgentPictureService` `tryFrom() ?? DEFAULT_PALETTE`.
         */
        $palette ??= Palette::Slate;

        $stored = $row->archetype !== null ? (string) $row->archetype : null;
        /*
         * A missing or unrecognised `archetype` becomes the host's
         * default rather than a null: the shared `Avatar` takes its
         * archetype tile only when `typeof archetype === 'string'`, so
         * forwarding the raw column sends every agent without one back to
         * initials — the same failure the `variant_key` derivation exists
         * to prevent. Mirrors `AgentPictureService::avatarWireShape()`.
         */
        $archetype = ($stored !== null ? Archetype::tryFrom($stored) : null) ?? self::DEFAULT_ARCHETYPE;

        /*
         * A missing `variant_key` is *derived*, never forwarded as null:
         * the host does the same, and the shared `Avatar` takes its
         * archetype branch only when `typeof variant_key === 'string'`.
         * Both sides run the same FNV-1a derivation, so the canvas and
         * the dashboard cannot disagree on the glyph. See
         * `resolveVariantKey()`.
         */
        $storedVariant = $row->variant_key !== null ? (string) $row->variant_key : null;

        return [
            'kind'             => 'avatar',
            'archetype'        => $archetype->value,
            'variant_key'      => $storedVariant ?? self::resolveVariantKey((int) $row->id),
            'palette_key'      => $palette->value,
            'bg_color'         => $palette->background(),
            'fg_color'         => $palette->foreground(),
            'image_url'        => null,
            'image_updated_at' => null,
        ];
    }

    /** The variant count the host's archetype table has (`v0`, `v1`, `v2`). */
    private const VARIANT_COUNT = 3;

    /**
     * A stored `Y-m-d H:i:s` timestamp in the envelope's wire format.
     *
     * Every date on the envelope is ATOM, so the DB column is parsed
     * here rather than handed on raw: a consumer should not need a second
     * date format, and this is also the cache-buster key the frontend
     * appends to the image URL.
     */
    private static function atom(mixed $value): string
    {
        return Carbon::parse((string) $value)->format(DateTimeInterface::ATOM);
    }

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
        /*
         * The id is cast to a string before it is indexed because offset
         * access on an int is a warning that yields null, and `ord(null)`
         * is 0 — so hashing the int directly would fold every byte of
         * every id into 0 and hand every agent the same variant.
         */
        $s    = (string) $agentId;
        $hash = 0x811C9DC5;
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $hash ^= ord($s[$i]);
            $hash = ($hash * 0x01000193) & 0xFFFFFFFF;
        }

        return 'v' . ($hash % self::VARIANT_COUNT);
    }
}
