<?php

namespace Sightline\SEO\Admin;

use Sightline\SEO\Context;
use Sightline\SEO\Meta;
use Sightline\SEO\Text;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The editing controls, rendered once and reused by the post, term and user
 * screens.
 *
 * The same fields on all three because the storage model says so (spec
 * section 4): a term has a title and a description for the same reason a
 * post does, and an editor should not have to learn two interfaces.
 *
 * The character counters are not decoration. Of 34 published products on
 * the reference store, 7 descriptions exceed 160 characters and 4 titles
 * exceed 60 -- all of them written in a plugin that had counters. What it
 * did not have was anything showing the derived fallback, so an author
 * could not tell an inherited description from an authored one.
 */
class Fields
{
    /**
     * Render the whole panel for an object.
     *
     * @param string $type      Context::POST, ::TERM or ::USER.
     * @param int    $object_id
     * @param string $preview   Derived text shown as the placeholder.
     * @return void
     */
    public static function panel($type, $object_id, array $preview = array())
    {
        $preview = array_merge(array('title' => '', 'description' => ''), $preview);

        wp_nonce_field('sightline_save_meta', 'sightline_nonce');
        ?>
        <div class="sightline-panel">
            <p class="sightline-field">
                <label for="sightline_title"><strong><?php esc_html_e('Search engine title', 'sightline-seo'); ?></strong></label>
                <input type="text" class="widefat sightline-count" id="sightline_title" name="sightline[title]"
                       data-limit="60"
                       value="<?php echo esc_attr(Meta::get($type, $object_id, 'title')); ?>"
                       placeholder="<?php echo esc_attr($preview['title']); ?>">
                <span class="sightline-hint">
                    <?php esc_html_e('Leave empty to use the template for this content type. Tokens: %title%, %sitename%, %sep%, %term%, %excerpt%, %page%.', 'sightline-seo'); ?>
                </span>
            </p>

            <p class="sightline-field">
                <label for="sightline_description"><strong><?php esc_html_e('Meta description', 'sightline-seo'); ?></strong></label>
                <textarea class="widefat sightline-count" id="sightline_description" name="sightline[description]" rows="3"
                          data-limit="160"
                          placeholder="<?php echo esc_attr($preview['description']); ?>"><?php echo esc_textarea(Meta::get($type, $object_id, 'description')); ?></textarea>
                <span class="sightline-hint">
                    <?php esc_html_e('The greyed-out text is what will be used if you leave this empty.', 'sightline-seo'); ?>
                </span>
            </p>

            <fieldset class="sightline-field">
                <legend><strong><?php esc_html_e('Indexing', 'sightline-seo'); ?></strong></legend>
                <?php
                $robots = (array) Meta::get($type, $object_id, 'robots');
                $choices = array(
                    'noindex'      => __('No index — keep this out of search results', 'sightline-seo'),
                    'nofollow'     => __('No follow — do not pass link equity from this page', 'sightline-seo'),
                    'noarchive'    => __('No archive — no cached copy', 'sightline-seo'),
                    'nosnippet'    => __('No snippet — no text preview', 'sightline-seo'),
                    'noimageindex' => __('No image index', 'sightline-seo'),
                );
                foreach ($choices as $value => $label) {
                    printf(
                        '<label class="sightline-check"><input type="checkbox" name="sightline[robots][]" value="%s"%s> %s</label>',
                        esc_attr($value),
                        checked(in_array($value, $robots, true), true, false),
                        esc_html($label)
                    );
                }
                ?>
                <span class="sightline-hint">
                    <?php esc_html_e('Untick everything to fall back to the default for this content type.', 'sightline-seo'); ?>
                </span>
            </fieldset>

            <p class="sightline-field">
                <label for="sightline_canonical"><strong><?php esc_html_e('Canonical URL', 'sightline-seo'); ?></strong></label>
                <input type="url" class="widefat" id="sightline_canonical" name="sightline[canonical]"
                       value="<?php echo esc_attr(Meta::get($type, $object_id, 'canonical')); ?>"
                       placeholder="<?php esc_attr_e('Leave empty unless this page is a duplicate of another', 'sightline-seo'); ?>">
            </p>

            <details class="sightline-social">
                <summary><?php esc_html_e('Social sharing', 'sightline-seo'); ?></summary>

                <p class="sightline-field">
                    <label for="sightline_og_title"><?php esc_html_e('Social title', 'sightline-seo'); ?></label>
                    <input type="text" class="widefat" id="sightline_og_title" name="sightline[og_title]"
                           value="<?php echo esc_attr(Meta::get($type, $object_id, 'og_title')); ?>"
                           placeholder="<?php esc_attr_e('Defaults to the search engine title', 'sightline-seo'); ?>">
                </p>

                <p class="sightline-field">
                    <label for="sightline_og_description"><?php esc_html_e('Social description', 'sightline-seo'); ?></label>
                    <textarea class="widefat" id="sightline_og_description" name="sightline[og_description]" rows="2"
                              placeholder="<?php esc_attr_e('Defaults to the meta description', 'sightline-seo'); ?>"><?php echo esc_textarea(Meta::get($type, $object_id, 'og_description')); ?></textarea>
                </p>

                <?php
                $og_image = Meta::get($type, $object_id, 'og_image');
                self::image_picker('sightline[og_image]', $og_image, __('Social image', 'sightline-seo'));
                ?>
            </details>

            <p class="sightline-field">
                <label for="sightline_focus_keyword"><?php esc_html_e('Focus keyword', 'sightline-seo'); ?></label>
                <input type="text" class="widefat" id="sightline_focus_keyword" name="sightline[focus_keyword]"
                       value="<?php echo esc_attr(Meta::get($type, $object_id, 'focus_keyword')); ?>">
                <span class="sightline-hint">
                    <?php esc_html_e('Editorial only. Nothing on the front end renders this — it is a note to yourself about what this page is for.', 'sightline-seo'); ?>
                </span>
            </p>
        </div>
        <?php
    }

    /**
     * An attachment picker storing an attachment ID.
     *
     * @param string $name
     * @param string $value
     * @param string $label
     * @return void
     */
    public static function image_picker($name, $value, $label)
    {
        $id  = ctype_digit((string) $value) ? (int) $value : 0;
        $src = $id ? wp_get_attachment_image_url($id, 'medium') : (('' !== $value) ? $value : '');
        ?>
        <div class="sightline-field sightline-image-picker">
            <label><?php echo esc_html($label); ?></label>
            <div class="sightline-image-preview"><?php if ($src) { printf('<img src="%s" alt="">', esc_url($src)); } ?></div>
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" class="sightline-image-value">
            <button type="button" class="button sightline-image-choose"><?php esc_html_e('Choose', 'sightline-seo'); ?></button>
            <button type="button" class="button-link sightline-image-clear"<?php echo $src ? '' : ' hidden'; ?>><?php esc_html_e('Remove', 'sightline-seo'); ?></button>
        </div>
        <?php
    }

    /**
     * Read the submitted panel and write it.
     *
     * @param string $type
     * @param int    $object_id
     * @return void
     */
    public static function save($type, $object_id)
    {
        if (!isset($_POST['sightline']) || !is_array($_POST['sightline'])) {
            return;
        }

        $input = wp_unslash($_POST['sightline']);

        foreach (array_keys(Meta::fields()) as $field) {
            if ('robots' === $field) {
                Meta::set($type, $object_id, 'robots', isset($input['robots']) ? (array) $input['robots'] : array());
                continue;
            }

            $value = isset($input[$field]) ? $input[$field] : '';

            if ('canonical' === $field) {
                $value = esc_url_raw((string) $value);
            } elseif ('description' === $field || 'og_description' === $field) {
                $value = sanitize_textarea_field((string) $value);
            } else {
                $value = sanitize_text_field((string) $value);
            }

            Meta::set($type, $object_id, $field, $value);
        }

        // The primary term, which only posts have.
        if (Context::POST === $type && isset($input['primary_term']) && is_array($input['primary_term'])) {
            foreach ($input['primary_term'] as $taxonomy => $term_id) {
                $taxonomy = sanitize_key($taxonomy);
                $term_id  = (int) $term_id;
                if (!taxonomy_exists($taxonomy)) {
                    continue;
                }
                if ($term_id > 0) {
                    update_post_meta($object_id, Meta::primary_term_key($taxonomy), $term_id);
                } else {
                    delete_post_meta($object_id, Meta::primary_term_key($taxonomy));
                }
            }
        }
    }

    /**
     * @param string $text
     * @return string
     */
    public static function preview($text)
    {
        return Text::truncate(Text::plain($text), 200);
    }
}
