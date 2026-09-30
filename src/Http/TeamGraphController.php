<?php

declare(strict_types=1);

namespace Spora\Plugins\TeamGraph\Http;

use Spora\Auth\AuthService;
use Spora\Plugins\TeamGraph\Services\TeamGraphService;
use Spora\Services\Exceptions\PrincipalNotAccessibleException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only endpoint backing the Team Graph admin panel.
 *
 *   GET /api/v1/plugins/team-graph/graph?principal_id=<id>
 *
 * The principal-id gate is enforced in
 * {@see TeamGraphService::buildGraph()} via
 * `PrincipalService::callerControlsPrincipal()`; this controller surfaces
 * the 403 envelope when the service refuses. `CsrfMiddleware` is a no-op on
 * GET, so the chain effectively enforces "logged-in caller" only.
 */
final class TeamGraphController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TeamGraphService $service,
    ) {}

    public function graph(Request $request): JsonResponse // NOSONAR php:S1142 — the returns are guard clauses: unauthenticated / invalid input / refused, each returning its own status+body, and collapsing them would nest the happy path under two error branches to save one `return`
    {
        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            return new JsonResponse(
                ['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.']],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $principalId = (int) $request->query->get('principal_id', '0');
        if ($principalId <= 0) {
            return new JsonResponse(
                ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'principal_id is required.']],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $payload = $this->service->buildGraph($principalId, $userId);
        } catch (PrincipalNotAccessibleException $e) {
            return new JsonResponse(
                ['error' => ['code' => 'FORBIDDEN', 'message' => $e->getMessage()]],
                Response::HTTP_FORBIDDEN,
            );
        }

        return new JsonResponse(['data' => $payload], Response::HTTP_OK);
    }
}
