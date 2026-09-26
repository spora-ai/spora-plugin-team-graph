<?php

declare(strict_types=1);

/*
 * Route definition for spora-plugin-team-graph.
 *
 * Loaded via Composer's `autoload.files` so the `TeamGraphRoutes`
 * class is available everywhere (production + tests) without
 * relying on PSR-4 path mapping (the file lives in `routes/` by
 * convention, not in `src/`).
 *
 * The plugin's entry point subscribes to `RoutesRegisteringEvent`
 * and reads these constants to register the single
 * `GET /api/v1/plugins/team-graph/graph` endpoint behind
 * `AuthMiddleware` + `CsrfMiddleware`. Centralising the path +
 * middleware list in this file keeps the route surface discoverable
 * in one place (matches the Spora convention of treating route
 * patterns as named constants rather than buried string literals).
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
