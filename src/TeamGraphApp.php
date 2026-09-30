<?php

declare(strict_types=1);

namespace Spora\Plugins\TeamGraph;

use Spora\Apps\VueAppInterface;

/**
 * Admin-panel metadata for the Team Graph plugin.
 *
 * The host loads the frontend bundle from the plugin slug + entry
 * filename, so `entry()` must match the frontend bundle's
 * `build.lib.fileName()` (default `main.js`).
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
         * Raw `d` path rather than a bundled icon name: the host registry
         * falls back to rendering a string starting with `M`/`m` as a path.
         * Stroke-only, so it inherits `currentColor` and the 24×24 viewBox.
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
         * `violet` as the brand accent because no status colour collides
         * with it — pairing it with `purple` for ABORTED made the two read
         * as near-duplicates. The frontend now uses fuchsia for ABORTED.
         */
        return 'violet';
    }

    public function entry(): string
    {
        return 'main.js';
    }
}
