<?php

namespace Sightline\SEO\Import;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Translating another plugin's template tokens into Sightline's.
 *
 * One syntax reaches storage (spec section 4). Rank Math writes `%title%`
 * and Yoast writes `%%title%%`; both are normalised here so nothing
 * downstream has to know which plugin a stored template came from.
 *
 * A token with no equivalent is removed *and reported*, not silently left
 * in place. Left in place it renders literally in a browser tab; removed
 * quietly it changes a title nobody was told about. The importer's report
 * is where the difference gets said out loud.
 */
class Tokens
{
    /**
     * Tokens whose name differs but whose meaning does not.
     *
     * @return array<string,string>
     */
    public static function aliases()
    {
        return array(
            'seo_title'       => 'title',
            'seo_description' => 'excerpt',
            'pagename'        => 'title',
            'page_title'      => 'title',
            'category'        => 'primary_category',
            'categories'      => 'primary_category',
            'sitedesc'        => 'sitedesc',
            'sitename'        => 'sitename',
            'post_author'     => 'name',
            'name'            => 'name',
            'user_description'=> 'user_description',
            'term'            => 'term',
            'term_description'=> 'term_description',
            'excerpt_only'    => 'excerpt',
            'wc_brand'        => '',
            'wc_price'        => '',
            'wc_shortdesc'    => 'excerpt',
            'wc_sku'          => '',
            'date'            => 'date',
            'modified'        => 'modified',
            'currentdate'     => 'currentdate',
            'currentday'      => 'currentdate',
            'currentmonth'    => 'currentmonth',
            'currentyear'     => 'currentyear',
            'org_name'        => 'sitename',
            'parent_title'    => 'parent_title',
            'pt_plural'       => 'pt_plural',
            'pt_singular'     => 'pt_singular',
            'page'            => 'page',
            'pagenumber'      => 'pagenumber',
            'sep'             => 'sep',
            'title'           => 'title',
            'excerpt'         => 'excerpt',
            'search_query'    => 'search_query',
            'id'              => 'id',
        );
    }

    /**
     * Translate a stored template.
     *
     * @param string      $template
     * @param Report|null $report For the tokens that could not be carried.
     * @param string      $subject What is being translated, for the report.
     * @return string
     */
    public static function translate($template, $report = null, $subject = '')
    {
        $template = (string) $template;
        if ('' === $template) {
            return '';
        }

        // Yoast's doubled syntax first, so the single-percent pass below
        // sees one shape.
        $template = preg_replace('/%%([a-z0-9_]+(?:\([^)]*\))?)%%/i', '%$1%', $template);

        $aliases = self::aliases();

        return (string) preg_replace_callback(
            '/%([a-z0-9_]+)(\([^)]*\))?%/i',
            static function ($m) use ($aliases, $report, $subject) {
                $name = strtolower($m[1]);
                $has_argument = !empty($m[2]);

                // %customfield(price)% and friends. Sightline has no
                // equivalent, and inventing one during an import would be
                // guessing at what the site meant.
                if ($has_argument) {
                    if ($report instanceof Report) {
                        $report->skipped(
                            sprintf(
                                /* translators: %s: a template token name. */
                                __('Template token "%s" has no equivalent and was removed', 'sightline-seo'),
                                $name . $m[2]
                            ),
                            $subject
                        );
                    }
                    return '';
                }

                if (isset($aliases[$name])) {
                    return '' === $aliases[$name] ? '' : '%' . $aliases[$name] . '%';
                }

                if ($report instanceof Report) {
                    $report->skipped(
                        sprintf(
                            /* translators: %s: a template token name. */
                            __('Template token "%s" has no equivalent and was removed', 'sightline-seo'),
                            '%' . $name . '%'
                        ),
                        $subject
                    );
                }

                return '';
            },
            $template
        );
    }
}
