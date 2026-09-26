<?php

declare(strict_types=1);

namespace Spora\Plugins\TeamGraph;

use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\TeamGraph\Http\TeamGraphController;
use Spora\Plugins\TeamGraph\Services\EdgeResolver;
use Spora\Plugins\TeamGraph\Services\NodeResolver;
use Spora\Plugins\TeamGraph\Services\TeamGraphService;
use Spora\Services\ToolConfigService;
use Spora\Services\ToolConfigServiceInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin entry point for `spora-plugin-team-graph`.
 *
 * Read-only v1: ships one admin app (TeamGraphApp) and one endpoint
 * (`GET /api/v1/plugins/team-graph/graph?principal_id=…`) backed by
 * the {@see TeamGraphService} pair (NodeResolver + EdgeResolver).
 * No LLM-callable tools, no migrations, no agent templates.
 *
 * Route registration goes through {@see RoutesRegisteringEvent} (the
 * pattern adopted by spora-core's `extension-interface-events`
 * migration). {@see AbstractPlugin} no longer exposes a `routes()`
 * hook — it was removed when the framework moved to PSR-14 events.
 *
 * DI bindings fire on {@see ContainerBuildingEvent}; the team-graph
 * service + controller are autowired explicitly because they depend
 * on resolvers the host App doesn't know about. The resolvers have
 * no constructor params so they resolve transitively through PHP-DI's
 * autowire-on-build path.
 */
final class TeamGraphPlugin extends AbstractPlugin implements EventSubscriberInterface
{
    /**
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ContainerBuildingEvent::class => 'onContainerBuilding',
            RoutesRegisteringEvent::class => 'onRoutesRegistering',
        ];
    }

    /**
     * Register the team-graph service stack + controller so PHP-DI can
     * autowire them at request-dispatch time. The NodeResolver +
     * EdgeResolver leaves autowired (zero constructor params).
     *
     * `ToolConfigServiceInterface` is bound explicitly to the
     * concrete `ToolConfigService` because PHP-DI cannot autowire
     * an interface without an alias — the host's container binds
     * the concrete only (see spora-core's
     * `app/Core/ContainerDefinitions.php:566`), so without this
     * alias every controller request throws an
     * `InvalidDefinition` for the interface.
     */
    public function onContainerBuilding(ContainerBuildingEvent $event): void
    {
        $event->builder()->addDefinitions([
            TeamGraphService::class              => \DI\autowire(),
            TeamGraphController::class           => \DI\autowire(),
            NodeResolver::class                  => \DI\autowire(),
            EdgeResolver::class                  => \DI\autowire(),
            ToolConfigServiceInterface::class    => \DI\get(ToolConfigService::class),
        ]);
    }

    /**
     * Register the single `GET /api/v1/plugins/team-graph/graph`
     * endpoint behind Auth + CSRF. The path + middleware array live in
     * `routes/team-graph.php` so the route surface stays discoverable
     * in one file.
     */
    public function onRoutesRegistering(RoutesRegisteringEvent $event): void
    {
        [$path, $handler, $middleware] = TeamGraphRoutes::graph();
        $event->routes()->addRoute('GET', $path, $handler, $middleware);
    }

    public function getName(): string
    {
        return (new TeamGraphApp())->displayName();
    }

    /**
     * @return array<int, class-string<\Spora\Apps\AppInterface>>
     */
    public function apps(): array
    {
        return [
            TeamGraphApp::class,
        ];
    }

    /**
     * @return array<int, class-string<\Spora\Tools\ToolInterface>>
     */
    public function tools(): array
    {
        return [];
    }
}
