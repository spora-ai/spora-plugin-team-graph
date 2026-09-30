# spora-plugin-team-graph

A per-principal directed graph of agent spawning relationships for
[Spora](https://github.com/spora-ai/spora-core). The endpoint
`GET /api/v1/plugins/team-graph/graph?principal_id=<id>` returns the
principal's agents (nodes) and the `sub_agent` connections between them
(edges); the admin SPA renders the edges as a Mermaid 10 flowchart and
overlays the node cards as real DOM at `/apps/team-graph`.

Read-only in v1 — no LLM-callable tools, no migrations, no agent
templates.

## Install

```sh
composer require spora-ai/spora-plugin-team-graph
composer require spora-ai/spora-plugin-team-graph-frontend
```

Two packages, as for any plugin with an operator-facing panel. This PHP
package does **not** `require` the frontend half, so the app must require
`spora-ai/spora-plugin-team-graph-frontend` itself — the explicit command
above is a no-op if the dependency is ever pulled in transitively. The
frontend package (`type: spora-plugin-frontend`) is what the
`SporaPluginFrontendInstaller` drops into `public/plugins/team-graph/`;
without it the app entry appears but the panel 404s on the bundle.

## Open it

After `composer install` (which triggers the installer's first-run
migration) and the regular `bin/spora spora:install` step, the
`Team Graph` entry appears under the admin panel's sidebar. It
serves from `/apps/team-graph`.

## Who can open which graph

The endpoint is gated on **visibility, not control** — the same rule
`GET /api/v1/principals/me` applies, so the panel's own principal picker
can never offer a principal the endpoint then refuses. A caller may read:

* their own user-principal, and
* the group-principal of **every** group they belong to, at any role
  (`member` included) — a member can already list and open every agent in
  the group, so the graph shows them nothing they could not see anyway.

Anything else is a `403 FORBIDDEN`. Group `owner`/`admin` ("control") is
the tier the *write* paths use — agent transfer, group settings — and is
deliberately not required here.

## Architecture

* `src/TeamGraphPlugin.php` — entry point; wires DI bindings
  (`TeamGraphService`, `NodeResolver`, `EdgeResolver`,
  `TeamGraphController`, plus the `ToolConfigServiceInterface` alias PHP-DI
  cannot infer) via `ContainerBuildingEvent` and registers the single GET
  route via `RoutesRegisteringEvent`.
* `routes/team-graph.php` — the route path and its middleware
  (`AuthMiddleware`, `CsrfMiddleware`) in one place, loaded through
  Composer's `autoload.files` because it sits outside PSR-4's `src/`.
* `src/TeamGraphApp.php` — admin-panel metadata
  (`VueAppInterface`).
* `src/Http/TeamGraphController.php` — `GET …/graph?principal_id=…`
  → `data` envelope; 401 / 403 / 422 mapping.
* `src/Services/TeamGraphService.php` — principal visibility gate +
  envelope assembly.
* `src/Services/NodeResolver.php` — agent aggregation in one SQL
  pass (active chats, 24h recent, latest in-flight status, and the
  `AgentPictureService` wire shape resolved inline so there is no N+1
  against `agent_pictures` / `media_assets`).
* `src/Services/EdgeResolver.php` — one edge per `(source, target)` pair in
  each source agent's **configured** `allowed_target_agents` allowlist for
  `SubAgentTool`, read through the same
  `ToolConfigServiceInterface::getEffectiveSettings()` cascade the tool
  checks at runtime. So the graph shows connections that are configured but
  have never fired, and drops cross-principal targets the way
  `SubAgentTool::sharePrincipal()` would. `sub_agent` `tool_calls` are read
  only to enrich those edges with `count_24h` (24h window) and
  `last_invoked_at` (7d window) — never to add or remove one.

## Frontend contract

The host SPA loads the bundle from
`/plugins/team-graph/main.js` (`TeamGraphApp::entry()`), which must be the
frontend package's `build.lib.fileName()`. The bundle installs a
mount/unmount contract on `window.SporaAppTeamGraph`:

```ts
window.SporaAppTeamGraph = {
    mount: (target: HTMLElement, hostContext: PluginHostContext) => void | Promise<void>,
    unmount: (target: HTMLElement) => void,
}
```

`hostContext` carries the host's `api`, `pinia`, `theme`, `route` and
`router`; the plugin installs its own Pinia for plugin-local state and
branches the host client in through `src/api/client.ts`.

## Tests

```sh
composer test:parallel
```

The suite covers:

* `TeamGraphServiceTest` — drives the service, both resolvers and the
  envelope against the same in-memory SQLite the feature suite uses, with
  only `ToolConfigServiceInterface` mocked so each scenario can script its
  own allowlist. Areas: node aggregates and status, configured-vs-observed
  edge emission, `tool_calls` enrichment, cross-principal and archived
  filtering, `profile_picture` wire shape (palette fallback, `variant_key`
  derivation), and the principal-visibility refusal.
* `TeamGraphControllerTest` — feature tests against an in-memory SQLite
  (auth gate, principal visibility per group role, validation).
* `TeamGraphPluginTest` — wiring: event subscriptions, the DI bindings, the
  registered route with its middleware, and the app's `VueAppInterface`
  contract.

## Plan

See `spora-workspace/plans/spora-plugin-team-graph.md` for the full
design context, including the prototype set and the risk register.
