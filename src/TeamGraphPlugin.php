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
 * Read-only v1: one admin app (TeamGraphApp) and one endpoint
 * (`GET /api/v1/plugins/team-graph/graph?principal_id=…`) backed by
 * {@see TeamGraphService}. No LLM-callable tools, no migrations, no agent
 * templates.
 *
 * The entry-point class must stay at `src/TeamGraphPlugin.php` —
 * `PluginLoader` resolves the manifest's FQCN through PSR-4 only. Routes
 * register through {@see RoutesRegisteringEvent}; {@see AbstractPlugin} has
 * no `routes()` hook since the framework moved to PSR-14 events.
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
     * `ToolConfigServiceInterface` needs an explicit alias: PHP-DI cannot
     * autowire an interface, and the host binds only the concrete
     * `ToolConfigService`, so without it every request throws an
     * `InvalidDefinition`. The rest autowire transitively.
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

    /** Path + middleware live in `routes/team-graph.php`, one file per concern. */
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

    /**
     * Translation catalogues this plugin ships, declared through
     * {@see \Spora\Plugins\PluginInterface::translationsPaths()}.
     *
     * Keys are the English source text — `de.json` reads
     * `{"Team Graph": "Team-Graph"}`, not `{"app": "…"}`. That is what
     * makes a copy edit detectable: the key changes and the old entry
     * orphans. The gate is this plugin's own `LocaleCatalogTest`, not the
     * host's `i18n:check` — that command harvests core's `app/` and reads
     * core's `locales/en.json`, so run against a plugin repo it reports
     * every catalogue key as an orphan.
     *
     * **Only operator copy belongs in here.** This plugin ships no tool
     * and declares no setting, so the admin app's `displayName()` and
     * `description()` plus the manifest description are the whole of it.
     *
     * Requires `spora-core >= 0.31.0` for the catalogue to be loaded. On an
     * older core the host never calls this hook and the plugin renders in
     * English.
     *
     * @return string[]
     */
    public function translationsPaths(): array
    {
        return [
            __DIR__ . '/../locales',
        ];
    }
}
