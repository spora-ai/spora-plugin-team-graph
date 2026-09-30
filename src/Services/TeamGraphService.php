<?php

declare(strict_types=1);

namespace Spora\Plugins\TeamGraph\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Principal;
use Spora\Services\Exceptions\PrincipalNotAccessibleException;
use Spora\Services\PrincipalService;

/**
 * Glue between the controller, the two resolvers, and the
 * {@see PrincipalService} access gate.
 *
 * The principal check lives here rather than in the controller so the gate
 * is exercised by every path that can build a graph.
 */
final class TeamGraphService
{
    public function __construct(
        private readonly NodeResolver $nodes,
        private readonly EdgeResolver $edges,
        private readonly PrincipalService $principals,
    ) {}

    /**
     * @throws PrincipalNotAccessibleException When the caller doesn't control the principal.
     *
     * @return array{
     *     principal: array{id: int, type: string, name: string, is_current_user_owned: bool},
     *     nodes: list<array<string, mixed>>,
     *     edges: list<array<string, mixed>>,
     *     generated_at: string,
     * }
     */
    public function buildGraph(int $principalId, int $callerUserId): array
    {
        if (!$this->principals->callerControlsPrincipal($callerUserId, $principalId)) {
            throw new PrincipalNotAccessibleException(
                "Caller {$callerUserId} does not control principal {$principalId}.",
            );
        }

        $principal = Principal::query()->find($principalId);
        $principalRow = [
            'id'                     => $principalId,
            'type'                   => $principal !== null ? (string) $principal->type : '',
            'name'                   => $this->principalName($principal),
            'is_current_user_owned'  => $principal !== null
                && $principal->type === Principal::TYPE_USER
                && (int) $principal->user_id === $callerUserId,
        ];

        return [
            'principal'    => $principalRow,
            'nodes'        => $this->nodes->resolveNodes($principalId),
            'edges'        => $this->edges->resolveEdges($principalId, $callerUserId),
            'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];
    }

    private function principalName(?Principal $principal): string
    {
        if ($principal === null) {
            return '';
        }
        if ($principal->type === Principal::TYPE_GROUP && $principal->group_id !== null) {
            $name = Capsule::table('groups')
                ->where('id', $principal->group_id)
                ->value('name');
            return is_string($name) && $name !== '' ? $name : '';
        }
        $userRow = Capsule::table('users')
            ->where('id', $principal->user_id)
            ->select(['username', 'email'])
            ->first();
        if ($userRow === null) {
            return '';
        }
        $username = is_string($userRow->username ?? null) ? $userRow->username : '';
        if ($username !== '') {
            return $username;
        }
        return is_string($userRow->email ?? null) ? $userRow->email : '';
    }
}
