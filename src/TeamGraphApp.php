<?php

declare(strict_types=1);

namespace Spora\Plugins\TeamGraph;

use Spora\Apps\VueAppInterface;

/**
 * Admin-panel metadata for the Team Graph plugin.
 *
 * The host loads the frontend bundle from the plugin slug and entry
 * filename. The entry must match the frontend bundle's
 * `build.lib.fileName()` value (default: `main.js`).
 */
final class TeamGraphApp implements VueAppInterface
{
    public function name(): string
    {
        return 'team-graph';
    }

    public function displayName(): string
    {
        return 'Team Graph';
    }

    public function description(): string
    {
        return 'A directed graph of the spawning relationships between this principal\'s agents. Click a node for chat history and connection details.';
    }

    public function icon(): string
    {
        /*
         * Custom brand icon: three nodes connected by three edges.
         * Single <path> element so the host's icon registry (which
         * accepts raw d-strings starting with `M`/`m` as fallback
         * for unbundled names) renders it without modification.
         * Stroke-only shape that picks up `currentColor` and matches
         * the 24x24 viewBox the host's <Icon> component expects.
         *
         *   M 3.5 5.5 a 1.5 1.5 0 1 0 3 0 a 1.5 1.5 0 1 0 -3 0   (top-left node, r=1.5, centered at 5, 5.5)
         *   M 17.5 5.5 a 1.5 1.5 0 1 0 3 0 a 1.5 1.5 0 1 0 -3 0 (top-right node, centered at 19, 5.5)
         *   M 10.5 18.5 a 1.5 1.5 0 1 0 3 0 a 1.5 1.5 0 1 0 -3 0 (bottom node, centered at 12, 18.5)
         *   M 6.5 5.5 L 17.5 5.5   (top edge, between left & right nodes)
         *   M 5.71 6.82 L 11.29 17.18 (left → bottom edge, hugging circle boundaries)
         *   M 18.29 6.82 L 12.71 17.18 (right → bottom edge, same)
         */
        return 'M 3.5 5.5 a 1.5 1.5 0 1 0 3 0 a 1.5 1.5 0 1 0 -3 0 '
            . 'M 17.5 5.5 a 1.5 1.5 0 1 0 3 0 a 1.5 1.5 0 1 0 -3 0 '
            . 'M 10.5 18.5 a 1.5 1.5 0 1 0 3 0 a 1.5 1.5 0 1 0 -3 0 '
            . 'M 6.5 5.5 L 17.5 5.5 '
            . 'M 5.71 6.82 L 11.29 17.18 '
            . 'M 18.29 6.82 L 12.71 17.18';
    }

    public function accent(): string
    {
        /*
         * `violet` (Tailwind's violet-500 ≈ #8b5cf6) was originally
         * paired with `purple` (#a855f7) for ABORTED, but the two
         * blues sit so close on the spectrum that the apps grid
         * showed them as near-duplicates. The frontend status palette
         * now uses fuchsia for ABORTED; `violet` stays the brand
         * accent because it doesn't collide with any status colour.
         */
        return 'violet';
    }

    public function entry(): string
    {
        return 'main.js';
    }
}
