# Sightline SEO

Titles, descriptions, social cards, structured data, sitemaps and redirects for WordPress — with Product schema that is actually correct for a digital-download store, and a migrator out of Rank Math.

- **Version** 1.0.0 · **License** GPL-2.0-or-later
- **Requires** WordPress 6.0+, PHP 7.4+
- No Composer, no build step, no third-party dependencies (a hand-rolled autoloader maps `Sightline\SEO\…` to `includes/…`)

`readme.txt` is the wordpress.org-format readme. This file is for whoever works on the code.

---

## What it does

- **Titles, meta descriptions and canonicals** for posts, pages, custom post types, taxonomy terms, post type archives, author archives and the front page.
- **Open Graph and Twitter cards**, with `og:type` set to `product` on products rather than `article` on everything.
- **One JSON-LD `@graph` per page**: Organization, WebSite with a search action, WebPage / ItemPage / CollectionPage, BreadcrumbList, and Product on a store. Every configured social profile goes into `sameAs`.
- **XML sitemaps** at `/sitemap_index.xml` with per-type children (`/{type}-sitemap.xml`), excluding anything marked noindex.
- **Store defaults**: cart, checkout, account, confirmation, history and registration pages are `noindex, follow` and left out of the sitemap — found through the store's own page settings, never guessed from slugs.
- **Redirects**: exact, contains, starts-with, ends-with and regex matching; 301 / 302 / 307 / 410 / 451 responses; a hit counter so dead rules can be found later.
- **Rank Math migrator**: non-destructive, idempotent, batched, and it reports every row it skipped with the reason.

### Product schema that passes

On a site running Easy Digital Downloads, Sightline emits **one** Product entity per page with a real SKU, one Offer per price tier, `availability` on every offer, `brand`, `priceValidUntil`, and an `aggregateRating` from the store's own reviews. Where the theme or store plugin offers a way to switch off its own microdata, Sightline uses it, so there is never a second, partial Product competing with the JSON-LD.

EDD is detected, never required. Without it Sightline is an ordinary SEO plugin and emits no product markup at all. All EDD detection lives in `includes/Integrations/EDD.php`, so nothing else has to scatter `function_exists()` checks.

---

## Admin

**Sightline SEO** in the admin menu (requires `manage_options`):

| Screen | For |
|---|---|
| Settings | Site identity (organization, logo, title separator, front page title and description), social profiles and cards, per post type and taxonomy templates and indexing, which structured data to emit, sitemap, other archives, store pages |
| Redirects | The redirect table, with hit counts |
| Import | The Rank Math migrator and its report |
| Tools | Export and import of settings and redirects as JSON, the list of every key the plugin writes, and the delete-on-uninstall choice |

The JSON export is also how settings move between installs: deploys are files only, so settings entered on one site reach another as a file, or not at all.

Per-object fields appear in a meta box on posts (hide it per post type with `sightline_show_meta_box`), on term edit screens and on user profiles.

### Title templates

Templates use `%token%` — single percent signs, the only syntax that reaches storage. The Rank Math importer converts on the way in. Unknown tokens are removed rather than printed.

Available everywhere: `%sep%`, `%sitename%`, `%sitedesc%`, `%currentyear%`, `%currentmonth%`, `%currentdate%`, `%page%`, `%pagenumber%`, `%search_query%`, `%title%`, `%excerpt%`, `%id%`.
On posts: `%date%`, `%modified%`, `%name%` (author), `%parent_title%`, `%primary_category%`, `%pt_singular%`, `%pt_plural%`.
On terms: `%term%`, `%term_description%`, `%tax_singular%`, `%tax_plural%`.

Add your own with the `sightline_tokens` filter.

---

## Migrating from Rank Math

**Sightline SEO → Import.** Nothing Rank Math wrote is changed or deleted, running it twice changes nothing the second time, and both plugins can stay active while you check the result.

Redirects matter most. They live only in Rank Math's own table (`rank_math_redirections`), and deactivating Rank Math silently stops every one of them. Import before you deactivate.

---

## Integrations

- **Easy Digital Downloads** — Product schema, transactional-page defaults, and taking over EDD's own microdata. See above.
- **Meridian** (multilingual) — Meridian supplies per-language titles, descriptions and `inLanguage`, and filters `sightline_redirect_request_path` so one redirect rule covers every language prefix. Sightline carries no Meridian code; the filter is inert without it.

---

## Hooks

Every value the plugin emits passes through a filter first.

| Area | Filters |
|---|---|
| Request | `sightline_context`, `sightline_settings`, `sightline_tokens` |
| Head | `sightline_title`, `sightline_description`, `sightline_description_length`, `sightline_canonical`, `sightline_robots`, `sightline_image`, `sightline_og_title`, `sightline_og_description`, `sightline_og_type`, `sightline_head_tags` |
| Schema | `sightline_schema_graph`, `sightline_organization_schema`, `sightline_website_schema`, `sightline_webpage_schema`, `sightline_webpage_type`, `sightline_product_schema`, `sightline_aggregate_rating`, `sightline_price_tiers` |
| Breadcrumbs | `sightline_breadcrumb_trail`, `sightline_primary_term`, `sightline_trail_taxonomy` |
| Store | `sightline_is_product_post_type`, `sightline_transactional_page_ids`, `sightline_edd_page_setting_keys` |
| Sitemaps | `sightline_sitemap_types`, `sightline_sitemap_post_urls`, `sightline_sitemap_term_urls`, `sightline_sitemap_excluded_posts` |
| Redirects | `sightline_redirect_request_path`, `sightline_matched_redirect` |
| Admin | `sightline_show_meta_box` |

---

## Storage

| What | Where |
|---|---|
| Per-object values | Post, term and user meta under `_sightline_*`: `title`, `description`, `robots` (array), `canonical`, `og_title`, `og_description`, `og_image`, `focus_keyword`, and `primary_{taxonomy}` for the primary term |
| Settings | Option `sightline_settings` |
| Redirects | Table `{prefix}sightline_redirects`, plus the active-rule cache in option `sightline_active_redirects` |
| Bookkeeping | Options `sightline_version`, `sightline_redirects_db_version`, `sightline_import_report` |

Activation creates the redirect table, writes first-run settings if none exist, and flushes rewrite rules (for the sitemap routes). Deactivation only flushes rewrite rules.

## Uninstall

**Keeps everything by default.** Data is deleted only when *delete data on uninstall* has been enabled on the Tools screen first. A redirect table cannot be recreated from content, and deleting a plugin to test something should not be what loses it.

When enabled, `uninstall.php` removes every `_sightline_*` meta row, the options above and the redirect table.

---

## Development

This repository is the single source for every site that runs Sightline. On the UI Lib workspaces it is checked out as a submodule of the main website (`ui-lib-website/wordpress/plugins/sightline-seo`), and the blog's Docker setup mounts that same folder — so one edit is live on both local sites at once.

- **Test on every site that runs it** before committing: the main store (`localhost:8080`, with EDD) and the blog (`localhost:8082`, without EDD).
- **Nothing site-specific goes in here.** Anything that depends on another plugin must detect it and stay silent without it; per-site differences go through settings or the filters above.
- **Every change bumps the version** — both the `Version:` header and `SIGHTLINE_VERSION` in `sightline-seo.php`, plus the changelog in `readme.txt` — and the same version is deployed to every production site.

## License

GPL-2.0-or-later. See <https://www.gnu.org/licenses/gpl-2.0.html>.
