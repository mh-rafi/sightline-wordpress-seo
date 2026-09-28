=== Sightline SEO ===
Contributors: peakpulse
Tags: seo, schema, structured data, sitemap, redirects
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Titles, descriptions, social cards, structured data, sitemaps and redirects — with Product schema that is actually correct for a digital-download store.

== Description ==

Sightline SEO does the ordinary things an SEO plugin does, and does two things
most of them do badly.

**Product schema that passes.** On a store running Easy Digital Downloads,
Sightline emits one Product entity per page with a real SKU, one Offer per
price tier, `availability` on every offer, `brand`, `priceValidUntil`, and an
`aggregateRating` drawn from the store's own reviews. Not the lowest price of
a variable-priced product. Not the literal string "-" where a SKU should be.
Not a second Product entity fighting with the theme's microdata — where the
theme offers a filter to switch its own output off, Sightline uses it.

**Leaving is a feature.** Every key the plugin writes is documented on its own
Tools screen. Settings and redirects export as JSON. Uninstalling asks first,
and keeps your data unless you tell it not to.

= What it does =

* Titles, meta descriptions and canonicals for posts, pages, custom post
  types, taxonomy terms, post type archives, author archives and the front
  page.
* Open Graph and Twitter cards, with `og:type` set to `product` on products
  rather than `article` on everything.
* One JSON-LD `@graph` per page: Organization, WebSite with a search action,
  WebPage/ItemPage/CollectionPage, BreadcrumbList, and Product on a store.
  Every social profile you configure goes into `sameAs`, not just the first.
* XML sitemaps at `/sitemap_index.xml` with per-type children, excluding
  anything marked noindex.
* Sensible store defaults: cart, checkout, account, confirmation, history and
  registration pages are `noindex, follow` and out of the sitemap out of the
  box — detected through the store's own page settings, not guessed from
  slugs.
* A redirect table with exact, contains, starts-with, ends-with and regular
  expression matching, 301/302/307/410/451 responses, and a hit counter so
  dead rules can be found later.
* A migrator out of Rank Math.

= Migrating from Rank Math =

Tools → Import. It is non-destructive, idempotent, batched, and honest:
nothing Rank Math wrote is changed or deleted, running it twice changes
nothing the second time, and every row it skips is reported with the reason.
Both plugins can stay active while you check the result.

Redirects are the part that matters most. They exist only in Rank Math's own
table, nothing outside it knows they are there, and deactivating the plugin
stops every one of them without an error anywhere.

= Store support =

Easy Digital Downloads is detected and supported. It is not required —
without a store, Sightline is an ordinary SEO plugin and emits no product
markup at all.

== Filters ==

Every value the plugin emits passes through a filter first.

`sightline_title`, `sightline_description`, `sightline_canonical`,
`sightline_image`, `sightline_og_title`, `sightline_og_description`,
`sightline_og_type`, `sightline_robots`, `sightline_head_tags`,
`sightline_schema_graph`, `sightline_organization_schema`,
`sightline_website_schema`, `sightline_webpage_schema`,
`sightline_webpage_type`, `sightline_product_schema`,
`sightline_aggregate_rating`, `sightline_price_tiers`,
`sightline_breadcrumb_trail`, `sightline_primary_term`,
`sightline_trail_taxonomy`, `sightline_sitemap_types`,
`sightline_sitemap_post_urls`, `sightline_sitemap_term_urls`,
`sightline_sitemap_excluded_posts`, `sightline_transactional_page_ids`,
`sightline_is_product_post_type`, `sightline_matched_redirect`,
`sightline_tokens`, `sightline_context`, `sightline_settings`.

== Frequently Asked Questions ==

= Does it phone home? =

No. Nothing is sent anywhere, with or without consent, and there is no
account to create.

= Will it nag me? =

No. There are no dashboard widgets, no notices on other plugins' screens and
nothing to upgrade to.

= What happens to my data if I delete the plugin? =

Nothing, unless you asked for it to be deleted on the Tools screen. Keeping
is the default: a redirect table cannot be recreated from your content, and
deleting a plugin to test something should not be what loses it.

== Changelog ==

= 1.0.0 =
* First release.
