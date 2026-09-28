<?php

namespace Sightline\SEO\Frontend;

use Sightline\SEO\Context;
use Sightline\SEO\Options;
use Sightline\SEO\Text;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What goes in <head> (R1, R2).
 *
 * Titles, the meta description, the canonical, and the Open Graph and
 * Twitter tags -- all drawn from one Values instance so the three copies of
 * the title on a page are the same string.
 *
 * Core's own canonical is removed and re-emitted here rather than left
 * alongside: core emits one only on singular views, so leaving it in place
 * means archives get none and overridden pages get two.
 */
class Head
{
    /** @var Values|null */
    private $values = null;

    /** @var bool Guards the recursion in title(). */
    private $resolving_title = false;

    /**
     * @return void
     */
    public function register()
    {
        add_filter('pre_get_document_title', array($this, 'document_title'), 20);

        remove_action('wp_head', 'rel_canonical');

        add_action('wp_head', array($this, 'output'), 1);
    }

    /**
     * @return Values
     */
    public function values()
    {
        if (null === $this->values) {
            $this->values = new Values(Context::current());
        }
        return $this->values;
    }

    /**
     * @param string $title
     * @return string
     */
    public function document_title($title)
    {
        if ($this->resolving_title || is_feed() || is_admin()) {
            return $title;
        }

        // Values::title() falls back to wp_get_document_title() when a
        // template resolves to nothing, and that call lands back on this
        // filter. The flag is what stops the loop.
        $this->resolving_title = true;
        $resolved = $this->values()->title();
        $this->resolving_title = false;

        return '' !== $resolved ? $resolved : $title;
    }

    /**
     * @return void
     */
    public function output()
    {
        if (is_feed() || is_embed()) {
            return;
        }

        $values  = $this->values();
        $context = $values->context();

        $lines = array();

        $description = $values->description();
        if ('' !== $description) {
            $lines[] = sprintf('<meta name="description" content="%s" />', esc_attr($description));
        }

        $canonical = $values->canonical();
        if ('' !== $canonical) {
            $lines[] = sprintf('<link rel="canonical" href="%s" />', esc_url($canonical));
        }

        $lines = array_merge($lines, $this->open_graph($values, $context, $canonical));
        $lines = array_merge($lines, $this->twitter($values));

        /**
         * Filter the head tags, as an array of complete lines.
         *
         * @param string[] $lines
         * @param Context  $context
         */
        $lines = (array) apply_filters('sightline_head_tags', $lines, $context);

        if (empty($lines)) {
            return;
        }

        echo "\n<!-- Sightline SEO -->\n";
        foreach ($lines as $line) {
            echo $line . "\n";
        }
        echo "<!-- /Sightline SEO -->\n\n";
    }

    /**
     * R2: the Open Graph tags.
     *
     * @param Values  $values
     * @param Context $context
     * @param string  $canonical
     * @return string[]
     */
    private function open_graph(Values $values, Context $context, $canonical)
    {
        $lines = array();

        $lines[] = $this->property('og:type', $values->og_type());
        $lines[] = $this->property('og:title', $values->og_title());
        $lines[] = $this->property('og:description', $values->og_description());

        $url = '' !== $canonical ? $canonical : $context->url();
        if ('' !== $url) {
            $lines[] = $this->property('og:url', $url, true);
        }

        $lines[] = $this->property('og:site_name', Text::plain(get_bloginfo('name')));
        $lines[] = $this->property('og:locale', get_locale());

        $image = $values->image();
        if ('' !== $image) {
            $lines[] = $this->property('og:image', $image, true);

            // Dimensions let a share card render before the crawler has
            // fetched the file. Only emitted when they are known -- guessing
            // them is worse than omitting them.
            $id = attachment_url_to_postid($image);
            if ($id) {
                $meta = wp_get_attachment_metadata($id);
                if (!empty($meta['width']) && !empty($meta['height'])) {
                    $lines[] = $this->property('og:image:width', (string) $meta['width']);
                    $lines[] = $this->property('og:image:height', (string) $meta['height']);
                }
                $alt = trim((string) get_post_meta($id, '_wp_attachment_image_alt', true));
                if ('' !== $alt) {
                    $lines[] = $this->property('og:image:alt', Text::plain($alt));
                }
            }
        }

        if ('article' === $values->og_type() && Context::POST === $context->type) {
            $post = get_post($context->object_id);
            if ($post) {
                $lines[] = $this->property('article:published_time', get_the_date(DATE_W3C, $post));
                $lines[] = $this->property('article:modified_time', get_the_modified_date(DATE_W3C, $post));
            }
        }

        return array_values(array_filter($lines));
    }

    /**
     * R2: the Twitter card tags.
     *
     * @param Values $values
     * @return string[]
     */
    private function twitter(Values $values)
    {
        $lines = array();

        $card = (string) Options::get('social/card_type', 'summary_large_image');
        $lines[] = $this->name('twitter:card', $card);
        $lines[] = $this->name('twitter:title', $values->og_title());
        $lines[] = $this->name('twitter:description', $values->og_description());

        $image = $values->image();
        if ('' !== $image) {
            $lines[] = $this->name('twitter:image', $image, true);
        }

        $site = trim((string) Options::get('social/twitter_site', ''));
        if ('' !== $site) {
            $lines[] = $this->name('twitter:site', '@' . ltrim($site, '@'));
        }

        return array_values(array_filter($lines));
    }

    /**
     * One `property=` tag, or '' when the value is empty.
     *
     * Encoding happens here and only here (R9): the value arrived as plain
     * text and leaves esc_attr()'d exactly once.
     *
     * @param string $property
     * @param string $content
     * @param bool   $is_url
     * @return string
     */
    private function property($property, $content, $is_url = false)
    {
        $content = (string) $content;
        if ('' === trim($content)) {
            return '';
        }

        return sprintf(
            '<meta property="%s" content="%s" />',
            esc_attr($property),
            $is_url ? esc_url($content) : esc_attr($content)
        );
    }

    /**
     * One `name=` tag, or '' when the value is empty.
     *
     * @param string $name
     * @param string $content
     * @param bool   $is_url
     * @return string
     */
    private function name($name, $content, $is_url = false)
    {
        $content = (string) $content;
        if ('' === trim($content)) {
            return '';
        }

        return sprintf(
            '<meta name="%s" content="%s" />',
            esc_attr($name),
            $is_url ? esc_url($content) : esc_attr($content)
        );
    }
}
