<?php

declare(strict_types=1);

use Spora\Core\MiddlewareRouteCollector;
use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\TeamGraph\Http\TeamGraphController;
use Spora\Plugins\TeamGraph\Services\EdgeResolver;
use Spora\Plugins\TeamGraph\Services\NodeResolver;
use Spora\Plugins\TeamGraph\Services\TeamGraphService;
use Spora\Plugins\TeamGraph\TeamGraphApp;
use Spora\Plugins\TeamGraph\TeamGraphPlugin;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Wiring tests for {@see TeamGraphPlugin}. The plugin's role is to
 * bind the team-graph stack into the host's container + route table;
 * the heavier controller / service behaviour lives in the Feature +
 * Unit suites. CSRF + Auth middleware attachment is verified here by
 * inspecting the route entry that {@see RoutesRegisteringEvent}
 * records — the same shape typst + memories use.
 */
beforeEach(function () {
    $this->plugin     = new TeamGraphPlugin();
    $this->dispatcher = new EventDispatcher();
    $this->dispatcher->addSubscriber($this->plugin);
});

it('advertises the plugin as the app\'s display name', function (): void {
    expect($this->plugin->getName())->toBe((new TeamGraphApp())->displayName());
});

it('contributes exactly one admin app', function (): void {
    expect($this->plugin->apps())->toBe([TeamGraphApp::class]);
});

it('contributes zero tools (read-only v1)', function (): void {
    expect($this->plugin->tools())->toBe([]);
});

it('subscribes to ContainerBuildingEvent and RoutesRegisteringEvent', function (): void {
    expect(TeamGraphPlugin::getSubscribedEvents())->toBe([
        ContainerBuildingEvent::class => 'onContainerBuilding',
        RoutesRegisteringEvent::class => 'onRoutesRegistering',
    ]);
});

it('onContainerBuilding wires the four team-graph DI bindings', function (): void {
    $builder = new DI\ContainerBuilder();
    $builder->useAutowiring(true);

    $this->dispatcher->dispatch(new ContainerBuildingEvent($builder));

    $container = $builder->build();

    expect($container->has(TeamGraphService::class))->toBeTrue()
        ->and($container->has(TeamGraphController::class))->toBeTrue()
        ->and($container->has(NodeResolver::class))->toBeTrue()
        ->and($container->has(EdgeResolver::class))->toBeTrue();
});

it('onRoutesRegistering registers the graph endpoint behind Auth + Csrf', function (): void {
    $dataGenerator = new FastRoute\DataGenerator\GroupCountBased();
    $routes = new MiddlewareRouteCollector(
        new FastRoute\RouteParser\Std(),
        $dataGenerator,
    );

    $this->dispatcher->dispatch(new RoutesRegisteringEvent($routes));

    $reflection = new ReflectionObject($dataGenerator);
    $staticRoutes = $reflection->getProperty('staticRoutes')->getValue($dataGenerator);

    $graphRoute = $staticRoutes['GET']['/api/v1/plugins/team-graph/graph'] ?? null;

    expect($graphRoute)->not->toBeNull('graph route is not registered');

    $handler = $graphRoute['handler'];
    expect($handler)->toBe([TeamGraphController::class, 'graph']);

    $middleware = $graphRoute['middleware'] ?? [];
    expect($middleware)->toBe([AuthMiddleware::class, CsrfMiddleware::class]);
});

it('TeamGraphApp satisfies VueAppInterface (name + entry)', function (): void {
    $app = new TeamGraphApp();

    expect($app->name())->toBe('team-graph')
        ->and($app->displayName())->toBe('Team Graph')
        ->and($app->icon())->toBe('git-fork')
        ->and($app->accent())->toBe('violet')
        ->and($app->entry())->toBe('main.js');
});
