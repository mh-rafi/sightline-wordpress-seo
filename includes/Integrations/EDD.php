<?php

namespace Sightline\SEO\Integrations;

use Sightline\SEO\Options;
use Sightline\SEO\Text;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Everything Sightline knows about Easy Digital Downloads, in one place.
 *
 * Store-aware, not store-dependent (spec section 2). Every method here
 * answers safely when EDD is absent: `is_active()` is false, the product
 * post type matches nothing, the page list is empty, and the rest of the
 * plugin degrades to an ordinary SEO plugin with no branches of its own.
 *
 * Keeping the detection here rather than scattering `function_exists()`
 * through the schema and robots code is what makes that claim checkable.
 */
class EDD
{
    /**
     * Is a supported store plugin present?
     *
     * @return bool
     */
    public static function is_active()
    {
        return class_exists('Easy_Digital_Downloads') || function_exists('edd_get_currency');
    }

    /**
     * Is this post type the store's product?
     *
     * @param string $post_type
     * @return bool
     */
    public static function is_product_post_type($post_type)
    {
        $is = self::is_active() && 'download' === $post_type;

        /**
         * Filter whether a post type is treated as a product.
         *
         * @param bool   $is
         * @param string $post_type
         */
        return (bool) apply_filters('sightline_is_product_post_type', $is, $post_type);
    }

    /**
     * The store's transactional pages, as post IDs.
     *
     * R8: detected through EDD's own page settings, never by slug. A slug
     * list looks like it works until a site renames a page or runs in
     * another language, and then it silently indexes a checkout.
     *
     * Pages the store does not record in its settings -- an account page or
     * a registration page belonging to the theme -- are added by hand in
     * Sightline's settings instead of guessed at.
     *
     * @return int[]
     */
    public static function transactional_page_ids()
    {
        $ids = array();

        if (self::is_active() && function_exists('edd_get_option')) {
            foreach (self::page_setting_keys() as $key) {
                $id = (int) edd_get_option($key, 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        foreach ((array) Options::get('store/extra_noindex_pages', array()) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));

        /**
         * Filter the pages treated as transactional.
         *
         * @param int[] $ids
         */
        return array_map('intval', (array) apply_filters('sightline_transactional_page_ids', $ids));
    }

    /**
     * The EDD settings keys that hold a page ID.
     *
     * `login_redirect_page` is deliberately absent: it points at wherever a
     * customer lands after logging in, which is often an ordinary page that
     * should stay indexed.
     *
     * @return string[]
     */
    public static function page_setting_keys()
    {
        return (array) apply_filters('sightline_edd_page_setting_keys', array(
            'purchase_page',
            'success_page',
            'failure_page',
            'purchase_history_page',
            'confirmation_page',
            'login_page',
        ));
    }

    /**
     * A human label for one of those pages, for the settings screen.
     *
     * @return array<string,string>
     */
    public static function page_setting_labels()
    {
        return array(
            'purchase_page'         => __('Checkout', 'sightline-seo'),
            'success_page'          => __('Purchase confirmation', 'sightline-seo'),
            'failure_page'          => __('Transaction failed', 'sightline-seo'),
            'purchase_history_page' => __('Purchase history', 'sightline-seo'),
            'confirmation_page'     => __('Order confirmation', 'sightline-seo'),
            'login_page'            => __('Login', 'sightline-seo'),
        );
    }

    /**
     * The store's currency code.
     *
     * @return string
     */
    public static function currency()
    {
        return function_exists('edd_get_currency') ? (string) edd_get_currency() : 'USD';
    }

    /**
     * The identifier to advertise as `sku`.
     *
     * R4: Rank Math emits the literal string "-" on every product on the
     * reference store, which is a value Google reads as a real SKU. EDD's
     * own field is used when set to something meaningful; otherwise the post
     * ID stands in, which is stable, unique, and already what order records
     * refer to.
     *
     * @param int $post_id
     * @return string
     */
    public static function sku($post_id)
    {
        $post_id = (int) $post_id;

        // The store may already own this. Prefer its answer so the page does
        // not advertise two different SKUs, which is exactly what happened
        // here before: microdata said 88861 and the JSON-LD said "-".
        if (function_exists('ul_product_sku')) {
            $sku = trim((string) ul_product_sku($post_id));
            if ('' !== $sku && '-' !== $sku) {
                return $sku;
            }
        }

        $sku = trim((string) get_post_meta($post_id, 'edd_sku', true));
        if ('' !== $sku && '-' !== $sku) {
            return $sku;
        }

        return (string) $post_id;
    }

    /**
     * One entry per price tier: name, amount.
     *
     * A variable-priced product has as many Offers as it has tiers.
     * Advertising only the lowest describes a $24 product whose extended
     * licence is $129.
     *
     * @param int $post_id
     * @return array<int,array{name:string,amount:string}>
     */
    public static function price_tiers($post_id)
    {
        $post_id = (int) $post_id;
        $tiers   = array();

        if (function_exists('edd_has_variable_prices') && edd_has_variable_prices($post_id)) {
            foreach ((array) edd_get_variable_prices($post_id) as $key => $price) {
                $amount = edd_get_price_option_amount($post_id, $key);
                if ('' === $amount || null === $amount) {
                    continue;
                }
                $tiers[] = array(
                    'name'   => isset($price['name']) ? Text::plain($price['name']) : '',
                    'amount' => self::amount($amount),
                );
            }
        } elseif (function_exists('edd_get_download_price')) {
            $amount = edd_get_download_price($post_id);
            if ('' !== $amount && null !== $amount) {
                $tiers[] = array('name' => '', 'amount' => self::amount($amount));
            }
        }

        /**
         * Filter the price tiers a product advertises.
         *
         * @param array $tiers
         * @param int   $post_id
         */
        return (array) apply_filters('sightline_price_tiers', $tiers, $post_id);
    }

    /**
     * This store's own rating for a product, or null.
     *
     * Store reviews only. A marketplace average folded in here would claim
     * reviews the page cannot show, which is the same rule the product page
     * follows when it keeps its two proof rows apart (spec section 10).
     *
     * @param int $post_id
     * @return array{value:string,count:int}|null
     */
    public static function rating($post_id)
    {
        $post_id = (int) $post_id;
        $rating  = null;

        // ul_get_product_proof() already separates store figures from
        // marketplace ones; taking its `store` branch is how that separation
        // is inherited rather than re-implemented.
        if (function_exists('ul_get_product_proof')) {
            $proof = ul_get_product_proof($post_id);
            if (!empty($proof['store']['rating']) && !empty($proof['store']['count'])) {
                $rating = array(
                    'value' => (string) $proof['store']['rating'],
                    'count' => (int) $proof['store']['count'],
                );
            }
        } elseif (function_exists('edd_reviews')) {
            $average = edd_reviews()->average_rating(false, $post_id);
            $count   = method_exists(edd_reviews(), 'count_reviews') ? (int) edd_reviews()->count_reviews($post_id) : 0;
            if ($average && $count > 0) {
                $rating = array(
                    'value' => number_format((float) $average, 2, '.', ''),
                    'count' => $count,
                );
            }
        }

        /**
         * Filter the aggregate rating. Return null to emit none.
         *
         * @param array|null $rating
         * @param int        $post_id
         */
        return apply_filters('sightline_aggregate_rating', $rating, $post_id);
    }

    /**
     * Hand-over: switch off another plugin's Product microdata.
     *
     * Two Product entities describing the same page is worse than either
     * one alone. The store exposes `ul_product_microdata_enabled` for
     * exactly this, so taking over is one filter rather than an edit
     * (spec section 8). Guarded on the store's own function so this does
     * nothing on any other site.
     *
     * @return void
     */
    public static function take_over_microdata()
    {
        if (!Options::get('store/take_over_microdata', true)) {
            return;
        }
        if (!Options::get('schema/product', true)) {
            return;
        }
        if (!function_exists('ul_product_microdata')) {
            return;
        }

        add_filter('ul_product_microdata_enabled', '__return_false');
    }

    /**
     * Hand-over: stop the store printing a second robots tag.
     *
     * EDD writes `<meta name="robots" content="noindex,nofollow">` straight
     * into wp_head on its checkout, confirmation, history and failure
     * pages. Sightline emits `noindex, follow` on the same pages (R8), so
     * without this the page carries two robots tags that disagree -- and a
     * crawler resolving a conflict takes the more restrictive one, which
     * strands the link equity flowing through a checkout to the rest of the
     * store. Exactly the outcome R8 says "follow, not nofollow" to avoid.
     *
     * EDD's callback also prints the `edd-chosen-gateway` meta, which its
     * own checkout JavaScript reads, so the tag is replaced rather than
     * removed -- the same guard, the same gateway meta, one fewer robots
     * tag. If a future EDD renames the callback this finds nothing and
     * changes nothing, and the duplicate simply comes back.
     *
     * @return void
     */
    public static function suppress_duplicate_robots()
    {
        if (!Options::get('store/noindex_transactional', true)) {
            return;
        }
        if (!has_action('wp_head', 'edd_checkout_meta_tags')) {
            return;
        }

        remove_action('wp_head', 'edd_checkout_meta_tags');
        add_action('wp_head', array(__CLASS__, 'checkout_gateway_meta'));
    }

    /**
     * EDD's checkout meta, minus the robots tag Sightline already owns.
     *
     * @return void
     */
    public static function checkout_gateway_meta()
    {
        if (!function_exists('edd_is_checkout') || !function_exists('edd_get_option')) {
            return;
        }

        $pages = array(
            edd_get_option('success_page'),
            edd_get_option('failure_page'),
            edd_get_option('purchase_history_page'),
        );

        if (!edd_is_checkout() && !is_page(array_filter($pages))) {
            return;
        }

        if (function_exists('edd_get_chosen_gateway')) {
            printf('<meta name="edd-chosen-gateway" content="%s" />' . "\n", esc_attr(edd_get_chosen_gateway()));
        }
    }

    /**
     * Format an amount the way schema.org wants it: a plain decimal number,
     * no thousands separator and no currency symbol.
     *
     * edd_format_amount() honours the store's display settings, which on a
     * European store produces "1.234,50" -- valid to a reader and invalid
     * as a schema.org price.
     *
     * @param mixed $amount
     * @return string
     */
    private static function amount($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
