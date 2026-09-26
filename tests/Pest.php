<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest Bootstrap
|--------------------------------------------------------------------------
|
| Plugin-local test helpers and global Pest hooks. Mirrors the
| `spora-plugin-memories` / `spora-plugin-typst` pattern: define
| BASE_PATH, hand-roll a `uses(...)` block that installs the full core
| migration set into a per-process in-memory SQLite and rolls back
| each test in afterEach for isolation.
|
*/

use Delight\Auth\Auth as DelightAuth;
use Illuminate\Database\Capsule\Manager as Capsule;
use Mockery as M;
use Spora\Auth\AuthService;
use Spora\Core\Database;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/vendor/autoload.php';

// Suppress E_DEPRECATED originating from delight-im vendor packages.
set_error_handler(static function (...$handlerArgs): bool {
    [$errno, , $errfile] = $handlerArgs;

    if ($errno === E_DEPRECATED && str_contains($errfile, \DIRECTORY_SEPARATOR . 'delight-im' . \DIRECTORY_SEPARATOR)) {
        return true;
    }

    return false;
}, E_DEPRECATED);

/**
 * Boot a fresh in-memory SQLite database and return a ready-to-use
 * AuthService. Throttling is disabled so tests never hit rate limits.
 */
function bootAuthLayer(): AuthService
{
    $pdo  = Capsule::connection()->getPdo();
    $auth = new DelightAuth($pdo, null, null, false /* throttling off */);

    return new AuthService($auth);
}

/**
 * Create a JSON Request with an optional body array.
 */
function jsonRequest(string $method, string $uri, array $body = []): Symfony\Component\HttpFoundation\Request
{
    $content = $body !== [] ? json_encode($body) : '';

    return Symfony\Component\HttpFoundation\Request::create(
        $uri,
        strtoupper($method),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $content,
    );
}

/**
 * Simulate a logged-in session by populating the PHP session
 * superglobal the same way delight-im/auth does internally.
 */
function simulateLoggedInSession(int $userId, string $email): void
{
    if (!isset($_SESSION)) {
        $_SESSION = [];
    }
    $_SESSION[DelightAuth::SESSION_FIELD_LOGGED_IN] = true;
    $_SESSION[DelightAuth::SESSION_FIELD_USER_ID]   = $userId;
    $_SESSION[DelightAuth::SESSION_FIELD_EMAIL]     = $email;
    $_SESSION[DelightAuth::SESSION_FIELD_USERNAME]  = null;
}

function clearSession(): void
{
    $_SESSION = [];
}

/**
 * Register a new user and simulate their session. Returns the user id.
 */
function bootAuth(AuthService $authService, string $email = 'test@example.com', string $password = 'Password1!', string $displayName = 'Test User'): int
{
    $userId = $authService->register($email, $password, $displayName);
    simulateLoggedInSession($userId, $email);

    return $userId;
}

/**
 * Materialise a user-principal row for the given user id via
 * PrincipalService::ensureUserPrincipal and return the principal id.
 *
 * Idempotent: returns the existing principal id when one already
 * exists for this user.
 */
function createUserPrincipal(int $userId): int
{
    $service = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());

    return (int) $service->ensureUserPrincipal($userId)->id;
}

uses()
    ->beforeEach(function () {
        Database::resetBootState();
        $db = new Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
        $db->boot();

        Capsule::connection()->beginTransaction();
    })
    ->afterEach(function () {
        if (Capsule::connection()->transactionLevel() > 0) {
            Capsule::connection()->rollBack();
        }
        Database::resetBootState();
        clearSession();
        M::close();
    })
    ->in(__DIR__);
