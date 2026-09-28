<?php

namespace Sightline\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template tokens, in one syntax.
 *
 * `%token%`, single percent signs, and that is the only syntax that ever
 * reaches storage. Rank Math writes `%token%` and Yoast writes `%%token%%`;
 * the migrator normalises on the way in (spec section 4) so nothing
 * downstream has to guess which plugin a stored template came from.
 *
 * Unknown tokens are removed rather than left in place. A visitor seeing
 * `%custom_field(price)%` in a browser tab is worse than seeing nothing,
 * and leaving them in makes an unsupported token silently look supported.
 */
class Tokens
{
    /**
     * Expand a template against a context.
     *
     * @param string  $template
     * @param Context $context
     * @return string Plain text, ready for one encoding pass.
     */
    public static function expand($template, Context $context)
    {
        $template = (string) $template;
        if ('' === $template || false === strpos($template, '%')) {
            return Text::plain($template);
        }

        $values = self::values($context);

        /**
         * Filter the token table for this context. The entry point for a
         * theme or store to add its own tokens.
         *
         * @param array   $values
         * @param Context $context
         */
        $values = apply_filters('sightline_tokens', $values, $context);

        $out = preg_replace_callback(
            '/%([a-z0-9_]+)%/i',
            static function ($m) use ($values) {
                $name = strtolower($m[1]);
                return isset($values[$name]) ? (string) $values[$name] : '';
            },
            $template
        );

        return self::tidy($out, isset($values['sep']) ? (string) $values['sep'] : '');
    }

    /**
     * The token table for a context.
     *
     * @param Context $context
     * @return array<string,string>
     */
    public static function values(Context $context)
    {
        $sep = (string) Options::get('title_separator', '-');

        $values = array(
            'sep'          => $sep,
            'sitename'     => Text::plain(get_bloginfo('name')),
            'sitedesc'     => Text::plain(get_bloginfo('description')),
            'currentyear'  => gmdate('Y'),
            'currentmonth' => gmdate('F'),
            'currentdate'  => gmdate(get_option('date_format')),
            'page'         => self::page_token($sep),
            'pagenumber'   => (string) max(1, (int) get_query_var('paged')),
            'search_query' => Text::plain(get_search_query()),
            'title'        => '',
            'excerpt'      => '',
            'id'           => (string) $context->object_id,
        );

        switch ($context->type) {
            case Context::POST:
                $post = $context->object instanceof \WP_Post ? $context->object : get_post($context->object_id);
                if ($post) {
                    $values['title']    = Text::plain(get_the_title($post));
                    $values['excerpt']  = self::post_excerpt($post);
                    $values['date']     = get_the_date('', $post);
                    $values['modified'] = get_the_modified_date('', $post);
                    $values['name']     = Text::plain(get_the_author_meta('display_name', (int) $post->post_author));
                    $values['parent_title'] = $post->post_parent
                        ? Text::plain(get_the_title($post->post_parent))
                        : '';
                    $values['primary_category'] = self::primary_term_name($post);
                    $pt = get_post_type_object($post->post_type);
                    if ($pt) {
                        $values['pt_singular'] = Text::plain($pt->labels->singular_name);
                        $values['pt_plural']   = Text::plain($pt->labels->name);
                    }
                }
                break;

            case Context::TERM:
                $term = $context->object instanceof \WP_Term
                    ? $context->object
                    : get_term($context->object_id, $context->subtype);
                if ($term instanceof \WP_Term) {
                    $values['term']             = Text::plain($term->name);
                    $values['title']            = $values['term'];
                    $values['term_description'] = Text::plain($term->description);
                    $values['excerpt']          = $values['term_description'];
                    $tax = get_taxonomy($term->taxonomy);
                    if ($tax) {
                        $values['tax_singular'] = Text::plain($tax->labels->singular_name);
                        $values['tax_plural']   = Text::plain($tax->labels->name);
                    }
                }
                break;

            case Context::USER:
                $user = $context->object instanceof \WP_User ? $context->object : get_userdata($context->object_id);
                if ($user) {
                    $values['name']  = Text::plain($user->display_name);
                    $values['title'] = $values['name'];
                    $values['user_description'] = Text::plain(get_the_author_meta('description', $user->ID));
                    $values['excerpt'] = $values['user_description'];
                }
                break;

            case Context::PT_ARCHIVE:
                $pt = get_post_type_object($context->subtype);
                if ($pt) {
                    $values['pt_singular'] = Text::plain($pt->labels->singular_name);
                    $values['pt_plural']   = Text::plain($pt->labels->name);
                    $values['title']       = $values['pt_plural'];
                }
                break;

            case Context::HOME:
                $values['title'] = $values['sitename'];
                if ($context->object_id) {
                    $post = get_post($context->object_id);
                    if ($post) {
                        $values['excerpt'] = self::post_excerpt($post);
                    }
                }
                break;

            case Context::SEARCH:
                $values['title'] = $values['search_query'];
                break;
        }

        return $values;
    }

    /**
     * A description-shaped excerpt for a post.
     *
     * Falls back to derived body copy, which is what a search engine would
     * do anyway -- only here it is a deliberate value that the
     * `sightline_description` filter can replace.
     *
     * @param \WP_Post $post
     * @return string
     */
    public static function post_excerpt(\WP_Post $post)
    {
        $excerpt = trim((string) $post->post_excerpt);
        if ('' !== $excerpt) {
            return Text::plain($excerpt);
        }

        // The store may already own a better derivation for its products.
        // Prefer it over re-deriving, so the two never disagree.
        if ('download' === $post->post_type && function_exists('ul_product_meta_description')) {
            $store = Text::plain(ul_product_meta_description($post->ID, 0));
            if ('' !== $store) {
                return $store;
            }
        }

        return Text::from_content($post->post_content);
    }

    /**
     * "%page%" -- " - Page 2 of 5" on paged views, empty on the first.
     *
     * @param string $sep
     * @return string
     */
    private static function page_token($sep)
    {
        global $wp_query;

        $paged = max((int) get_query_var('paged'), (int) get_query_var('page'));
        if ($paged < 2) {
            return '';
        }

        $max = isset($wp_query->max_num_pages) ? (int) $wp_query->max_num_pages : 0;
        if ($max > 1) {
            /* translators: 1: current page number, 2: total page count. */
            $text = sprintf(__('Page %1$d of %2$d', 'sightline-seo'), $paged, $max);
        } else {
            /* translators: %d: current page number. */
            $text = sprintf(__('Page %d', 'sightline-seo'), $paged);
        }

        return $sep . ' ' . $text;
    }

    /**
     * The primary term's name for a post, honouring the stored choice.
     *
     * @param \WP_Post $post
     * @return string
     */
    private static function primary_term_name(\WP_Post $post)
    {
        $taxonomies = get_object_taxonomies($post->post_type, 'objects');
        foreach ($taxonomies as $taxonomy) {
            if (!$taxonomy->public || !$taxonomy->hierarchical) {
                continue;
            }
            $term = Terms::primary($post->ID, $taxonomy->name);
            if ($term instanceof \WP_Term) {
                return Text::plain($term->name);
            }
        }
        return '';
    }

    /**
     * Collapse the gaps an unresolved token leaves behind.
     *
     * "%title% %page% %sep% %sitename%" with no paging must not come out as
     * "Stocky POS  -  UI Lib", and a template whose leading token is empty
     * must not start with a separator.
     *
     * @param string $text
     * @param string $sep
     * @return string
     */
    private static function tidy($text, $sep)
    {
        $text = Text::plain($text);

        if ('' !== $sep) {
            $quoted = preg_quote($sep, '/');
            // A separator with nothing on one side of it.
            $text = preg_replace('/^(?:' . $quoted . '\s*)+/u', '', $text);
            $text = preg_replace('/(?:\s*' . $quoted . ')+$/u', '', $text);
            // Two separators with only space between them.
            $text = preg_replace('/(?:' . $quoted . '\s*){2,}/u', $sep . ' ', $text);
        }

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
