<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Plugins\TeamGraph\Services\EdgeResolver;
use Spora\Plugins\TeamGraph\Services\NodeResolver;
use Spora\Plugins\TeamGraph\Services\TeamGraphService;
use Spora\Services\Exceptions\PrincipalNotAccessibleException;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;

/**
 * Integration coverage for {@see TeamGraphService}. The resolvers
 * are `final` (framework rule), so they cannot be mocked; the tests
 * drive the service against the same in-memory SQLite the feature
 * suite uses, seeded with the rows each scenario needs. The service's
 * real job — principal gate + envelope assembly — is exercised by
 * every test.
 */

function makeService(): TeamGraphService
{
    $principals = new PrincipalService(new PrincipalResolver());

    return new TeamGraphService(new NodeResolver(), new EdgeResolver(), $principals);
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

function seedAgent(int $agentId, int $principalId, bool $archived = false): void
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

it('dedupes five sub_agent calls across two parent/target pairs into two edges', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(11, 42); // parent
    seedAgent(12, 42); // parent
    seedAgent(4, 42);  // shared target for agent 11
    seedAgent(7, 42);  // shared target for agent 12

    // 3 calls from 11 → 4, 2 calls from 12 → 7.
    $now = time();
    $calls = [
        [200, 11, 4,  $now - 60],
        [201, 11, 4,  $now - 30],
        [202, 11, 4,  $now - 10],
        [203, 12, 7,  $now - 50],
        [204, 12, 7,  $now - 20],
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

    $payload = makeService()->buildGraph(42, 1);

    expect($payload['edges'])->toHaveCount(2)
        ->and(array_column($payload['edges'], 'id'))->toBe(['11->4', '12->7'])
        ->and($payload['edges'][0]['count_24h'])->toBe(3)
        ->and($payload['edges'][1]['count_24h'])->toBe(2);
});

it('drops cross-principal target edges (defence-in-depth)', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedUser(99, 'foreign@example.com');
    seedPrincipal(99, 99);
    seedAgent(11, 42);
    seedAgent(4, 99); // target in a different principal

    $now = time();
    Capsule::table('tasks')->insert([
        'id'           => 200,
        'agent_id'     => 11,
        'principal_id' => 42,
        'status'       => 'COMPLETED',
        'user_prompt'  => 'spawn',
        'created_at'   => date('Y-m-d H:i:s', $now - 5),
        'updated_at'   => date('Y-m-d H:i:s', $now),
    ]);
    Capsule::table('tool_calls')->insert([
        'id'                 => 2000,
        'task_id'            => 200,
        'agent_id'           => 11,
        'provider_call_id'   => 'p_200',
        'tool_name'          => 'sub_agent',
        'tool_class'         => 'Spora\\Tools\\SubAgentTool',
        'tool_type'          => 'output',
        'status'             => 'EXECUTED',
        'proposed_arguments' => json_encode(['target_agent_id' => 4]),
        'approved_arguments' => json_encode(['target_agent_id' => 4]),
        'created_at'         => date('Y-m-d H:i:s', $now),
        'updated_at'         => date('Y-m-d H:i:s', $now),
    ]);

    $payload = makeService()->buildGraph(42, 1);

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

it('throws PrincipalNotAccessibleException when the caller does not control the principal', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedUser(99, 'foreign@example.com');
    seedPrincipal(99, 99);

    expect(fn() => makeService()->buildGraph(99, 1))
        ->toThrow(PrincipalNotAccessibleException::class);
});
