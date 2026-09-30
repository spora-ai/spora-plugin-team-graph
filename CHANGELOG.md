# Changelog

All notable changes to `spora-plugin-team-graph` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Nothing yet.

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

[Unreleased]: https://github.com/spora-ai/spora-plugin-team-graph/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/spora-ai/spora-plugin-team-graph/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/spora-ai/spora-plugin-team-graph/releases/tag/v0.1.0
