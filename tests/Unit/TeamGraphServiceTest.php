<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Mockery as M;
use Spora\Plugins\TeamGraph\Services\EdgeResolver;
use Spora\Plugins\TeamGraph\Services\NodeResolver;
use Spora\Plugins\TeamGraph\Services\TeamGraphService;
use Spora\Services\Exceptions\PrincipalNotAccessibleException;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\SubAgentTool;

/**
 * Integration coverage for {@see TeamGraphService}. The resolvers
 * are `final` (framework rule), so they cannot be mocked; the tests
 * drive the service against the same in-memory SQLite the feature
 * suite uses, seeded with the rows each scenario needs. The service's
 * real job — principal gate + envelope assembly — is exercised by
 * every test.
 *
 * EdgeResolver depends on {@see ToolConfigServiceInterface}, which is
 * mocked so each scenario can script its own `allowed_target_agents`
 * per agent. The mock only needs `getEffectiveSettings(SubAgentTool,
 * $agentId, …)` — every other call returns a no-op default.
 */

function makeService(?ToolConfigServiceInterface $toolConfig = null): TeamGraphService
{
    $principals = new PrincipalService(new PrincipalResolver());
    $edges      = new EdgeResolver($toolConfig ?? mockToolConfig([]));

    return new TeamGraphService(new NodeResolver(), $edges, $principals);
}

/**
 * @param  array<int, list<int>> $allowlistByAgentId source-agent-id → list of target agent ids
 */
function mockToolConfig(array $allowlistByAgentId): ToolConfigServiceInterface
{
    $mock = M::mock(ToolConfigServiceInterface::class);
    $mock->shouldReceive('getEffectiveSettings')
        ->andReturnUsing(static function (string $toolClass, int $agentId) use ($allowlistByAgentId): array {
            expect($toolClass)->toBe(SubAgentTool::class);
            return ['allowed_target_agents' => $allowlistByAgentId[$agentId] ?? []];
        });
    return $mock;
}

function seedUser(int $userId, string $email): void
{
    Capsule::table('users')->insert([
        'id'         => $userId,
        'email'      => $email,
        'password'   => 'x',
        'username'   => 'user' . $userId,
        'status'     => 0,
        'verified'   => 0,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function seedPrincipal(int $principalId, int $userId): void
{
    Capsule::table('principals')->insert([
        'id'         => $principalId,
        'type'       => 'user',
        'user_id'    => $userId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function seedAgent(int $agentId, int $principalId, bool $archived = false, ?string $paletteKey = null): void
{
    Capsule::table('agents')->insert([
        'id'           => $agentId,
        'principal_id' => $principalId,
        'name'         => 'Agent ' . $agentId,
        'max_steps'    => 5,
        'is_active'    => true,
        'is_archived'  => $archived,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
    if ($paletteKey !== null) {
        Capsule::table('agent_pictures')->insert([
            'agent_id'    => $agentId,
            'palette_key' => $paletteKey,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}

it('builds three nodes with the expected aggregates from three agents', function (): void {
    seedUser(1, 'owner@example.com');
    seedPrincipal(42, 1);
    seedAgent(10, 42);
    seedAgent(11, 42);
    seedAgent(12, 42);

    // One in-flight task on agent 10 (RUNNING), one recent task on agent
    // 11 (within the 24h window — uses created_at since the tasks
    // table has no completed_at column). Agent 12 has no tasks.
    Capsule::table('tasks')->insert([
        'id'           => 100,
        'agent_id'     => 10,
        'principal_id' => 42,
        'status'       => 'RUNNING',
        'user_prompt'  => 'hi',
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
    Capsule::table('tasks')->insert([
        'id'           => 101,
        'agent_id'     => 11,
        'principal_id' => 42,
        'status'       => 'COMPLETED',
        'user_prompt'  => 'hi',
        'created_at'   => date('Y-m-d H:i:s', time() - 60),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);

    $payload = makeService()->buildGraph(42, 1);

    expect($payload['nodes'])->toHaveCount(3)
        ->and(array_column($payload['nodes'], 'id'))->toBe([10, 11, 12])
        ->and($payload['nodes'][0]['active_chats'])->toBe(1)
        ->and($payload['nodes'][0]['status'])->toBe('RUNNING')
        ->and($payload['nodes'][1]['recent_chats_24h'])->toBe(1)
        ->and($payload['nodes'][2]['active_chats'])->toBe(0)
        ->and($payload['principal']['is_current_user_owned'])->toBeTrue()
        ->and($payload['generated_at'])->toBeString();
});

it('emits one edge per configured target, including targets that have never been spawned', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(11, 42); // source: configured to spawn 4 and 7
    seedAgent(12, 42); // source: configured to spawn 7 only
    seedAgent(4, 42);  // target 1 of 11
    seedAgent(7, 42);  // shared target

    $service = makeService(mockToolConfig([
        11 => [4, 7],
        12 => [7],
    ]));

    $payload = $service->buildGraph(42, 1);

    expect($payload['edges'])->toHaveCount(3)
        ->and(array_column($payload['edges'], 'id'))->toBe(['11->4', '11->7', '12->7'])
        ->and($payload['edges'][0]['configured'])->toBeTrue()
        ->and($payload['edges'][0]['count_24h'])->toBe(0)
        ->and($payload['edges'][0]['last_invoked_at'])->toBeNull();
});

it('enriches configured edges with last-24h tool_call counts and last_invoked_at', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(11, 42); // source
    seedAgent(4, 42);  // target

    // 3 calls from 11 → 4 within the last 24h.
    $now = time();
    $calls = [
        [200, 11, 4,  $now - 60],
        [201, 11, 4,  $now - 30],
        [202, 11, 4,  $now - 10],
    ];
    foreach ($calls as [$taskId, $parentId, $targetId, $createdAt]) {
        Capsule::table('tasks')->insert([
            'id'           => $taskId,
            'agent_id'     => $parentId,
            'principal_id' => 42,
            'status'       => 'COMPLETED',
            'user_prompt'  => 'spawn',
            'created_at'   => date('Y-m-d H:i:s', $createdAt - 5),
            'updated_at'   => date('Y-m-d H:i:s', $createdAt),
        ]);
        Capsule::table('tool_calls')->insert([
            'id'                 => $taskId * 10,
            'task_id'            => $taskId,
            'agent_id'           => $parentId,
            'provider_call_id'   => 'p_' . $taskId,
            'tool_name'          => 'sub_agent',
            'tool_class'         => 'Spora\\Tools\\SubAgentTool',
            'tool_type'          => 'output',
            'status'             => 'EXECUTED',
            'proposed_arguments' => json_encode(['target_agent_id' => $targetId, 'prompt' => 'p']),
            'approved_arguments' => json_encode(['target_agent_id' => $targetId, 'prompt' => 'p']),
            'created_at'         => date('Y-m-d H:i:s', $createdAt),
            'updated_at'         => date('Y-m-d H:i:s', $createdAt),
        ]);
    }

    $service = makeService(mockToolConfig([
        11 => [4],
    ]));

    $payload = $service->buildGraph(42, 1);

    expect($payload['edges'])->toHaveCount(1)
        ->and($payload['edges'][0]['id'])->toBe('11->4')
        ->and($payload['edges'][0]['count_24h'])->toBe(3)
        ->and($payload['edges'][0]['last_invoked_at'])->toBe(date('Y-m-d H:i:s', $now - 10));
});

it('drops cross-principal configured targets (defence-in-depth)', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedUser(99, 'foreign@example.com');
    seedPrincipal(99, 99);
    seedAgent(11, 42);            // source in principal 42
    seedAgent(4, 99);             // target in foreign principal 99
    seedAgent(7, 42);             // target in same principal

    // The source agent's allowlist incorrectly references a foreign
    // agent (4) — a stale override or a drift after a principal
    // transfer. The runtime would refuse to fire this edge; the
    // team-graph view must hide it too.
    $service = makeService(mockToolConfig([
        11 => [4, 7],
    ]));

    $payload = $service->buildGraph(42, 1);

    expect($payload['edges'])->toHaveCount(1)
        ->and($payload['edges'][0]['target'])->toBe(7);
});

it('does not emit an edge for an agent with no configured sub-agents', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(11, 42); // source: empty allowlist (schema default)

    // The mock returns ['allowed_target_agents' => []] for any agent
    // not in the explicit map — same shape `getEffectiveSettings`
    // would return when only defaults are present.
    $service = makeService(mockToolConfig([]));

    $payload = $service->buildGraph(42, 1);

    expect($payload['edges'])->toBe([]);
});

it('hides archived agents by filtering on is_archived', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(10, 42);
    seedAgent(11, 42, archived: true);
    seedAgent(12, 42);

    $payload = makeService()->buildGraph(42, 1);

    expect($payload['nodes'])->toHaveCount(2)
        ->and(array_column($payload['nodes'], 'id'))->toBe([10, 12]);
});

it('resolves each node\'s profile_picture to (palette_key, bg_color, fg_color) from agent_pictures.palette_key', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(10, 42, paletteKey: 'indigo');
    seedAgent(11, 42, paletteKey: 'amber');

    $payload = makeService()->buildGraph(42, 1);

    expect($payload['nodes'])->toHaveCount(2);
    // The hex codes mirror Palette::background()/foreground() in
    // spora-core/app/Services/AgentPictures/Palette.php — pinning
    // the exact strings here so a Palette rename (e.g. "indigo" →
    // "deep-indigo") surfaces as a test diff instead of silently
    // shifting the canvas colour. `palette_key` is shipped on the
    // wire so the frontend can attach a Mermaid classDef without
    // re-deriving from bg_color.
    expect($payload['nodes'][0]['profile_picture'])->toBe([
        'palette_key' => 'indigo',
        'bg_color'    => '#4338CA',
        'fg_color'    => '#EEF2FF',
    ]);
    expect($payload['nodes'][1]['profile_picture'])->toBe([
        'palette_key' => 'amber',
        'bg_color'    => '#D97706',
        'fg_color'    => '#FFFBEB',
    ]);
});

it('falls back to Slate palette when an agent has no agent_pictures row', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(10, 42); // no paletteKey → no row seeded

    $payload = makeService()->buildGraph(42, 1);

    // ProfilePictureService::defaultWireShape() uses Slate when no
    // row exists; we mirror that default so the canvas never has
    // a node without a usable (bg, fg) pair.
    expect($payload['nodes'][0]['profile_picture'])->toBe([
        'palette_key' => 'slate',
        'bg_color'    => '#475569',
        'fg_color'    => '#F8FAFC',
    ]);
});

it('falls back to Slate palette when an unknown palette_key is on the row', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(10, 42, paletteKey: 'mauve-from-an-old-version');

    $payload = makeService()->buildGraph(42, 1);

    // Drift resilience: a palette renamed upstream shouldn't 500
    // the graph endpoint. Slate is the safest fallback because
    // it's the default in ProfilePictureService too.
    expect($payload['nodes'][0]['profile_picture'])->toBe([
        'palette_key' => 'slate',
        'bg_color'    => '#475569',
        'fg_color'    => '#F8FAFC',
    ]);
});

it('throws PrincipalNotAccessibleException when the caller does not control the principal', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedUser(99, 'foreign@example.com');
    seedPrincipal(99, 99);

    expect(fn() => makeService()->buildGraph(99, 1))
        ->toThrow(PrincipalNotAccessibleException::class);
});
