<?php

namespace Sightline\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the current request is about, resolved once.
 *
 * Every output component -- title, description, robots, canonical, Open
 * Graph, the JSON-LD graph, breadcrumbs -- needs the same three answers:
 * which kind of object is being displayed, which object, and where its
 * settings live. Resolving that in each of them separately is how the
 * outputs drift apart, which is how a checkout page ends up described as
 * an Article with a Person author (spec section 7).
 *
 * Storage per kind follows section 4 of the spec: post meta, term meta,
 * user meta, or `sightline_settings`.
 */
class Context
{
    const POST      = 'post';
    const TERM      = 'term';
    const USER      = 'user';
    const PT_ARCHIVE = 'post_type_archive';
    const HOME      = 'home';
    const SEARCH    = 'search';
    const NOT_FOUND = 'not_found';
    const DATE      = 'date';
    const OTHER     = 'other';

    /** @var self|null */
    private static $current = null;

    /** @var string */
    public $type = self::OTHER;

    /** @var int */
    public $object_id = 0;

    /** @var string Post type, taxonomy name, or ''. */
    public $subtype = '';

    /** @var \WP_Post|\WP_Term|\WP_User|null */
    public $object = null;

    /**
     * The context for this request.
     *
     * @return self
     */
    public static function current()
    {
        if (null !== self::$current) {
            return self::$current;
        }

        $c = new self();

        if (is_404()) {
            $c->type = self::NOT_FOUND;
        } elseif (is_search()) {
            $c->type = self::SEARCH;
        } elseif (is_front_page()) {
            $c->type = self::HOME;
            // A static front page is still a post, and per-post meta on it
            // must win over the site-wide home settings.
            if ('page' === get_option('show_on_front')) {
                $id = (int) get_option('page_on_front');
                if ($id) {
                    $c->object_id = $id;
                    $c->subtype   = 'page';
                    $c->object    = get_post($id);
                }
            }
        } elseif (is_home()) {
            // Posts index on a site with a static front page.
            $c->type    = self::PT_ARCHIVE;
            $c->subtype = 'post';
            $id = (int) get_option('page_for_posts');
            if ($id) {
                $c->object_id = $id;
                $c->object    = get_post($id);
            }
        } elseif (is_singular()) {
            $post = get_queried_object();
            if ($post instanceof \WP_Post) {
                $c->type      = self::POST;
                $c->object_id = (int) $post->ID;
                $c->subtype   = $post->post_type;
                $c->object    = $post;
            }
        } elseif (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                $c->type      = self::TERM;
                $c->object_id = (int) $term->term_id;
                $c->subtype   = $term->taxonomy;
                $c->object    = $term;
            }
        } elseif (is_post_type_archive()) {
            $c->type    = self::PT_ARCHIVE;
            $c->subtype = (string) get_query_var('post_type');
            if (is_array($c->subtype)) {
                $c->subtype = (string) reset($c->subtype);
            }
        } elseif (is_author()) {
            $user = get_queried_object();
            if ($user instanceof \WP_User) {
                $c->type      = self::USER;
                $c->object_id = (int) $user->ID;
                $c->object    = $user;
            }
        } elseif (is_date()) {
            $c->type = self::DATE;
        }

        /**
         * Filter the resolved context.
         *
         * @param self $c
         */
        self::$current = apply_filters('sightline_context', $c);

        return self::$current;
    }

    /**
     * Is this a singular view of the given post type?
     *
     * @param string $post_type
     * @return bool
     */
    public function is_singular_of($post_type)
    {
        return self::POST === $this->type && $post_type === $this->subtype;
    }

    /**
     * Whether this context has a real object with its own meta row.
     *
     * @return bool
     */
    public function has_object()
    {
        return in_array($this->type, array(self::HOME, self::POST, self::TERM, self::USER), true)
            && $this->object_id > 0;
    }

    /**
     * The object type to read this context's meta as.
     *
     * A static front page resolves to HOME with the page as its object, but
     * its meta is ordinary post meta -- without this, Meta::get() finds
     * nothing for HOME and the page's own title and description are ignored.
     *
     * @return string
     */
    public function meta_type()
    {
        return self::HOME === $this->type ? self::POST : $this->type;
    }

    /**
     * The canonical permalink for this context, unpaged.
     *
     * @return string
     */
    public function url()
    {
        switch ($this->type) {
            case self::POST:
                return (string) get_permalink($this->object_id);
            case self::TERM:
                $link = get_term_link($this->object_id, $this->subtype);
                return is_wp_error($link) ? '' : (string) $link;
            case self::USER:
                return (string) get_author_posts_url($this->object_id);
            case self::HOME:
                return (string) home_url('/');
            case self::PT_ARCHIVE:
                if ('post' === $this->subtype && $this->object_id) {
                    return (string) get_permalink($this->object_id);
                }
                $link = get_post_type_archive_link($this->subtype);
                return $link ? (string) $link : '';
        }

        return '';
    }

    /**
     * Reset the resolved context. For tests.
     *
     * @return void
     */
    public static function reset()
    {
        self::$current = null;
    }
}
