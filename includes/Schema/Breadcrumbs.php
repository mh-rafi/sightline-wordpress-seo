<?php

namespace Sightline\SEO\Schema;

use Sightline\SEO\Context;
use Sightline\SEO\Options;
use Sightline\SEO\Terms;
use Sightline\SEO\Text;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The breadcrumb trail (R5).
 *
 * Absent site-wide on the reference store, though breadcrumbs are visibly
 * rendered on the product, page and archive templates -- so the markup
 * describes a trail that search engines cannot see.
 *
 * The trail is exposed as an array as well as as JSON-LD so a theme can
 * render the visible breadcrumbs from the same source. Two implementations
 * of one trail is how the visible and the structured versions come to
 * disagree, which is worse than having only one of them.
 */
class Breadcrumbs
{
    /**
     * The trail for a context: an ordered list of ['name' => , 'url' => ].
     *
     * The last crumb is the current page and carries no URL -- a
     * self-referential link in a BreadcrumbList is noise, and Google drops
     * it anyway.
     *
     * @param Context $context
     * @return array<int,array{name:string,url:string}>
     */
    public static function trail(Context $context)
    {
        $crumbs = array();

        $home_label = (string) Options::get('breadcrumbs/home_label', '');
        $crumbs[] = array(
            'name' => '' !== $home_label ? Text::plain($home_label) : __('Home', 'sightline-seo'),
            'url'  => home_url('/'),
        );

        switch ($context->type) {
            case Context::HOME:
                $crumbs = array();
                break;

            case Context::POST:
                $crumbs = array_merge($crumbs, self::post_crumbs($context));
                break;

            case Context::TERM:
                $crumbs = array_merge($crumbs, self::term_crumbs($context));
                break;

            case Context::PT_ARCHIVE:
                $crumbs = array_merge($crumbs, self::archive_crumbs($context->subtype, true));
                break;

            case Context::USER:
                $user = get_userdata($context->object_id);
                if ($user) {
                    $crumbs[] = array('name' => Text::plain($user->display_name), 'url' => '');
                }
                break;

            case Context::SEARCH:
                $crumbs[] = array(
                    /* translators: %s: the search term. */
                    'name' => sprintf(__('Search results for "%s"', 'sightline-seo'), Text::plain(get_search_query())),
                    'url'  => '',
                );
                break;

            case Context::NOT_FOUND:
                $crumbs[] = array('name' => __('Page not found', 'sightline-seo'), 'url' => '');
                break;
        }

        // The current page never links to itself.
        if (!empty($crumbs)) {
            $crumbs[count($crumbs) - 1]['url'] = '';
        }

        /**
         * Filter the breadcrumb trail. Return an empty array to emit none.
         *
         * @param array   $crumbs
         * @param Context $context
         */
        return (array) apply_filters('sightline_breadcrumb_trail', $crumbs, $context);
    }

    /**
     * The BreadcrumbList node, or null when there is nothing worth saying.
     *
     * A trail of one item -- Home, and here you are -- is not a breadcrumb.
     *
     * @param Context $context
     * @param string  $id
     * @return array|null
     */
    public static function node(Context $context, $id)
    {
        if (!Options::get('schema/breadcrumbs', true)) {
            return null;
        }

        $crumbs = self::trail($context);
        if (count($crumbs) < 2) {
            return null;
        }

        $items = array();
        $position = 1;
        foreach ($crumbs as $crumb) {
            if ('' === trim((string) $crumb['name'])) {
                continue;
            }
            $item = array(
                '@type'    => 'ListItem',
                'position' => $position,
                'name'     => Text::plain($crumb['name']),
            );
            if (!empty($crumb['url'])) {
                $item['item'] = $crumb['url'];
            }
            $items[] = $item;
            $position++;
        }

        if (count($items) < 2) {
            return null;
        }

        return array(
            '@type'           => 'BreadcrumbList',
            '@id'             => $id,
            'itemListElement' => $items,
        );
    }

    /**
     * Crumbs for a singular view.
     *
     * @param Context $context
     * @return array
     */
    private static function post_crumbs(Context $context)
    {
        $post = get_post($context->object_id);
        if (!$post) {
            return array();
        }

        $crumbs = array();

        // A hierarchical post type walks its own ancestors; that is the
        // trail a visitor sees in the URL.
        if (is_post_type_hierarchical($post->post_type)) {
            foreach (array_reverse(get_post_ancestors($post)) as $ancestor_id) {
                $crumbs[] = array(
                    'name' => Text::plain(get_the_title($ancestor_id)),
                    'url'  => (string) get_permalink($ancestor_id),
                );
            }
        } else {
            $crumbs = array_merge($crumbs, self::archive_crumbs($post->post_type, false));

            if (Options::get('breadcrumbs/use_primary_term', true)) {
                $taxonomy = Terms::trail_taxonomy($post->post_type);
                if ('' !== $taxonomy) {
                    $term = Terms::primary($post->ID, $taxonomy);
                    if ($term instanceof \WP_Term) {
                        $crumbs = array_merge($crumbs, self::term_ancestor_crumbs($term));
                    }
                }
            }
        }

        $crumbs[] = array('name' => Text::plain(get_the_title($post)), 'url' => (string) get_permalink($post));

        return $crumbs;
    }

    /**
     * Crumbs for a taxonomy archive.
     *
     * @param Context $context
     * @return array
     */
    private static function term_crumbs(Context $context)
    {
        $term = get_term($context->object_id, $context->subtype);
        if (!$term instanceof \WP_Term) {
            return array();
        }

        $crumbs = array();

        // A category archive sits under the archive of whatever it
        // categorises, when that archive exists.
        $taxonomy = get_taxonomy($term->taxonomy);
        if ($taxonomy && !empty($taxonomy->object_type)) {
            $crumbs = array_merge($crumbs, self::archive_crumbs((string) reset($taxonomy->object_type), false));
        }

        return array_merge($crumbs, self::term_ancestor_crumbs($term));
    }

    /**
     * A term and its ancestors, outermost first.
     *
     * @param \WP_Term $term
     * @return array
     */
    private static function term_ancestor_crumbs(\WP_Term $term)
    {
        $crumbs = array();

        foreach (array_reverse((array) get_ancestors($term->term_id, $term->taxonomy, 'taxonomy')) as $ancestor_id) {
            $ancestor = get_term($ancestor_id, $term->taxonomy);
            if ($ancestor instanceof \WP_Term) {
                $link = get_term_link($ancestor);
                $crumbs[] = array(
                    'name' => Text::plain($ancestor->name),
                    'url'  => is_wp_error($link) ? '' : (string) $link,
                );
            }
        }

        $link = get_term_link($term);
        $crumbs[] = array(
            'name' => Text::plain($term->name),
            'url'  => is_wp_error($link) ? '' : (string) $link,
        );

        return $crumbs;
    }

    /**
     * The crumb for a post type archive, when the post type has one.
     *
     * @param string $post_type
     * @param bool   $is_current
     * @return array
     */
    private static function archive_crumbs($post_type, $is_current)
    {
        $object = get_post_type_object($post_type);
        if (!$object) {
            return array();
        }

        if ('post' === $post_type) {
            // The posts index only earns a crumb when it is a real page.
            $page_id = (int) get_option('page_for_posts');
            if (!$page_id) {
                return array();
            }
            return array(array(
                'name' => Text::plain(get_the_title($page_id)),
                'url'  => (string) get_permalink($page_id),
            ));
        }

        $link = get_post_type_archive_link($post_type);
        if (!$link && !$is_current) {
            return array();
        }

        return array(array(
            'name' => Text::plain($object->labels->name),
            'url'  => (string) $link,
        ));
    }
}
