<?php

namespace Sightline\SEO\Frontend;

use Sightline\SEO\Context;
use Sightline\SEO\Integrations\EDD;
use Sightline\SEO\Meta;
use Sightline\SEO\Options;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Indexation rules (R1, R8).
 *
 * Output goes through core's `wp_robots` filter rather than a printed meta
 * tag, so there is exactly one robots tag on the page and core's own
 * `max-image-preview:large` survives -- it is the single directive that
 * still stood after Rank Math was deactivated, and losing it would be a
 * regression introduced by the plugin meant to fix regressions.
 *
 * The store defaults are the R8 requirement: cart, checkout, account,
 * confirmation, history and registration pages are `noindex, follow` out of
 * the box. On the reference store all six were `index, follow` *and* in the
 * sitemap, with checkout additionally described as an Article with a Person
 * author. Every site should not have to discover that on its own.
 */
class Robots
{
    /**
     * @return void
     */
    public function register()
    {
        add_filter('wp_robots', array($this, 'filter_robots'), 20);
    }

    /**
     * @param array $robots
     * @return array
     */
    public function filter_robots($robots)
    {
        if (is_admin()) {
            return $robots;
        }

        $context = Context::current();
        $rules   = $this->rules($context);

        if (!empty($rules['noindex'])) {
            unset($robots['index']);
            $robots['noindex'] = true;
        } else {
            unset($robots['noindex']);
        }

        if (!empty($rules['nofollow'])) {
            unset($robots['follow']);
            $robots['nofollow'] = true;
        } else {
            unset($robots['nofollow']);
            // Stated rather than implied. "noindex" alone is read as
            // "noindex, follow" by every crawler that matters, but R8 asks
            // for the pair, and being explicit is what makes the output
            // checkable against the requirement with curl.
            if (!empty($rules['noindex'])) {
                $robots['follow'] = true;
            }
        }

        foreach (array('noarchive', 'nosnippet', 'noimageindex') as $directive) {
            if (!empty($rules[$directive])) {
                $robots[$directive] = true;
            }
        }

        /**
         * Filter the final robots directives.
         *
         * @param array   $robots
         * @param Context $context
         */
        return (array) apply_filters('sightline_robots', $robots, $context);
    }

    /**
     * The directives that apply to a context.
     *
     * @param Context $context
     * @return array<string,bool>
     */
    public function rules(Context $context)
    {
        $rules = array(
            'noindex'  => false,
            'nofollow' => false,
            'noarchive' => false,
            'nosnippet' => false,
            'noimageindex' => false,
        );

        switch ($context->type) {
            case Context::POST:
                $defaults = Options::post_type_defaults($context->subtype);
                $rules['noindex']  = !empty($defaults['noindex']);
                $rules['nofollow'] = !empty($defaults['nofollow']);

                if ($this->is_transactional($context)) {
                    // follow, not nofollow: these pages link to real content
                    // and there is no reason to strand that link equity.
                    $rules['noindex']  = true;
                    $rules['nofollow'] = false;
                }
                break;

            case Context::TERM:
                $defaults = Options::taxonomy_defaults($context->subtype);
                $rules['noindex']  = !empty($defaults['noindex']);
                $rules['nofollow'] = !empty($defaults['nofollow']);
                break;

            case Context::PT_ARCHIVE:
                $defaults = Options::archive_defaults($context->subtype);
                $rules['noindex']  = !empty($defaults['noindex']);
                $rules['nofollow'] = !empty($defaults['nofollow']);
                break;

            case Context::USER:
                $rules['noindex'] = (bool) Options::get('author/noindex', true);
                break;

            case Context::SEARCH:
                // Already correct on the reference store. Preserved here so
                // it stays correct rather than becoming correct by accident.
                $rules['noindex'] = (bool) Options::get('search/noindex', true);
                break;

            case Context::NOT_FOUND:
                $rules['noindex'] = true;
                break;
        }

        // A per-object choice is the editor's, and wins over every default.
        if ($context->has_object()) {
            $own = (array) Meta::get($context->meta_type(), $context->object_id, 'robots');
            if (!empty($own)) {
                $rules['noindex']  = in_array('noindex', $own, true);
                $rules['nofollow'] = in_array('nofollow', $own, true);
                foreach (array('noarchive', 'nosnippet', 'noimageindex') as $directive) {
                    $rules[$directive] = in_array($directive, $own, true);
                }
            }
        }

        return $rules;
    }

    /**
     * Is this one of the store's transactional pages?
     *
     * @param Context $context
     * @return bool
     */
    public function is_transactional(Context $context)
    {
        if (!Options::get('store/noindex_transactional', true)) {
            return false;
        }
        if (Context::POST !== $context->type) {
            return false;
        }

        return in_array((int) $context->object_id, EDD::transactional_page_ids(), true);
    }
}
