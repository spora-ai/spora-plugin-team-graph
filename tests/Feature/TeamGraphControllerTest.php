<?php

declare(strict_types=1);

use Mockery as M;
use Spora\Plugins\TeamGraph\Http\TeamGraphController;
use Spora\Plugins\TeamGraph\Services\EdgeResolver;
use Spora\Plugins\TeamGraph\Services\NodeResolver;
use Spora\Plugins\TeamGraph\Services\TeamGraphService;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigServiceInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feature coverage for {@see TeamGraphController}.
 *
 * CSRF + Auth middleware attachment is asserted at the plugin level in
 * {@see Tests\Unit\TeamGraphPluginTest}; here we exercise the HTTP
 * envelope: 200 + data, 403, 401, 422.
 *
 * The fixture graph is empty (no agents, no edges) — the controller
 * envelope is what these tests pin, not the resolvers' behaviour.
 */

const GRAPH_PATH = '/api/v1/plugins/team-graph/graph';

function makeGraphController(): array
{
    $auth = bootAuthLayer();
    $principals = new PrincipalService(new PrincipalResolver());
    $toolConfig = M::mock(ToolConfigServiceInterface::class);
    $toolConfig->shouldReceive('getEffectiveSettings')
        ->andReturn(['allowed_target_agents' => []]);
    $service = new TeamGraphService(
        new NodeResolver(),
        new EdgeResolver($toolConfig),
        $principals,
    );
    $controller = new TeamGraphController($auth, $service);

    return [$controller, $auth, $principals];
}

function createGraphTestUser(Spora\Auth\AuthService $authService, string $email = 'graph@example.com'): int
{
    static $seq = 0;
    $seq++;
    $userId = bootAuth($authService, "{$seq}{$email}", 'Password1!', "Graph User {$seq}");

    return $userId;
}

it('GET graph returns 401 when no user is authenticated', function (): void {
    [$controller] = makeGraphController();

    $request = Request::create(GRAPH_PATH . '?principal_id=1', 'GET');
    $response = $controller->graph($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNAUTHORIZED);

    $body = json_decode((string) $response->getContent(), true);
    expect($body['error']['code'])->toBe('UNAUTHENTICATED');
});

it('GET graph returns 200 with the data envelope when the caller controls the principal', function (): void {
    [$controller, $auth] = makeGraphController();
    $userId = createGraphTestUser($auth);
    [, , $principalId] = [null, null, createUserPrincipal($userId)];

    $request = Request::create(GRAPH_PATH . '?principal_id=' . $principalId, 'GET');
    $response = $controller->graph($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);

    $body = json_decode((string) $response->getContent(), true);
    expect($body)->toHaveKey('data')
        ->and($body['data']['principal']['id'])->toBe($principalId)
        ->and($body['data']['principal']['type'])->toBe('user')
        ->and($body['data']['principal']['is_current_user_owned'])->toBeTrue()
        ->and($body['data']['nodes'])->toBeArray()
        ->and($body['data']['edges'])->toBeArray()
        ->and($body['data'])->not->toHaveKey('fixtures')
        ->and($body['data']['generated_at'])->toBeString();
});

it('GET graph returns 403 when the caller does not control the principal', function (): void {
    [$controller, $auth] = makeGraphController();
    $userId = createGraphTestUser($auth, 'caller@example.com');

    // Stand up a foreign principal the caller does NOT own.
    $outsiderId = bootAuth(bootAuthLayer(), 'outsider@example.com', 'Password1!', 'Outsider');
    $foreignPrincipalId = (int) (new PrincipalService(new PrincipalResolver()))
        ->ensureUserPrincipal($outsiderId)->id;

    // Restore the original caller's session (bootAuth switched it).
    simulateLoggedInSession($userId, 'caller@example.com');

    $request = Request::create(GRAPH_PATH . '?principal_id=' . $foreignPrincipalId, 'GET');
    $response = $controller->graph($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);

    $body = json_decode((string) $response->getContent(), true);
    expect($body['error']['code'])->toBe('FORBIDDEN');
});

it('GET graph returns 422 when principal_id is missing', function (): void {
    [$controller, $auth] = makeGraphController();
    $userId = createGraphTestUser($auth);

    $request = Request::create(GRAPH_PATH, 'GET');
    $response = $controller->graph($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);

    $body = json_decode((string) $response->getContent(), true);
    expect($body['error']['code'])->toBe('VALIDATION_ERROR');
});
