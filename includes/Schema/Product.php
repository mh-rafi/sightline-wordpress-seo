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
 * Product schema, as one entity per page (R4).
 *
 * The differentiator, and the requirement with the longest list of things
 * the reference store got wrong. Before this, a product page described
 * itself twice -- Rank Math's JSON-LD and the theme's microdata -- and the
 * two disagreed on SKU and on price. Sightline emits one Product entity and
 * switches the store's microdata off (see Integrations\EDD).
 *
 * What Rank Math left out, measured on 34 published products:
 *
 *   - `availability`, on every Offer. Omitted entirely.
 *   - `sku`. The literal string "-" on all 34.
 *   - `aggregateRating`. Absent site-wide, though real store reviews exist:
 *     MatX Free has 5.00 from 15 of them. The biggest missed rich result.
 *   - `brand`.
 *   - `priceValidUntil`, which Google warns about when missing.
 *
 * Emits nothing when no store is active. Product schema on a site with no
 * products is a rich-result penalty waiting to be collected.
 */
class Product
{
    /**
     * The Product node for a context, or null.
     *
     * @param Context $context
     * @param Values  $values
     * @param string  $id
     * @return array|null
     */
    public static function node(Context $context, Values $values, $id)
    {
        if (!Options::get('schema/product', true)) {
            return null;
        }
        if (Context::POST !== $context->type) {
            return null;
        }
        if (!EDD::is_product_post_type($context->subtype)) {
            return null;
        }

        $post_id = (int) $context->object_id;
        $url     = (string) get_permalink($post_id);

        $node = array(
            '@type'       => 'Product',
            '@id'         => $id,
            'name'        => Text::plain(get_the_title($post_id)),
            'url'         => $url,
            'sku'         => EDD::sku($post_id),
        );

        $description = $values->description();
        if ('' !== $description) {
            $node['description'] = $description;
        }

        $image = $values->image();
        if ('' !== $image) {
            $node['image'] = $image;
        }

        $node['brand'] = array(
            '@type' => 'Brand',
            'name'  => self::brand_name(),
        );

        $offers = self::offers($post_id, $url);
        if (!empty($offers)) {
            $node['offers'] = $offers;
        }

        $rating = EDD::rating($post_id);
        if (is_array($rating)) {
            $node['aggregateRating'] = array(
                '@type'       => 'AggregateRating',
                'ratingValue' => $rating['value'],
                'reviewCount' => (int) $rating['count'],
                'bestRating'  => '5',
                'worstRating' => '1',
            );
        }

        $category = self::category_name($post_id);
        if ('' !== $category) {
            $node['category'] = $category;
        }

        /**
         * Filter the Product node.
         *
         * @param array   $node
         * @param int     $post_id
         * @param Context $context
         */
        return (array) apply_filters('sightline_product_schema', $node, $post_id, $context);
    }

    /**
     * One Offer per price tier.
     *
     * A single-tier product still gets an array, so consumers do not have to
     * handle two shapes, and a tier added later does not change the shape of
     * the output.
     *
     * @param int    $post_id
     * @param string $url
     * @return array
     */
    private static function offers($post_id, $url)
    {
        $tiers = EDD::price_tiers($post_id);
        if (empty($tiers)) {
            return array();
        }

        $currency = EDD::currency();
        $valid_until = self::price_valid_until();
        $offers = array();

        foreach ($tiers as $index => $tier) {
            $offer = array(
                '@type'         => 'Offer',
                'price'         => $tier['amount'],
                'priceCurrency' => $currency,
                // A download is never out of stock. Saying so explicitly is
                // the point: an Offer with no availability is the single
                // most common reason Google declines a product rich result,
                // and it was missing from all 34 products here.
                'availability'  => 'https://schema.org/InStock',
                'itemCondition' => 'https://schema.org/NewCondition',
                'url'           => $url,
            );

            if ('' !== $tier['name']) {
                $offer['name'] = $tier['name'];
                // Each tier needs its own identity, or two Offers on one
                // page are indistinguishable to a consumer.
                $offer['@id'] = $url . '#offer-' . ($index + 1);
            }

            if ('' !== $valid_until) {
                $offer['priceValidUntil'] = $valid_until;
            }

            $offers[] = $offer;
        }

        return $offers;
    }

    /**
     * The date after which a price should no longer be trusted.
     *
     * Google warns when this is absent, and a date in the past is worse than
     * none at all -- so it is always computed forward from today rather than
     * stored, and can be switched off by setting the interval to 0.
     *
     * @return string
     */
    private static function price_valid_until()
    {
        $months = (int) Options::get('schema/price_valid_months', 12);
        if ($months <= 0) {
            return '';
        }

        return gmdate('Y-m-d', strtotime('+' . $months . ' months'));
    }

    /**
     * The brand a product is sold under.
     *
     * @return string
     */
    private static function brand_name()
    {
        $name = trim((string) Options::get('organization/name', ''));
        if ('' === $name) {
            $name = (string) get_bloginfo('name');
        }

        return Text::plain($name);
    }

    /**
     * The product's primary category name, when it has one.
     *
     * @param int $post_id
     * @return string
     */
    private static function category_name($post_id)
    {
        $taxonomy = \Sightline\SEO\Terms::trail_taxonomy(get_post_type($post_id));
        if ('' === $taxonomy) {
            return '';
        }

        $term = \Sightline\SEO\Terms::primary($post_id, $taxonomy);

        return $term instanceof \WP_Term ? Text::plain($term->name) : '';
    }
}
