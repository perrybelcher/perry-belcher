# CLAUDE.md — Lodestar Directory

> **Anti-amnesia file.** Read this at the start of every session before touching
> code. It restates the non-negotiable architecture (SDD §1), the conventions
> (SDD §2), and the module map so the build stays on rails across sessions.
> The full spec lives in the Software Design Document (SDD); this is the
> always-loaded summary.

## What we are building

Lodestar is a WordPress directory engine for the AI-search era: fast at
**100k+ listings**, **citable by default** (JSON-LD + programmatic hub pages +
FAQ), with **monetization in core**. The whole architecture defends one thesis:
**incumbent directory plugins die at scale because they store listing data in
`wp_postmeta`, where faceted search becomes brutal self-joins.** We avoid that
trap while keeping WordPress's routing/SEO machinery.

## Core architectural decisions (NON-NEGOTIABLE — do not re-litigate)

1. **Hybrid storage.** Register a real CPT (`ld_listing`) for
   routing/permalinks/sitemaps/templates, but store all field & search data in
   **custom indexed tables**, joined by `post_id`. The post row stays minimal
   (title, slug, status, author). **No facetable data ever goes in
   `wp_postmeta`.**
2. **Custom tables are the source of truth for search.** All filtering,
   sorting, and faceting hits indexed custom tables — **never `meta_query`.**
3. **AEO/GEO is core, not an add-on.** Automatic JSON-LD per listing,
   programmatic hub/category/location pages, FAQ blocks, and an AI-citability
   score ship in core.
4. **Update-safe + theme-agnostic rendering.** All front-end output goes through
   a template-loader with override hooks. An update must never flatten user
   layout work. Scoped CSS, no global resets — never fight the active theme.
5. **Provider-agnostic AI layer, server-side only.** `AI_PROVIDER=auto` with
   Anthropic adapter primary, Gemini fallback. Keys live server-side in
   config/constants, **never reach the client**.
6. **API-first.** Every read/write capability is exposed via REST (core) so a
   headless Next.js front end, mobile app, or agent can drive the directory.
   GraphQL is an optional Phase 8 layer.
7. **Monetization in core.** Featured listings, paid submission, subscription
   plans, and claim-listing are first-class. The wedge is "everything
   Directorist sells as five extensions, included."

## Tech stack

- **PHP 8.1+** — typed properties, enums, `readonly` where sensible,
  `declare(strict_types=1)` in every file.
- **WordPress 6.4+**, MySQL 8 / MariaDB 10.6+.
- **Node 18+** for block build tooling.
- Composer **PSR-4 autoload**: `Lodestar\` → `/src`.
- `@wordpress/scripts` for Gutenberg blocks & admin React → builds to `/build`.
- PHPUnit + WP test suite for the data layer. PHPCS with `WordPress-Extra`.

## Hard conventions (ENFORCE)

- OOP, namespaced, **no global functions except the single bootstrap** in
  `lodestar-directory.php`. **No business logic in template files.**
- **Security is mandatory on every front-end write path** (public submission =
  attack surface): capability checks, nonce verification, `sanitize_*` on input,
  `esc_*` on output, **prepared statements for every custom query**
  (`$wpdb->prepare`).
- **Schema changes only through versioned migrations** run via `dbDelta` in
  `MigrationRunner`. **Never raw `CREATE TABLE` ad hoc.**
- All user-facing strings wrapped in i18n (`__()`, `_e()`), text domain
  `lodestar`.
- **No direct `$_POST`/`$_GET` access** outside a single request-sanitizing
  layer.
- DB table prefix: `{$wpdb->prefix}lodestar_`. PHP namespace `Lodestar\`. Text
  slug `lodestar`.

## Performance contract (the whole bet)

- **Searchable/facetable fields → `lodestar_field_index`** (EAV, but indexed for
  the access pattern: always filter by `field_key` first). The composite index
  order `(field_key, value_text)` / `(field_key, value_num)` is what makes facet
  queries fast — **do not reorder.**
- **Non-facetable extras** (rich text, gallery captions) → a single `meta` JSON
  column on `lodestar_listing_data`. Never indexed, never searched.
- Phase 1 DoD: a 50,000-listing dataset returns a 3-facet + radius filtered,
  sorted, paginated query in **<150ms**. Do not advance past Phase 1 until this
  passes.

## Module map

```
lodestar-directory.php   bootstrap, header, autoload, lifecycle hooks (only globals live here)
CLAUDE.md                this file
composer.json            PSR-4 Lodestar\ -> src/, dev tooling (phpunit, phpcs/wpcs)
package.json             @wordpress/scripts block/admin build
uninstall.php            destructive teardown (drops tables, deletes options)
src/
  Plugin.php             container/bootstrap, hook registration
  Install/
    Activator.php        env guard, run migrations, schedule cron, flush rewrites
    Deactivator.php      clear cron, flush rewrites (never drops data)
    MigrationRunner.php   versioned dbDelta migrations, reads lodestar_migrations
  Data/                  ListingRepository, FieldIndexer, QueryBuilder, SearchQuery
  PostType/             ListingPostType, Taxonomies
  DirectoryType/        DirectoryType + FieldDefinition DTOs, DirectoryTypeManager, FieldManager, FormBuilder
  Support/              Request (single $_POST/$_GET sanitiser), Settings (config flags)
  Frontend/             SubmissionController, DashboardController, SearchController, SearchRequest, TemplateLoader, FieldSanitizer, ListingFormData, MapRenderer, Assets
  Geo/                  GeoPoint, GeocoderProvider, Geocoder, RadiusQuery, Provider/{Abstract,Nominatim,Google,Mapbox}
  Aeo/                  SchemaGenerator (JSON-LD), FaqBlock, ProgrammaticPages (hub pages), SitemapProvider
  Monetize/             Plan, PlanManager, OrderManager, FeaturedManager, ClaimManager, WebhookController, Gateways/{GatewayInterface,Stripe,Paypal,Woo,GatewayManager,CheckoutSession,WebhookEvent}
  Ai/                   AiClient (auto router), ProviderResponse, AiLog, Adapters/{ProviderInterface,Abstract,Anthropic,Gemini}, ListingEnricher, CitabilityScorer, IntakeController
  Api/                  RestController (/wp-json/lodestar/v1/*), Schemas (+ OpenAPI), RateLimiter
  Data/ (Phase 8)       ReviewRepository (pending reviews + rating recompute)
  Admin/                AdminMenu, DirectoryTypeAdmin (Settings, ListingsTable to come)
blocks/                  Gutenberg source (search-form, listings-grid, single-listing, map, submit-form)
templates/               default, override-able markup (submit-form, dashboard, search, …)
assets/                  plain (un-built) scoped css/js — lodestar.css, lodestar-map.js
build/                   compiled assets (gitignored source)
tests/                   PHPUnit + WP test suite
```

## Build phases (DoD-gated — do not advance until the DoD passes)

- **Phase 0 — Scaffold & infrastructure.** ✅ *(current)* Bootstrap, autoload,
  `@wordpress/scripts` config, this file, Activator/Deactivator, MigrationRunner.
  **DoD:** Activates cleanly on a stock install, no notices/warnings with
  `WP_DEBUG` on; deactivation leaves no orphaned cron; migration runner applies
  an empty baseline and records the version.
- **Phase 1 — Data layer.** ✅ *(current)* CPT (`ld_listing`) + taxonomies, all
  §3 tables (migration `1.1.0`), `ListingRepository`, `FieldIndexer`,
  `SearchQuery`/`Facet`/`GeoFilter`/`SearchResult` DTOs, `QueryBuilder`,
  `Geohash`. Facet search uses indexed EXISTS subqueries + a lat/lng
  bounding-box prefilter + haversine refine — never `meta_query`. Taxonomy terms
  are denormalised into `field_index` (`ld_category`/`ld_location`/`ld_tag`,
  term ID in `value_num`) so they facet like any field. **DoD: 50k listings,
  <150ms 3-facet + radius query** — see `tests/benchmark/` (run the `mysql`
  driver for the authoritative gate; the SQLite proxy proves the access pattern
  is index-driven).
- **Phase 2 — Directory types & field/form system.** ✅ *(current)*
  `DirectoryType`/`FieldDefinition` DTOs, `DirectoryTypeManager` +
  `FieldManager` (CRUD on `directory_types`/`fields`, per-type field
  isolation), `FormBuilder` (dynamic, escaped, theme-scoped markup),
  `Admin\AdminMenu` + `Admin\DirectoryTypeAdmin` (secure CRUD UI), and
  `Support\Request` (the single superglobal-sanitising layer). `FieldManager::
  indexDefinitions()` feeds `FieldIndexer` so facetable fields flow into search;
  richtext/file/geo are never indexed. **DoD met:** admin defines a type, adds
  facetable + non-facetable fields, the form renders dynamically, and two types
  coexist with isolated fields.
- **Phase 3 — Front-end submission & user dashboard.** ✅ *(current)*
  `Frontend\SubmissionController` (`[lodestar_submit]`, add/edit via
  admin-post.php), `Frontend\DashboardController` (`[lodestar_dashboard]`,
  manage own listings + delete), `Frontend\TemplateLoader` (theme-override
  aware: child/parent theme `lodestar/` → plugin `templates/`). Security-
  critical input handling is factored into pure, tested units:
  `Frontend\FieldSanitizer` (per-type sanitisation, option whitelisting, geo
  bounds, XSS strip) and `Frontend\ListingFormData` (validation + facetable/
  meta/geo split). `Support\Settings` holds the config flags (guest submissions
  off, default status pending). **Every write path:** nonce + capability/
  ownership + sanitise; status is forced server-side (no self-publish); SQLi
  neutralised by prepared statements, XSS by sanitise-in + escape-out.
- **Phase 4 — Search, faceting & maps.** ✅ *(current)*
  `Frontend\SearchController` (`[lodestar_search]`, GET-driven faceted search +
  pagination + sort, live against the custom tables via the Phase 1
  QueryBuilder), `Frontend\SearchRequest` (pure args→SearchQuery mapper — only
  facetable fields can filter), `Geo\Geocoder` (provider-agnostic: Nominatim
  default / Google / Mapbox behind `Geo\Provider\*`, server-side keys, cached),
  `Geo\RadiusQuery` (haversine + geohash covering prefixes), `Frontend\
  MapRenderer` + `Frontend\Assets` (Leaflet + markercluster from CDN, enqueued
  only where shortcodes render; plain JS in `assets/`, no build step). **DoD:**
  faceted UI filters live; radius results ordered by distance; markers cluster.
  The Gutenberg *map block* wrapper is deferred to Phase 9 (Blocks); the map
  ships functionally now via the search template.
- **Phase 5 — AEO/GEO engine** (the differentiator). ✅ *(current)*
  `Aeo\SchemaGenerator` (per-listing JSON-LD on `wp_head`; @type +
  field→property map are **configurable per directory type**, not hardcoded;
  `build()` is pure; `render()` uses `JSON_HEX_TAG` so data can't break out of
  the script tag), `Aeo\FaqBlock` (FAQPage JSON-LD + accessible `<details>`
  render from listing `meta['faq']`), `Aeo\ProgrammaticPages` (category/location
  term archives enriched into hubs: auto intro, comparison table, BreadcrumbList
  + CollectionPage JSON-LD), `Aeo\SitemapProvider` (dedicated chunked hub
  sitemap atop WP core + pure `chunk()` helper). Pure builders are unit-tested;
  WP routing/output is thin glue.
- **Phase 6 — Monetization.** ✅ *(current)* `Monetize\Plan`/`PlanManager`
  (plans CRUD + `within_limit` submission gating), `Monetize\OrderManager`
  (orders + allowlisted status machine), `Monetize\FeaturedManager`
  (`feature`/`unfeature` + daily cron `sweep` that un-features past
  `featured_until` and drafts past `expires_at`; pure `is_active`/`is_expired`),
  `Monetize\ClaimManager` (claim → approve transfers post ownership +
  `claim_status`; pure `can_transition`), and gateways behind
  `Gateways\GatewayInterface` (`StripeGateway` with tested HMAC
  `verify_signature` + `map_status` + `to_minor_units`; `PaypalGateway`
  refuses unverified webhooks; `WooGateway` bridge; `GatewayManager` registry).
  `Monetize\WebhookController` (admin-post endpoint) verifies, updates the order,
  and fulfils (attach plan + feature) only on a verified `paid` event. Pure
  security/state logic is unit-tested; cron sweep reuses the Activator cron.
- **Phase 7 — AI intake & citability scoring.** ✅ *(current)* (Never
  auto-publish AI output.) `Ai\AiClient` (`AI_PROVIDER=auto` router: ordered
  providers, fail over on error, every attempt logged to `lodestar_ai_log`),
  `Ai\Adapters\*` (`ProviderInterface` + `AbstractAdapter` with injectable
  poster; `AnthropicAdapter` primary / `GeminiAdapter` fallback; keys server-side
  only), `Ai\ListingEnricher` (URL/blurb → *proposed* title/description/fields/
  faq; pure prompt + parse; parsed fields **whitelisted to the type's known
  keys**; returns a draft for human review, never saves), `Ai\CitabilityScorer`
  (pure deterministic 0–100 rubric — description/fields/FAQ/title/geo/rating/
  terms/freshness — with actionable gaps), `Ai\IntakeController` (login+nonce
  AJAX enrich endpoint returning only the proposal; recomputes + persists
  `ai_citability_score` on save). Front-end: `assets/js/lodestar-intake.js`
  pre-fills the form from the proposal (human-in-the-loop). Pure router/parse/
  score logic is unit-tested.
- **Phase 8 — API surface** (headless-ready). ✅ *(current)*
  `Api\RestController` (`/wp-json/lodestar/v1/*`: GET/POST `listings`,
  GET/PUT/DELETE `listings/{id}`, POST `listings/{id}/claim`,
  POST `listings/{id}/reviews`, GET `directory-types`, GET `openapi`). Reads are
  public; writes require auth + are rate-limited; input reuses the SAME
  `FieldSanitizer`/`ListingFormData`/`SearchRequest` path as the forms (one
  trusted way into the DB — no second sanitiser to drift). `Api\Schemas` (pure
  arg defs + public/owner response shaping + OpenAPI 3 doc), `Api\RateLimiter`
  (fixed-window, injected store+clock, transient-backed in prod),
  `Data\ReviewRepository` (pending reviews + rating recompute). Pure
  schema/limiter logic + the `ListingFormData::map_fields` split are unit-tested.
- **Phase 9 — Blocks, templates & theme-agnostic polish.**

## Open config flags (decided as settings, defaults noted)

- Guest submissions → default **login-required**.
- Payments → `LODESTAR_PAYMENTS=native|woo`, default **native** (Stripe).
- Map provider → default **Leaflet/OSM** (no API-key friction).
- AI provider → `LODESTAR_AI_PROVIDER=auto|anthropic|gemini`, default **auto**.
- AI keys: `LODESTAR_ANTHROPIC_KEY`, `LODESTAR_GEMINI_KEY` (wp-config/env,
  server-side only).

## Working agreements

- Build **phase-by-phase**, gating on each DoD. Do not start a phase until the
  previous DoD passes.
- Run `php -l` on changed files; run `composer lint` and `composer test` before
  considering a phase done.
- Keep this file and the migration set in sync with reality as the build grows.
