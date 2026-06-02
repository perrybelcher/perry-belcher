# Lodestar Directory

> Working codename — swap for final brand before launch.

The WordPress directory engine built for the AI-search era: fast at 100k+
listings, **citable by default** (JSON-LD + programmatic hub pages + FAQ), and
with **monetization in core** — not held hostage by an extension store.

## Why it's different

Incumbent directory plugins die at scale because they store listing data in
`wp_postmeta`, where faceted search becomes brutal self-joins. Lodestar uses a
**hybrid storage model**: a real Custom Post Type for routing/permalinks/SEO,
but all field and search data live in **custom indexed tables**. Faceted search
never touches `meta_query`.

See [`CLAUDE.md`](./CLAUDE.md) for the architecture, conventions, and module map.

## Requirements

- PHP **8.1+**
- WordPress **6.4+**
- MySQL 8 / MariaDB 10.6+
- Node **18+** (only for building blocks)

## Development setup

```bash
composer install      # PHP deps + autoloader + phpcs/phpunit
npm install           # block build tooling (@wordpress/scripts)
npm run build         # compile blocks/admin assets to /build
```

> The plugin ships a runtime PSR-4 autoloader, so it activates on a stock
> install even before `composer install` has run.

### Quality gates

```bash
composer lint         # PHPCS, WordPress-Extra ruleset
composer test         # PHPUnit (requires the WP test suite installed)
```

## Build status

Built phase-by-phase, gated on each phase's Definition of Done.

| Phase | Scope                                   | Status |
|-------|-----------------------------------------|--------|
| 0     | Scaffold & infrastructure               | ✅ done |
| 1     | Data layer (50k / <150ms facet query)   | ✅ done |
| 2     | Directory types & field/form system     | ✅ done |
| 3     | Front-end submission & user dashboard    | ✅ done |
| 4     | Search, faceting & maps                  | ✅ done |
| 5     | AEO/GEO engine (the differentiator)      | ✅ done |
| 6     | Monetization                             | ✅ done |
| 7     | AI intake & citability scoring           | ✅ done |
| 8     | API surface (headless-ready)             | ⏳ next |
| 9     | Blocks, templates & polish               | ☐      |

The Phase 1 faceted-search bet is validated by `tests/benchmark/` — the real
`QueryBuilder` output runs index-driven (bounding-box prefilter + indexed facet
lookups), not via `wp_postmeta` self-joins.

## License

GPL-2.0-or-later.
