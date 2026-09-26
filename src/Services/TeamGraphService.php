<?php

declare(strict_types=1);

namespace Spora\Plugins\TeamGraph\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Spora\Services\Exceptions\PrincipalNotAccessibleException;
use Spora\Services\PrincipalService;

/**
 * Glue between the controller, the two resolvers, and the
 * {@see PrincipalService} access gate.
 *
 * The service-level `buildGraph()` is the single point that
 *   1. verifies the caller controls the requested principal
 *      (`PrincipalService::callerControlsPrincipal`),
 *   2. fetches the principal summary for the response envelope,
 *   3. folds nodes + edges from the resolvers into the payload.
 *
 * Splitting the read from the principal check keeps the controller
 * thin and the test surface narrow: a unit test against
 * {@see TeamGraphService} only needs to mock the resolvers +
 * {@see PrincipalService}; an integration test exercises the SQL.
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

        $principal = \Spora\Models\Principal::query()->find($principalId);
        $principalRow = [
            'id'                     => $principalId,
            'type'                   => $principal !== null ? (string) $principal->type : '',
            'name'                   => $this->principalName($principal),
            'is_current_user_owned'  => $principal !== null
                && $principal->type === \Spora\Models\Principal::TYPE_USER
                && (int) $principal->user_id === $callerUserId,
        ];

        return [
            'principal'    => $principalRow,
            'nodes'        => $this->nodes->resolveNodes($principalId),
            'edges'        => $this->edges->resolveEdges($principalId, $callerUserId),
            'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];
    }

    private function principalName(?\Spora\Models\Principal $principal): string
    {
        if ($principal === null) {
            return '';
        }
        if ($principal->type === \Spora\Models\Principal::TYPE_GROUP && $principal->group_id !== null) {
            $name = \Illuminate\Database\Capsule\Manager::table('groups')
                ->where('id', $principal->group_id)
                ->value('name');
            return is_string($name) && $name !== '' ? $name : '';
        }
        $userRow = \Illuminate\Database\Capsule\Manager::table('users')
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
