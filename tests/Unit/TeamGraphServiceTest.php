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
 * Integration coverage for {@see TeamGraphService}. The resolvers are
 * `final`, so they cannot be mocked; the tests drive the service
 * against the same in-memory SQLite the feature suite uses, seeded with
 * the rows each scenario needs.
 *
 * `ToolConfigServiceInterface` is mocked so each scenario can script its
 * own `allowed_target_agents` per agent.
 */

function makeService(?ToolConfigServiceInterface $toolConfig = null): TeamGraphService
{
    $principals = new PrincipalService(new PrincipalResolver());
    // The resolver is autowired into the host container, so it carries its
    // own ownership gate — hand it one here too rather than exercising the
    // ungated path.
    $edges      = new EdgeResolver($toolConfig ?? mockToolConfig([]), $principals);

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

/**
 * Seed a group plus its group-principal and return the principal id.
 *
 * `created_by_user_id` is an FK to `users`, so the owner row must exist
 * before the group is inserted.
 */
function seedGroupPrincipal(int $groupId, int $principalId, int $ownerUserId, string $name = 'Team'): int
{
    Capsule::table('groups')->insert([
        'id'                => $groupId,
        'name'              => $name,
        'created_by_user_id' => $ownerUserId,
        'created_at'        => date('Y-m-d H:i:s'),
        'updated_at'        => date('Y-m-d H:i:s'),
    ]);
    Capsule::table('principals')->insert([
        'id'         => $principalId,
        'type'       => 'group',
        'group_id'   => $groupId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return $principalId;
}

/** Seed a `group_memberships` row at the given role. */
function seedMembership(int $groupId, int $userId, string $role): void
{
    Capsule::table('group_memberships')->insert([
        'group_id'   => $groupId,
        'user_id'    => $userId,
        'role'       => $role,
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

/**
 * Seed a `sub_agent` tool call on a completed parent task, in the shape
 * `ToolCallExecutor` writes it.
 *
 * `operation`, `status` and `$executed` are parameters because a
 * `tool_calls` row is written for *every* call the LLM proposes, and
 * `SubAgentTool` declares `handover` as a second operation on the same
 * `tool_name` — so neither the tool name nor the row's existence implies
 * that a delegation happened.
 *
 * `$targetAgentId` is `int|string` because the production wire shape is
 * the *label*: `SubAgentTool`'s `target_agent` parameter is the resolved
 * "Name (#id)" `enum` label, so the LLM's `proposed_arguments` carry a
 * string. The int form only shows up in older callers and fixtures.
 */
function seedSubAgentCall(
    int $toolCallId,
    int $taskId,
    int $parentAgentId,
    int|string $targetAgentId,
    int $createdAt,
    string $operation = 'sub_agent',
    string $status = 'APPROVED',
    bool $executed = true,
): void {
    Capsule::table('tasks')->insert([
        'id'           => $taskId,
        'agent_id'     => $parentAgentId,
        'principal_id' => 42,
        'status'       => 'COMPLETED',
        'user_prompt'  => 'spawn',
        'created_at'   => date('Y-m-d H:i:s', $createdAt - 5),
        'updated_at'   => date('Y-m-d H:i:s', $createdAt),
    ]);
    Capsule::table('tool_calls')->insert([
        'id'                 => $toolCallId,
        'task_id'            => $taskId,
        'agent_id'           => $parentAgentId,
        'provider_call_id'   => 'p_' . $toolCallId,
        'tool_name'          => 'sub_agent',
        'tool_class'         => 'Spora\\Tools\\SubAgentTool',
        'tool_type'          => 'output',
        'operation'          => $operation,
        'status'             => $status,
        'proposed_arguments' => json_encode(['target_agent_id' => $targetAgentId, 'prompt' => 'p']),
        'approved_arguments' => json_encode(['target_agent_id' => $targetAgentId, 'prompt' => 'p']),
        // `APPROVED` + a stamped `executed_at` is what takes a call out of
        // the proposal state, so one flag drives both.
        'executed_at'        => $executed ? date('Y-m-d H:i:s', $createdAt) : null,
        'created_at'         => date('Y-m-d H:i:s', $createdAt),
        'updated_at'         => date('Y-m-d H:i:s', $createdAt),
    ]);
}

/** `last_invoked_at` ships as ATOM, matching `generated_at`. */
function atomTimestamp(int $unixTime): string
{
    return (new DateTimeImmutable(date('Y-m-d H:i:s', $unixTime)))->format(DateTimeInterface::ATOM);
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

    // 3 executed sub_agent calls from 11 → 4 within the last 24h.
    $now = time();
    seedSubAgentCall(2000, 200, 11, 4, $now - 60);
    seedSubAgentCall(2010, 201, 11, 4, $now - 30);
    seedSubAgentCall(2020, 202, 11, 4, $now - 10);

    $service = makeService(mockToolConfig([
        11 => [4],
    ]));

    $payload = $service->buildGraph(42, 1);

    expect($payload['edges'])->toHaveCount(1)
        ->and($payload['edges'][0]['id'])->toBe('11->4')
        // `op` is read off the `tool_calls.operation` column rather than
        // hard-coded, so it tracks the relationship the count describes.
        ->and($payload['edges'][0]['op'])->toBe('sub_agent')
        ->and($payload['edges'][0]['count_24h'])->toBe(3)
        ->and($payload['edges'][0]['last_invoked_at'])->toBe(atomTimestamp($now - 10));
});

it('resolves the target id from the label string the LLM actually sends', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(11, 42); // source
    seedAgent(4, 42);  // target

    // The three string forms `SubAgentTool::resolveTargetAgentId()` accepts,
    // in the order it tries them. The parenthesised form must win over the
    // bare-hash form, and "Agent #4" only parses under the first pattern —
    // the reason the order is load-bearing.
    $now = time();
    seedSubAgentCall(3000, 300, 11, 'Research Agent (#4)', $now - 60);
    seedSubAgentCall(3010, 301, 11, '#4', $now - 30);
    seedSubAgentCall(3020, 302, 11, '4', $now - 10);
    // No id in the label → the row cannot be attributed to an edge and is
    // skipped rather than attributed to agent 0.
    seedSubAgentCall(3030, 303, 11, 'Research Agent', $now - 5);

    $service = makeService(mockToolConfig([
        11 => [4],
    ]));

    $payload = $service->buildGraph(42, 1);

    expect($payload['edges'])->toHaveCount(1)
        ->and($payload['edges'][0]['id'])->toBe('11->4')
        ->and($payload['edges'][0]['count_24h'])->toBe(3)
        ->and($payload['edges'][0]['last_invoked_at'])->toBe(atomTimestamp($now - 10));
});

it('never counts a handover call, which closes the source chat instead of delegating', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(11, 42); // source
    seedAgent(4, 42);  // target

    // `SubAgentTool` declares `handover` as a second operation on the same
    // `sub_agent` tool, so `tool_name` alone cannot tell the two apart. A
    // handover hands the task over and ends the source chat — a materially
    // different relationship, not a delegation that waited for a result.
    seedSubAgentCall(3000, 300, 11, 4, time() - 60, operation: 'handover');

    // Nothing configured, so the handover must not conjure an edge…
    expect(makeService()->buildGraph(42, 1)['edges'])->toBe([]);

    // …and with the pair configured, it must not inflate the aggregate.
    $edge = makeService(mockToolConfig([11 => [4]]))->buildGraph(42, 1)['edges'][0];

    expect($edge['configured'])->toBeTrue()
        ->and($edge['count_24h'])->toBe(0)
        ->and($edge['last_invoked_at'])->toBeNull();
});

it('counts only calls that actually executed, not proposals or refusals', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(11, 42); // source
    seedAgent(4, 42);  // target

    // A row exists for every proposed call regardless of what happened to
    // it, so without the status filter the count labels edges that never
    // fired a delegation.
    $now = time();
    seedSubAgentCall(4000, 400, 11, 4, $now - 60, status: 'PENDING_APPROVAL', executed: false);
    seedSubAgentCall(4010, 401, 11, 4, $now - 50, status: 'REJECTED', executed: false);
    seedSubAgentCall(4020, 402, 11, 4, $now - 40, status: 'DISABLED', executed: false);
    // The one call that did run, so the count assertion is not vacuous.
    seedSubAgentCall(4030, 403, 11, 4, $now - 30);

    $edge = makeService(mockToolConfig([11 => [4]]))->buildGraph(42, 1)['edges'][0];

    expect($edge['count_24h'])->toBe(1)
        ->and($edge['last_invoked_at'])->toBe(atomTimestamp($now - 30));
});

it('reads the allowlist with the runtime\'s own call shape — a user id and no PrincipalContext', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(11, 42); // source
    seedAgent(4, 42);  // target

    $toolConfig = M::mock(ToolConfigServiceInterface::class);
    $toolConfig->shouldReceive('getEffectiveSettings')
        ->andReturnUsing(static function (...$args): array {
            // `SubAgentTool::isTargetOnAllowlist()` passes exactly these
            // three arguments. A fourth `PrincipalContext` would collapse
            // the group cascade to the principal being graphed, so the
            // canvas would show edges the tool refuses and hide edges it
            // permits.
            expect($args)->toHaveCount(3)
                ->and($args[0])->toBe(SubAgentTool::class)
                ->and($args[2])->toBe(1);
            return ['allowed_target_agents' => $args[1] === 11 ? [4] : []];
        });

    $principals = new PrincipalService(new PrincipalResolver());
    $payload    = (new TeamGraphService(
        new NodeResolver(),
        new EdgeResolver($toolConfig, $principals),
        $principals,
    ))->buildGraph(42, 1);

    // Non-vacuous: the edge that call shape produced is still rendered.
    expect($payload['edges'])->toHaveCount(1)
        ->and($payload['edges'][0]['id'])->toBe('11->4');
});

it('refuses to resolve edges for a principal the caller cannot see', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedUser(99, 'foreign@example.com');
    seedPrincipal(99, 99);
    seedAgent(11, 42);
    seedAgent(4, 42);

    // `EdgeResolver` is autowired into the host container, so any plugin
    // can reach it through `\DI\get()` and bypass the service gate. The
    // resolver therefore re-asserts the predicate on its own entry point.
    $resolver = new EdgeResolver(mockToolConfig([11 => [4]]), new PrincipalService(new PrincipalResolver()));

    expect(fn() => $resolver->resolveEdges(99, 1))
        ->toThrow(PrincipalNotAccessibleException::class);
});

it('resolves edges for a plain group member, not only owners and admins', function (): void {
    seedUser(1, 'member@example.com');
    seedPrincipal(1, 1);
    seedUser(2, 'owner@example.com');
    seedPrincipal(2, 2);
    seedUser(3, 'peer@example.com');
    seedPrincipal(3, 3);
    seedUser(4, 'outsider@example.com');
    seedPrincipal(4, 4);
    seedUser(5, 'admin@example.com');
    seedPrincipal(5, 5);
    $groupPrincipal = seedGroupPrincipal(42, 42, 2);
    seedMembership(42, 2, 'owner');
    seedMembership(42, 3, 'member');
    seedMembership(42, 5, 'admin');
    seedAgent(11, $groupPrincipal);
    seedAgent(4, $groupPrincipal);

    $service = makeService(mockToolConfig([11 => [4]]));

    // Every role that is a member of the group reads the same graph.
    foreach ([2, 3, 5] as $callerUserId) {
        $payload = $service->buildGraph(42, $callerUserId);
        expect($payload['edges'])->toHaveCount(1)
            ->and($payload['edges'][0]['id'])->toBe('11->4')
            ->and($payload['nodes'])->toHaveCount(2);
    }

    // User 4 is a registered user with a user-principal of their own, but
    // no membership in this group.
    expect(fn() => $service->buildGraph(42, 4))
        ->toThrow(PrincipalNotAccessibleException::class);
});

it('drops cross-principal configured targets (defence-in-depth)', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedUser(99, 'foreign@example.com');
    seedPrincipal(99, 99);
    seedAgent(11, 42);            // source in principal 42
    seedAgent(4, 99);             // target in foreign principal 99
    seedAgent(7, 42);             // target in same principal

    // The source agent's allowlist references a foreign agent (4) — a
    // stale override or drift after a principal transfer. The runtime
    // would refuse to fire it, so the graph must hide it too.
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
    // The full wire shape mirrors the host's `ProfilePictureService`
    // output so the canvas renders the same `Avatar.vue` shape the
    // dashboard does. Pinning the strings catches Palette renames.
    expect($payload['nodes'][0]['profile_picture'])->toBe([
        'kind'             => 'avatar',
        // No archetype on the row — the host's default, never a null.
        'archetype'        => 'assistant',
        // Derived, never null — `fnv1a(10) % 3`.
        'variant_key'      => 'v0',
        'palette_key'      => 'indigo',
        'bg_color'         => '#4338CA',
        'fg_color'         => '#EEF2FF',
        'image_url'        => null,
        'image_updated_at' => null,
    ]);
    expect($payload['nodes'][1]['profile_picture'])->toBe([
        'kind'             => 'avatar',
        'archetype'        => 'assistant',
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

    // Mirroring the host's Slate default means the canvas never has a
    // node without a usable (bg, fg) pair. The host also defaults the
    // archetype and derives the variant, so `fnv1a(10) % 3` = v0.
    expect($payload['nodes'][0]['profile_picture'])->toBe([
        'kind'             => 'avatar',
        'archetype'        => 'assistant',
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
        'archetype'        => 'assistant',
        // Derived, never null — `fnv1a(10) % 3`.
        'variant_key'      => 'v0',
        'palette_key'      => 'slate',
        'bg_color'         => '#475569',
        'fg_color'         => '#F8FAFC',
        'image_url'        => null,
        'image_updated_at' => null,
    ]);
});

it('defaults a missing archetype to the host default instead of shipping null', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    // Both picture rows leave `archetype` as SQL NULL: an agent whose row
    // predates the column's backfill, and one an older release wrote.
    seedAgent(10, 42, paletteKey: 'indigo');
    seedAgent(11, 42, paletteKey: 'indigo', variantKey: 'v1');

    $payload = makeService()->buildGraph(42, 1);

    // The shared `Avatar` takes its archetype tile only when
    // `typeof archetype === 'string'`, so a null here would put these two
    // nodes back on initials while the dashboard showed glyphs — the same
    // failure `variant_key` had. `assistant` is
    // `AgentPictureService::DEFAULT_ARCHETYPE`.
    foreach ($payload['nodes'] as $node) {
        expect($node['profile_picture']['archetype'])->toBe('assistant');
    }
});

it('falls back to the default archetype when the stored one is not in the enum', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    // An archetype shipped by a newer host than this one knows about.
    seedAgent(10, 42, paletteKey: 'indigo', archetype: 'archivist');

    $payload = makeService()->buildGraph(42, 1);

    // Same drift tolerance the palette gets, and the same host default.
    expect($payload['nodes'][0]['profile_picture']['archetype'])->toBe('assistant');
});

it('keeps an operator-chosen archetype untouched', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(11, 42, paletteKey: 'violet', archetype: 'creative', variantKey: 'v2');

    $payload = makeService()->buildGraph(42, 1);

    expect($payload['nodes'][0]['profile_picture']['archetype'])->toBe('creative');
});

it('breaks a created_at tie on task id so the status is deterministic', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedAgent(10, 42);

    // `tasks.created_at` is second-precision, so two runs started in the
    // same second tie — and `LIMIT 1` then picks whichever row the engine
    // hands back first. The higher id is the newer run, so it wins.
    $createdAt = date('Y-m-d H:i:s');
    foreach ([[300, 'RUNNING'], [301, 'PENDING_APPROVAL']] as [$taskId, $status]) {
        Capsule::table('tasks')->insert([
            'id'           => $taskId,
            'agent_id'     => 10,
            'principal_id' => 42,
            'status'       => $status,
            'user_prompt'  => 'hi',
            'created_at'   => $createdAt,
            'updated_at'   => $createdAt,
        ]);
    }

    $node = makeService()->buildGraph(42, 1)['nodes'][0];

    expect($node['status'])->toBe('PENDING_APPROVAL')
        // The count is not a tie-break away from the truth: both rows are
        // in flight, so the node is carrying two.
        ->and($node['active_chats'])->toBe(2);
});

it('derives a missing variant_key instead of shipping null on the avatar branch', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    // The reported shape: an archetype is configured, a variant is not,
    // so `agent_pictures.variant_key` is SQL NULL for both.
    seedAgent(9, 42, paletteKey: 'teal', archetype: 'analyst');
    seedAgent(10, 42, paletteKey: 'orange', archetype: 'writer');

    $payload = makeService()->buildGraph(42, 1);

    foreach ($payload['nodes'] as $node) {
        $picture = $node['profile_picture'];
        expect($picture['kind'])->toBe('avatar');
        expect($picture['archetype'])->not->toBeNull();
        // A null here fails the shared `Avatar`'s
        // `typeof variant_key === 'string'` guard, dropping the card to
        // initials while the dashboard showed the glyph.
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

it('throws PrincipalNotAccessibleException when the principal is not visible to the caller', function (): void {
    seedUser(1, 'o@example.com');
    seedPrincipal(42, 1);
    seedUser(99, 'foreign@example.com');
    seedPrincipal(99, 99);

    expect(fn() => makeService()->buildGraph(99, 1))
        ->toThrow(PrincipalNotAccessibleException::class);
});

it('reads a group graph for a member-role caller, and still refuses a non-member', function (): void {
    seedUser(1, 'member@example.com');
    seedPrincipal(1, 1);
    seedUser(2, 'owner@example.com');
    seedPrincipal(2, 2);
    seedUser(3, 'outsider@example.com');
    seedPrincipal(3, 3);
    $groupPrincipal = seedGroupPrincipal(42, 42, 2, 'Design Team');
    seedMembership(42, 2, 'owner');
    seedMembership(42, 1, 'member');
    seedAgent(11, $groupPrincipal);

    $service = makeService();

    $payload = $service->buildGraph(42, 1);

    expect($payload['principal']['id'])->toBe(42)
        ->and($payload['principal']['type'])->toBe('group')
        ->and($payload['principal']['name'])->toBe('Design Team')
        ->and($payload['principal']['is_current_user_owned'])->toBeFalse()
        ->and($payload['nodes'])->toHaveCount(1);

    // The member is not in the group and must stay locked out — the fix is
    // membership, not "any authenticated caller".
    expect(fn() => $service->buildGraph(42, 3))
        ->toThrow(PrincipalNotAccessibleException::class);
});
