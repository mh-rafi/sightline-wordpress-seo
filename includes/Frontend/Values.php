<?php

namespace Sightline\SEO\Frontend;

use Sightline\SEO\Context;
use Sightline\SEO\Meta;
use Sightline\SEO\Options;
use Sightline\SEO\Text;
use Sightline\SEO\Tokens;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The values this page advertises, resolved once and shared.
 *
 * The title in <title>, the title in og:title and the title in the JSON-LD
 * WebPage node are the same string; so are the three descriptions. Working
 * that out three times is how they drift, and a page whose og:title and
 * <title> disagree is a page nobody notices is wrong.
 *
 * Every value leaves here as plain text through a filter (spec section 2:
 * "filters are the API"), so a store can override a description without
 * patching the plugin. Encoding happens at the point of output, once.
 */
class Values
{
    /** @var array<string,mixed> */
    private $cache = array();

    /** @var Context */
    private $context;

    /**
     * @param Context|null $context
     */
    public function __construct(?Context $context = null)
    {
        $this->context = $context ? $context : Context::current();
    }

    /**
     * @return Context
     */
    public function context()
    {
        return $this->context;
    }

    /**
     * The document title.
     *
     * @return string
     */
    public function title()
    {
        return $this->memo('title', function () {
            $override = $this->object_meta('title');
            $template = '' !== $override ? $override : $this->template('title');
            $title    = Tokens::expand($template, $this->context);

            if ('' === $title) {
                $title = Text::plain(wp_get_document_title());
            }

            /**
             * Filter the document title.
             *
             * @param string  $title
             * @param Context $context
             */
            return (string) apply_filters('sightline_title', $title, $this->context);
        });
    }

    /**
     * The meta description.
     *
     * @return string
     */
    public function description()
    {
        return $this->memo('description', function () {
            $override = $this->object_meta('description');
            $template = '' !== $override ? $override : $this->template('description');
            $text     = Tokens::expand($template, $this->context);

            // A template that resolved to nothing is not a description. Fall
            // back to derived body copy rather than emitting an empty tag --
            // an empty description tag is the bug that started this project.
            if ('' === $text && Context::POST === $this->context->type) {
                $post = get_post($this->context->object_id);
                if ($post) {
                    $text = Tokens::post_excerpt($post);
                }
            }

            $text = Text::truncate($text, (int) apply_filters('sightline_description_length', 160, $this->context));

            /**
             * Filter the meta description.
             *
             * @param string  $text
             * @param Context $context
             */
            return (string) apply_filters('sightline_description', $text, $this->context);
        });
    }

    /**
     * The canonical URL.
     *
     * Core already emits a correct canonical on nearly every view, so this
     * only supplies one where an override exists or where core has nothing
     * to say (a post type archive, for instance).
     *
     * @return string
     */
    public function canonical()
    {
        return $this->memo('canonical', function () {
            $override = $this->object_meta('canonical');
            $url      = '' !== $override ? $override : $this->context->url();

            if ('' !== $url) {
                $paged = (int) get_query_var('paged');
                if ($paged > 1 && '' === $override) {
                    $url = user_trailingslashit(trailingslashit($url) . 'page/' . $paged);
                }
            }

            /**
             * Filter the canonical URL. Return '' to emit none.
             *
             * @param string  $url
             * @param Context $context
             */
            return (string) apply_filters('sightline_canonical', $url, $this->context);
        });
    }

    /**
     * The social sharing image URL.
     *
     * @return string
     */
    public function image()
    {
        return $this->memo('image', function () {
            $url = '';

            $own = $this->object_meta('og_image');
            if ('' !== $own) {
                // Stored as an attachment ID by the picker, but the migrator
                // may bring a bare URL across from another plugin.
                $url = ctype_digit($own) ? (string) wp_get_attachment_image_url((int) $own, 'full') : $own;
            }

            if ('' === $url && Context::POST === $this->context->type) {
                $url = (string) get_the_post_thumbnail_url($this->context->object_id, 'full');
            }

            if ('' === $url && Context::HOME === $this->context->type) {
                $home_image = (string) Options::get('home/image', '');
                $url = ctype_digit($home_image) ? (string) wp_get_attachment_image_url((int) $home_image, 'full') : $home_image;
            }

            if ('' === $url) {
                $default = (string) Options::get('social/default_image', '');
                $url = ctype_digit($default) ? (string) wp_get_attachment_image_url((int) $default, 'full') : $default;
            }

            /**
             * Filter the social sharing image.
             *
             * @param string  $url
             * @param Context $context
             */
            return (string) apply_filters('sightline_image', $url, $this->context);
        });
    }

    /**
     * The Open Graph title, which falls back to the document title.
     *
     * @return string
     */
    public function og_title()
    {
        return $this->memo('og_title', function () {
            $own = $this->object_meta('og_title');
            $title = '' !== $own ? Tokens::expand($own, $this->context) : $this->title();

            return (string) apply_filters('sightline_og_title', $title, $this->context);
        });
    }

    /**
     * The Open Graph description.
     *
     * @return string
     */
    public function og_description()
    {
        return $this->memo('og_description', function () {
            $own = $this->object_meta('og_description');
            $text = '' !== $own ? Tokens::expand($own, $this->context) : $this->description();

            return (string) apply_filters('sightline_og_description', $text, $this->context);
        });
    }

    /**
     * The og:type for this page.
     *
     * R2: `product` on a product. Rank Math reports `article` for products,
     * post type archives and plain pages alike -- wrong on all three, and
     * the reason this is a resolved value rather than a constant.
     *
     * @return string
     */
    public function og_type()
    {
        return $this->memo('og_type', function () {
            $type = 'website';

            if (Context::POST === $this->context->type) {
                if (\Sightline\SEO\Integrations\EDD::is_product_post_type($this->context->subtype)) {
                    $type = 'product';
                } elseif ('page' === $this->context->subtype) {
                    $type = 'website';
                } elseif (is_post_type_hierarchical($this->context->subtype)) {
                    $type = 'website';
                } else {
                    $type = 'article';
                }
            } elseif (Context::USER === $this->context->type) {
                $type = 'profile';
            }

            /**
             * Filter the og:type.
             *
             * @param string  $type
             * @param Context $context
             */
            return (string) apply_filters('sightline_og_type', $type, $this->context);
        });
    }

    /**
     * A per-object meta value for the current context, or ''.
     *
     * @param string $field
     * @return string
     */
    public function object_meta($field)
    {
        if (!$this->context->has_object()) {
            return '';
        }

        $value = Meta::get($this->context->meta_type(), $this->context->object_id, $field);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * The settings template for this context and field.
     *
     * @param string $field 'title' or 'description'.
     * @return string
     */
    private function template($field)
    {
        switch ($this->context->type) {
            case Context::HOME:
                $home = Options::get('home', array());
                $value = isset($home[$field]) ? (string) $home[$field] : '';
                // A static front page with no home template still deserves a
                // title, so fall through to the page's own post type rules.
                if ('' === $value && $this->context->object_id) {
                    $defaults = Options::post_type_defaults('page');
                    $value = isset($defaults[$field]) ? (string) $defaults[$field] : '';
                }
                return $value;

            case Context::POST:
                $defaults = Options::post_type_defaults($this->context->subtype);
                return isset($defaults[$field]) ? (string) $defaults[$field] : '';

            case Context::TERM:
                $defaults = Options::taxonomy_defaults($this->context->subtype);
                return isset($defaults[$field]) ? (string) $defaults[$field] : '';

            case Context::PT_ARCHIVE:
                $defaults = Options::archive_defaults($this->context->subtype);
                return isset($defaults[$field]) ? (string) $defaults[$field] : '';

            case Context::USER:
                $author = Options::get('author', array());
                return isset($author[$field]) ? (string) $author[$field] : '';

            case Context::SEARCH:
                return 'title' === $field
                    ? __('Search for "%search_query%"', 'sightline-seo') . ' %sep% %sitename%'
                    : '';

            case Context::NOT_FOUND:
                return 'title' === $field
                    ? __('Page not found', 'sightline-seo') . ' %sep% %sitename%'
                    : '';
        }

        return '';
    }

    /**
     * @param string   $key
     * @param callable $compute
     * @return mixed
     */
    private function memo($key, $compute)
    {
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $compute();
        }
        return $this->cache[$key];
    }
}
