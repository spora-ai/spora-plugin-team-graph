<?php

declare(strict_types=1);

/*
 * Loaded via Composer's `autoload.files` rather than PSR-4: this class lives
 * in `routes/` by convention, not in `src/`. Centralising the path +
 * middleware here keeps the route surface discoverable in one file.
 */

namespace Spora\Plugins\TeamGraph;

use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\TeamGraph\Http\TeamGraphController;

final class TeamGraphRoutes
{
    public const GRAPH_PATH = '/api/v1/plugins/team-graph/graph';

    /** @var list<class-string> */
    public const AUTH = [AuthMiddleware::class, CsrfMiddleware::class];

    /**
     * @return array{0: string, 1: array{0: class-string, 1: string}, 2: list<class-string>}
     */
    public static function graph(): array
    {
        return [
            self::GRAPH_PATH,
            [TeamGraphController::class, 'graph'],
            self::AUTH,
        ];
    }
}
