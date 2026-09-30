# Changelog

All notable changes to `spora-plugin-team-graph` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Nothing yet.

## [0.1.2] — 2026-09-30

### Fixed

- **Group members can now open a group's graph.** The endpoint gated on
  `PrincipalService::callerControlsPrincipal()`, which demands group
  `owner`/`admin`, so any user whose `group_memberships.role` was the
  default `member` got `403 FORBIDDEN` — *"Caller 1 does not control
  principal 16."* The gate is now
  `PrincipalService::visiblePrincipalIdsFor()`, the same membership rule
  `GET /api/v1/principals/me` and the agent list use, so the panel's own
  principal picker can no longer offer a principal the endpoint refuses.
  The `403` message is now *"cannot access"* rather than *"does not
  control"*, matching the rule it actually enforces.
- The same gate in `EdgeResolver` (the defence-in-depth check for callers
  that reach the autowired resolver through `\DI\get()`) moved to the
  membership rule, so a member reaching the resolver directly gets the
  same graph the endpoint would give them.

Unchanged: callers outside the group still get `403`, including global
admins who are not members — existence-hiding is the same rule
`GroupAuthorizationTrait::callerCanSeeGroup()` applies to every other
group read.

## [0.1.1] — 2026-09-30

### Fixed

- **The admin panel now ships with its frontend bundle.** The package
  requires `spora-ai/spora-plugin-team-graph-frontend`, so
  `composer require spora-ai/spora-plugin-team-graph` is the only step —
  the installer copies the bundle to `public/plugins/team-graph/` and
  `/apps/team-graph` renders. In `0.1.0` the package required only
  `spora-ai/spora-core`, so the app entry pointed at a directory nothing
  populated and the panel 404'd on `main.js`. Operators upgrading from
  `0.1.0` need `composer update`.

## [0.1.0] — 2026-09-30

### Added

- `GET /api/v1/plugins/team-graph/graph?principal_id=<id>` — the
  principal's agents as nodes, and each source agent's configured
  `allowed_target_agents` as edges, enriched with 24 h call counts and a
  7-day last-invoked watermark. Cross-principal targets are dropped.
- `TeamGraphApp` — the `/apps/team-graph` admin entry (Mermaid 10
  rendering, node cards as a Vue overlay).

[Unreleased]: https://github.com/spora-ai/spora-plugin-team-graph/compare/v0.1.2...HEAD
[0.1.2]: https://github.com/spora-ai/spora-plugin-team-graph/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/spora-ai/spora-plugin-team-graph/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/spora-ai/spora-plugin-team-graph/releases/tag/v0.1.0
