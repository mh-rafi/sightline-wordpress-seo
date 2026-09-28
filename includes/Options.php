<?php

namespace Sightline\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Everything that is not per-object lives in one option, `sightline_settings`.
 *
 * One option rather than a family of them because the whole set has to be
 * exportable and importable as a single JSON blob (R11) and re-enterable by
 * hand on a production site that will never receive this database (R10, and
 * DEPLOY.md's "deploys are files only"). A settings screen that writes twenty
 * options is twenty things to miss.
 *
 * Reads are cached per request. Defaults are merged shallowly per top-level
 * section so a stored partial section does not lose keys added by a later
 * version.
 */
class Options
{
    const KEY = 'sightline_settings';

    /** @var array|null */
    private static $cache = null;

    /**
     * The shipped defaults.
     *
     * Per-post-type and per-taxonomy entries are generated rather than listed,
     * so a site that registers a new post type gets sane output without
     * touching settings.
     *
     * @return array
     */
    public static function defaults()
    {
        return array(
            'title_separator'   => '-',

            // Front page. Used when the front page is the posts index; a
            // static front page also accepts per-post meta, which wins.
            'home'              => array(
                'title'       => '%sitename% %sep% %sitedesc%',
                'description' => '',
                'image'       => '',
            ),

            // Keyed by post type name.
            'post_types'        => array(),
            // Keyed by taxonomy name.
            'taxonomies'        => array(),
            // Keyed by post type name; the post type archive itself.
            'archives'          => array(),

            'author'            => array(
                'enabled'     => true,
                'title'       => '%name% %sep% %sitename%',
                'description' => '',
                'noindex'     => true,
            ),
            'search'            => array('noindex' => true),

            'social'            => array(
                'profiles'      => array(),
                'twitter_site'  => '',
                'card_type'     => 'summary_large_image',
                'default_image' => '',
            ),

            'organization'      => array(
                'enabled' => true,
                'type'    => 'Organization',
                'name'    => '',
                'logo'    => '',
            ),

            'website'           => array(
                'enabled'    => true,
                'name'       => '',
                'searchbox'  => true,
            ),

            'schema'            => array(
                'breadcrumbs' => true,
                'product'     => true,
                'webpage'     => true,
                'price_valid_months' => 12,
            ),

            'breadcrumbs'       => array(
                'home_label' => '',
                'use_primary_term' => true,
            ),

            'sitemap'           => array(
                'enabled'    => true,
                'per_page'   => 500,
                'post_types' => array(),
                'taxonomies' => array(),
                'images'     => true,
            ),

            'redirects'         => array(
                'enabled'      => true,
                'fallback_404' => false,
            ),

            'store'             => array(
                // R8: transactional pages default to noindex,follow. Detected
                // through the store's own page settings, never by slug.
                'noindex_transactional' => true,
                'take_over_microdata'   => true,
                // Pages the store does not record in its own settings -- an
                // account or registration page belonging to the theme. Listed
                // by ID because R8 forbids matching them by slug: a renamed
                // page would silently start being indexed again.
                'extra_noindex_pages'   => array(),
            ),

            'advanced'          => array(
                'delete_data_on_uninstall' => false,
            ),
        );
    }

    /**
     * The full settings array, defaults merged in.
     *
     * @return array
     */
    public static function all()
    {
        if (null !== self::$cache) {
            return self::$cache;
        }

        $stored = get_option(self::KEY, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        $merged = self::defaults();
        foreach ($stored as $section => $value) {
            if (is_array($value) && isset($merged[$section]) && is_array($merged[$section])) {
                $merged[$section] = array_merge($merged[$section], $value);
            } else {
                $merged[$section] = $value;
            }
        }

        /**
         * Filter the whole settings array.
         *
         * @param array $merged
         */
        self::$cache = apply_filters('sightline_settings', $merged);

        return self::$cache;
    }

    /**
     * One value, addressed with a slash path: `social/twitter_site`.
     *
     * @param string $path
     * @param mixed  $default
     * @return mixed
     */
    public static function get($path, $default = null)
    {
        $node = self::all();
        foreach (explode('/', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    /**
     * Replace the whole settings array.
     *
     * @param array $settings
     * @return bool
     */
    public static function save(array $settings)
    {
        self::$cache = null;
        return update_option(self::KEY, $settings);
    }

    /**
     * Merge one section and save.
     *
     * @param string $section
     * @param array  $values
     * @return bool
     */
    public static function set_section($section, array $values)
    {
        $stored = get_option(self::KEY, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        $existing = isset($stored[$section]) && is_array($stored[$section]) ? $stored[$section] : array();
        $stored[$section] = array_merge($existing, $values);
        return self::save($stored);
    }

    /**
     * Defaults for one post type's templates.
     *
     * Generated rather than stored so an unconfigured post type still emits a
     * sensible title instead of nothing.
     *
     * @param string $post_type
     * @return array
     */
    public static function post_type_defaults($post_type)
    {
        $stored = self::get('post_types/' . $post_type, array());
        return array_merge(array(
            'title'       => '%title% %sep% %sitename%',
            'description' => '%excerpt%',
            'noindex'     => false,
            'nofollow'    => false,
            'in_sitemap'  => 'attachment' !== $post_type,
        ), is_array($stored) ? $stored : array());
    }

    /**
     * Defaults for one taxonomy's templates.
     *
     * @param string $taxonomy
     * @return array
     */
    public static function taxonomy_defaults($taxonomy)
    {
        $stored = self::get('taxonomies/' . $taxonomy, array());
        return array_merge(array(
            'title'       => '%term% %sep% %sitename%',
            'description' => '%term_description%',
            'noindex'     => false,
            'nofollow'    => false,
            'in_sitemap'  => true,
        ), is_array($stored) ? $stored : array());
    }

    /**
     * Defaults for one post type archive.
     *
     * @param string $post_type
     * @return array
     */
    public static function archive_defaults($post_type)
    {
        $stored = self::get('archives/' . $post_type, array());
        return array_merge(array(
            'title'       => '%pt_plural% %sep% %sitename%',
            'description' => '',
            'noindex'     => false,
            'nofollow'    => false,
        ), is_array($stored) ? $stored : array());
    }

    /**
     * Drop the request cache. For tests and after an import.
     *
     * @return void
     */
    public static function flush()
    {
        self::$cache = null;
    }
}
