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

function seedAgent(
    int $agentId,
    int $principalId,
    bool $archived = false,
    ?string $paletteKey = null,
    ?string $archetype = null,
    ?string $variantKey = null,
): void {
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
    if ($paletteKey !== null || $archetype !== null || $variantKey !== null) {
        Capsule::table('agent_pictures')->insert([
            'agent_id'    => $agentId,
            'palette_key' => $paletteKey,
            'archetype'   => $archetype,
            // Left as SQL NULL when the caller passes null, which is the
            // case the variant-derivation tests below depend on.
            'variant_key' => $variantKey,
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

it('resolves each node\'s profile_picture (full host wire shape) from agent_pictures.palette_key', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(10, 42, paletteKey: 'indigo');
    seedAgent(11, 42, paletteKey: 'amber');

    $payload = makeService()->buildGraph(42, 1);

    expect($payload['nodes'])->toHaveCount(2);
    // The full wire shape mirrors the host's
    // `ProfilePictureService::pictureToWire()` output so the canvas
    // can render the same `Avatar.vue` shape (image / archetype /
    // initials) the dashboard does. Pinning the exact strings here
    // catches Palette renames + archetype enum drift.
    expect($payload['nodes'][0]['profile_picture'])->toBe([
        'kind'             => 'avatar',
        'archetype'        => null,
        // Derived, never null — see the derivation tests below.
        // `fnv1a(10) % 3`.
        'variant_key'      => 'v0',
        'palette_key'      => 'indigo',
        'bg_color'         => '#4338CA',
        'fg_color'         => '#EEF2FF',
        'image_url'        => null,
        'image_updated_at' => null,
    ]);
    expect($payload['nodes'][1]['profile_picture'])->toBe([
        'kind'             => 'avatar',
        'archetype'        => null,
        // `fnv1a(11) % 3`.
        'variant_key'      => 'v2',
        'palette_key'      => 'amber',
        'bg_color'         => '#D97706',
        'fg_color'         => '#FFFBEB',
        'image_url'        => null,
        'image_updated_at' => null,
    ]);
});

it('falls back to Slate palette when an agent has no agent_pictures row', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(10, 42); // no paletteKey → no row seeded

    $payload = makeService()->buildGraph(42, 1);

    // ProfilePictureService::defaultWireShape() uses Slate when no
    // row exists; we mirror that default so the canvas never has
    // a node without a usable (bg, fg) pair. The kind stays
    // 'avatar' (it's not 'image') and the colour fields are
    // populated so Avatar.vue's archetype-fallback can render the
    // agent tile instead of falling through to initials. The host's
    // default also *derives* the variant, so `fnv1a(10) % 3` = v0.
    expect($payload['nodes'][0]['profile_picture'])->toBe([
        'kind'             => 'avatar',
        'archetype'        => null,
        'variant_key'      => 'v0',
        'palette_key'      => 'slate',
        'bg_color'         => '#475569',
        'fg_color'         => '#F8FAFC',
        'image_url'        => null,
        'image_updated_at' => null,
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
        'kind'             => 'avatar',
        'archetype'        => null,
        // Derived, never null — `fnv1a(10) % 3`.
        'variant_key'      => 'v0',
        'palette_key'      => 'slate',
        'bg_color'         => '#475569',
        'fg_color'         => '#F8FAFC',
        'image_url'        => null,
        'image_updated_at' => null,
    ]);
});

it('derives a missing variant_key instead of shipping null on the avatar branch', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    // The two shapes the bug was reported on: an archetype is configured,
    // a variant is not. `agent_pictures.variant_key` is SQL NULL for both.
    seedAgent(9, 42, paletteKey: 'teal', archetype: 'analyst');
    seedAgent(10, 42, paletteKey: 'orange', archetype: 'writer');

    $payload = makeService()->buildGraph(42, 1);

    foreach ($payload['nodes'] as $node) {
        $picture = $node['profile_picture'];
        expect($picture['kind'])->toBe('avatar');
        expect($picture['archetype'])->not->toBeNull();
        // The regression: null here makes the shared `Avatar` fail its
        // `typeof variant_key === 'string'` guard and fall through to the
        // initials branch, so the card showed "SC" / "ST" instead of the
        // agent's glyph while the dashboard showed the glyph.
        expect($picture['variant_key'])->toBeString();
        expect($picture['variant_key'])->toMatch('/^v[0-2]$/');
    }
    // Pinned so a change to the derivation is a deliberate one. These are
    // the host's `fnv1a(agent_id) % 3`: 9 → v2, 10 → v0.
    expect($payload['nodes'][0]['profile_picture']['variant_key'])->toBe('v2');
    expect($payload['nodes'][1]['profile_picture']['variant_key'])->toBe('v0');
});

it('derives exactly the variant the host\'s own AgentPictureService would', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    // Nine ids, so the derivation is exercised across all three buckets
    // and a divergence from the host cannot hide behind one lucky id.
    foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $agentId) {
        seedAgent($agentId, 42, paletteKey: 'slate', archetype: 'assistant');
    }

    $nodes = makeService()->buildGraph(42, 1)['nodes'];
    $host  = new Spora\Services\AgentPictures\AgentPictureService();

    foreach ($nodes as $node) {
        $id = $node['id'];
        expect($node['profile_picture']['variant_key'])
            ->toBe($host->toWireShape($id)['variant_key'], "variant for agent {$id} matches the host");
        // And the archetype / palette pair, while we are here.
        expect($node['profile_picture']['archetype'])->toBe($host->toWireShape($id)['archetype']);
        expect($node['profile_picture']['bg_color'])->toBe($host->toWireShape($id)['bg_color']);
    }
    // Not vacuous: at least two of the three buckets were actually hit.
    $buckets = array_unique(array_column(array_column($nodes, 'profile_picture'), 'variant_key'));
    expect(count($buckets))->toBeGreaterThan(1);
});

it('keeps an operator-chosen variant_key untouched', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(11, 42, paletteKey: 'violet', archetype: 'creative', variantKey: 'v2');

    $payload = makeService()->buildGraph(42, 1);

    // The derivation is a *fallback*, not an override.
    expect($payload['nodes'][0]['profile_picture']['variant_key'])->toBe('v2');
});

it('sends no variant at all on the image branch, where the archetype is replaced', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(3, 42, paletteKey: 'slate', archetype: 'assistant');
    Capsule::table('media_assets')->insert([
        'id'           => 'asset-1',
        'user_id'      => 1,
        'asset_url'    => '/api/v1/assets/asset-1.jpg',
        'media_type'   => 'image',
        'mime_type'    => 'image/jpeg',
        'storage_mode' => 'local',
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
    Capsule::table('agent_pictures')->where('agent_id', 3)->update(['media_asset_id' => 'asset-1']);

    $payload = makeService()->buildGraph(42, 1);

    // An uploaded picture is the whole tile; the archetype branch is not
    // taken, so there is no variant to derive.
    expect($payload['nodes'][0]['profile_picture'])->toMatchArray([
        'kind'             => 'image',
        'archetype'        => null,
        'variant_key'      => null,
        'palette_key'      => null,
        'bg_color'         => null,
        'fg_color'         => null,
        'image_url'        => '/api/v1/assets/asset-1.jpg',
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
