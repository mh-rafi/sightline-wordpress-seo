<?php

namespace Sightline\SEO\Schema;

use Sightline\SEO\Context;
use Sightline\SEO\Frontend\Values;
use Sightline\SEO\Integrations\EDD;
use Sightline\SEO\Options;
use Sightline\SEO\Text;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One JSON-LD @graph per page (R3).
 *
 * A single block, not one per entity: nodes carry `@id`s and reference each
 * other, so a consumer reads "this WebPage is part of this WebSite, which is
 * published by this Organization" rather than three unrelated fragments it
 * has to guess the relationships between.
 *
 * `@id` scheme, all absolute so they stay unique across a multisite:
 *
 *   {home}/#organization    the publisher, one per site
 *   {home}/#website         the site, one per site
 *   {url}#webpage           this page
 *   {url}#breadcrumb        this page's trail
 *   {url}#product           this page's product, on a store
 *
 * R9 is enforced at the exit: every string in the graph is plain text, and
 * Text::json() encodes it once. JSON-LD is JSON, not HTML, so an `&amp;`
 * reaching a `name` value is a double encoding -- which is what the
 * reference store was emitting.
 */
class Graph
{
    /**
     * @return void
     */
    public function register()
    {
        add_action('wp_head', array($this, 'output'), 10);
    }

    /**
     * @return void
     */
    public function output()
    {
        if (is_feed() || is_embed() || is_admin()) {
            return;
        }

        $graph = $this->build();
        if (empty($graph)) {
            return;
        }

        $document = array(
            '@context' => 'https://schema.org',
            '@graph'   => array_values($graph),
        );

        echo "\n" . '<script type="application/ld+json" class="sightline-schema">' . "\n";
        // Already JSON. Escaping it as HTML here is the double-encode this
        // requirement exists to prevent; the only thing that could break out
        // of a <script> block is the closing tag itself.
        echo str_replace('</', '<\/', Text::json($document)) . "\n";
        echo '</script>' . "\n";
    }

    /**
     * The nodes for this page.
     *
     * @return array
     */
    public function build()
    {
        $context = Context::current();
        $values  = new Values($context);

        $graph = array();

        $organization = $this->organization();
        if ($organization) {
            $graph['organization'] = $organization;
        }

        $website = $this->website($organization ? $organization['@id'] : '');
        if ($website) {
            $graph['website'] = $website;
        }

        $page_url = $values->canonical();
        if ('' === $page_url) {
            $page_url = $context->url();
        }

        if ('' !== $page_url) {
            $breadcrumb = Breadcrumbs::node($context, $page_url . '#breadcrumb');
            if ($breadcrumb) {
                $graph['breadcrumb'] = $breadcrumb;
            }

            $product = Product::node($context, $values, $page_url . '#product');
            if ($product) {
                $graph['product'] = $product;
            }

            $webpage = $this->webpage($context, $values, $page_url, $website ? $website['@id'] : '', isset($graph['breadcrumb']));
            if ($webpage) {
                $graph['webpage'] = $webpage;
            }
        }

        /**
         * Filter the whole graph before output. Keys are node names, so a
         * consumer can unset one without knowing its position.
         *
         * @param array   $graph
         * @param Context $context
         */
        return (array) apply_filters('sightline_schema_graph', $graph, $context);
    }

    /**
     * The publisher.
     *
     * `sameAs` takes every configured profile. Rank Math emits one here
     * where this site's footer links five -- a knowledge-graph signal thrown
     * away because the settings screen only had one field.
     *
     * @return array|null
     */
    private function organization()
    {
        if (!Options::get('organization/enabled', true)) {
            return null;
        }

        $name = trim((string) Options::get('organization/name', ''));
        if ('' === $name) {
            $name = (string) get_bloginfo('name');
        }

        $type = (string) Options::get('organization/type', 'Organization');
        if ('' === $type) {
            $type = 'Organization';
        }

        $node = array(
            '@type' => $type,
            '@id'   => home_url('/') . '#organization',
            'name'  => Text::plain($name),
            'url'   => home_url('/'),
        );

        $logo = (string) Options::get('organization/logo', '');
        if ('' !== $logo) {
            $id  = ctype_digit($logo) ? (int) $logo : attachment_url_to_postid($logo);
            $src = $id ? wp_get_attachment_image_src($id, 'full') : null;
            if ($src) {
                $node['logo'] = array(
                    '@type'  => 'ImageObject',
                    '@id'    => home_url('/') . '#logo',
                    'url'    => $src[0],
                    'width'  => (int) $src[1],
                    'height' => (int) $src[2],
                );
                $node['image'] = array('@id' => home_url('/') . '#logo');
            } elseif (!ctype_digit($logo)) {
                $node['logo'] = $logo;
            }
        }

        $profiles = array();
        foreach ((array) Options::get('social/profiles', array()) as $profile) {
            $profile = trim((string) $profile);
            if ('' !== $profile) {
                $profiles[] = esc_url_raw($profile);
            }
        }
        $profiles = array_values(array_unique(array_filter($profiles)));
        if (!empty($profiles)) {
            $node['sameAs'] = $profiles;
        }

        /**
         * Filter the Organization node.
         *
         * @param array $node
         */
        return (array) apply_filters('sightline_organization_schema', $node);
    }

    /**
     * The site.
     *
     * @param string $publisher_id
     * @return array|null
     */
    private function website($publisher_id)
    {
        if (!Options::get('website/enabled', true)) {
            return null;
        }

        $name = trim((string) Options::get('website/name', ''));
        if ('' === $name) {
            $name = (string) get_bloginfo('name');
        }

        $node = array(
            '@type'       => 'WebSite',
            '@id'         => home_url('/') . '#website',
            'url'         => home_url('/'),
            'name'        => Text::plain($name),
            'inLanguage'  => get_bloginfo('language'),
        );

        $tagline = Text::plain(get_bloginfo('description'));
        if ('' !== $tagline) {
            $node['description'] = $tagline;
        }

        if ('' !== $publisher_id) {
            $node['publisher'] = array('@id' => $publisher_id);
        }

        if (Options::get('website/searchbox', true)) {
            $node['potentialAction'] = array(
                array(
                    '@type'       => 'SearchAction',
                    'target'      => array(
                        '@type'       => 'EntryPoint',
                        'urlTemplate' => home_url('/') . '?s={search_term_string}',
                    ),
                    'query-input' => 'required name=search_term_string',
                ),
            );
        }

        /**
         * Filter the WebSite node.
         *
         * @param array $node
         */
        return (array) apply_filters('sightline_website_schema', $node);
    }

    /**
     * This page.
     *
     * The subtype matters: a product page is an ItemPage and a category
     * archive is a CollectionPage. Calling a checkout page an Article with a
     * Person author -- which is what the reference store did -- is not a
     * cosmetic error; it is a claim about the page that is simply untrue.
     *
     * @param Context $context
     * @param Values  $values
     * @param string  $url
     * @param string  $website_id
     * @param bool    $has_breadcrumb
     * @return array|null
     */
    private function webpage(Context $context, Values $values, $url, $website_id, $has_breadcrumb)
    {
        if (!Options::get('schema/webpage', true)) {
            return null;
        }

        $node = array(
            '@type' => $this->webpage_type($context),
            '@id'   => $url . '#webpage',
            'url'   => $url,
            'name'  => $values->title(),
        );

        $description = $values->description();
        if ('' !== $description) {
            $node['description'] = $description;
        }

        if ('' !== $website_id) {
            $node['isPartOf'] = array('@id' => $website_id);
        }

        if ($has_breadcrumb) {
            $node['breadcrumb'] = array('@id' => $url . '#breadcrumb');
        }

        $image = $values->image();
        if ('' !== $image) {
            $node['primaryImageOfPage'] = array('@type' => 'ImageObject', 'url' => $image);
        }

        if (Context::POST === $context->type) {
            $post = get_post($context->object_id);
            if ($post) {
                $node['datePublished'] = get_the_date(DATE_W3C, $post);
                $node['dateModified']  = get_the_modified_date(DATE_W3C, $post);
            }
        }

        $node['inLanguage'] = get_bloginfo('language');

        /**
         * Filter the WebPage node.
         *
         * @param array   $node
         * @param Context $context
         */
        return (array) apply_filters('sightline_webpage_schema', $node, $context);
    }

    /**
     * @param Context $context
     * @return string
     */
    private function webpage_type(Context $context)
    {
        $type = 'WebPage';

        switch ($context->type) {
            case Context::HOME:
                $type = 'WebPage';
                break;
            case Context::POST:
                $type = EDD::is_product_post_type($context->subtype) ? 'ItemPage' : 'WebPage';
                break;
            case Context::TERM:
            case Context::PT_ARCHIVE:
            case Context::DATE:
                $type = 'CollectionPage';
                break;
            case Context::USER:
                $type = 'ProfilePage';
                break;
            case Context::SEARCH:
                $type = 'SearchResultsPage';
                break;
        }

        /**
         * Filter the WebPage @type.
         *
         * @param string  $type
         * @param Context $context
         */
        return (string) apply_filters('sightline_webpage_type', $type, $context);
    }
}
