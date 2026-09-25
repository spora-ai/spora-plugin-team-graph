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
        return 'git-fork';
    }

    public function accent(): string
    {
        return 'violet';
    }

    public function entry(): string
    {
        return 'main.js';
    }
}
