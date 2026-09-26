# spora-plugin-team-graph

A per-principal directed graph of agent spawning relationships for
[Spora](https://github.com/spora-ai/spora-core). The endpoint
`GET /api/v1/plugins/team-graph/graph?principal_id=<id>` returns the
principal's agents (nodes) and the recent `sub_agent` invocations
between them (edges); the admin SPA renders the response as a
Mermaid 10 flowchart at `/apps/team-graph`.

Read-only in v1 — no LLM-callable tools, no migrations, no agent
templates.

## Install

```sh
composer require spora-ai/spora-plugin-team-graph
```

The plugin depends on its companion frontend bundle
(`spora-ai/spora-plugin-team-graph-frontend`) which the operator
app's `composer install` pulls in automatically via
`spora-installer`.

## Open it

After `composer install` (which triggers the installer's first-run
migration) and the regular `bin/spora spora:install` step, the
`Team Graph` entry appears under the admin panel's sidebar. It
serves from `/apps/team-graph`.

## Architecture

* `src/TeamGraphPlugin.php` — entry point; wires DI bindings
  (`TeamGraphService`, `NodeResolver`, `EdgeResolver`,
  `TeamGraphController`) via `ContainerBuildingEvent` and registers
  the single GET route via `RoutesRegisteringEvent`.
* `src/TeamGraphApp.php` — admin-panel metadata
  (`VueAppInterface`).
* `src/Http/TeamGraphController.php` — `GET …/graph?principal_id=…`
  → `data` envelope.
* `src/Services/TeamGraphService.php` — principal gate + envelope
  assembly.
* `src/Services/NodeResolver.php` — agent aggregation in one SQL
  pass (active chats, 24h recent, latest in-flight status).
* `src/Services/EdgeResolver.php` — `sub_agent` `tool_calls` →
  deduped `(parent, target)` edges with the live `count_24h` /
  `last_invoked_at`.

## Tests

```sh
composer test:parallel
```

The suite covers:

* `TeamGraphServiceTest` — 5 unit tests with mocked resolvers.
* `TeamGraphControllerTest` — 4 feature tests against an in-memory
  SQLite (auth gate, principal control, validation).
* `TeamGraphPluginTest` — 6 wiring tests (DI bindings, route
  registration, CSRF + Auth middleware attachment).

## Plan

See `spora-workspace/plans/spora-plugin-team-graph.md` for the full
design context, including the prototype set and the risk register.
