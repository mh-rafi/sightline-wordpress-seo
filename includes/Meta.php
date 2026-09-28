<?php

namespace Sightline\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The per-object keys, and the only code that reads or writes them.
 *
 * Sightline owns every key it writes and reads no other plugin's. That is
 * the first principle in the spec, and it is there because this store spent
 * years emitting an empty description on every product page: the theme read
 * `_yoast_wpseo_metadesc` long after Yoast was replaced, a key with zero
 * rows, and nothing reported it. Data arrives once through the migrator and
 * then belongs here.
 *
 * The same field names are used across post meta, term meta and user meta
 * (spec section 4) so the editing UI and the output layer can address a
 * field without caring which table it came from.
 */
class Meta
{
    const PREFIX = '_sightline_';

    /**
     * The fields an object can carry, and how each is stored.
     *
     * `array` fields are stored as arrays; everything else as strings.
     *
     * @return array<string,string>
     */
    public static function fields()
    {
        return array(
            'title'          => 'string',
            'description'    => 'string',
            'robots'         => 'array',
            'canonical'      => 'string',
            'og_title'       => 'string',
            'og_description' => 'string',
            'og_image'       => 'string',
            'focus_keyword'  => 'string',
        );
    }

    /**
     * Full meta key for a field.
     *
     * @param string $field
     * @return string
     */
    public static function key($field)
    {
        return self::PREFIX . $field;
    }

    /**
     * Read one field for one object.
     *
     * @param string $type      Context::POST, ::TERM or ::USER.
     * @param int    $object_id
     * @param string $field
     * @return mixed '' or array() when unset.
     */
    public static function get($type, $object_id, $field)
    {
        $object_id = (int) $object_id;
        if ($object_id <= 0) {
            return self::empty_value($field);
        }

        $key = self::key($field);

        switch ($type) {
            case Context::POST:
                $value = get_post_meta($object_id, $key, true);
                break;
            case Context::TERM:
                $value = get_term_meta($object_id, $key, true);
                break;
            case Context::USER:
                $value = get_user_meta($object_id, $key, true);
                break;
            default:
                return self::empty_value($field);
        }

        $fields = self::fields();
        if (isset($fields[$field]) && 'array' === $fields[$field]) {
            return is_array($value) ? $value : array();
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Write one field. An empty value deletes the row rather than storing
     * an empty string, so "unset" and "set to nothing" stay distinguishable
     * and an export does not carry noise.
     *
     * @param string $type
     * @param int    $object_id
     * @param string $field
     * @param mixed  $value
     * @return void
     */
    public static function set($type, $object_id, $field, $value)
    {
        $object_id = (int) $object_id;
        if ($object_id <= 0) {
            return;
        }

        $key = self::key($field);
        $fields = self::fields();
        $is_array = isset($fields[$field]) && 'array' === $fields[$field];

        if ($is_array) {
            $value = array_values(array_filter(array_map('sanitize_text_field', (array) $value)));
            $empty = empty($value);
        } else {
            $value = is_scalar($value) ? (string) $value : '';
            $empty = ('' === trim($value));
        }

        switch ($type) {
            case Context::POST:
                $empty ? delete_post_meta($object_id, $key) : update_post_meta($object_id, $key, $value);
                break;
            case Context::TERM:
                $empty ? delete_term_meta($object_id, $key) : update_term_meta($object_id, $key, $value);
                break;
            case Context::USER:
                $empty ? delete_user_meta($object_id, $key) : update_user_meta($object_id, $key, $value);
                break;
        }
    }

    /**
     * The primary term key for a taxonomy.
     *
     * Kept out of fields() because it is one key per taxonomy rather than a
     * fixed field, and only posts have it.
     *
     * @param string $taxonomy
     * @return string
     */
    public static function primary_term_key($taxonomy)
    {
        return self::PREFIX . 'primary_' . $taxonomy;
    }

    /**
     * The chosen primary term for a post in a taxonomy, or 0.
     *
     * @param int    $post_id
     * @param string $taxonomy
     * @return int
     */
    public static function primary_term($post_id, $taxonomy)
    {
        return (int) get_post_meta((int) $post_id, self::primary_term_key($taxonomy), true);
    }

    /**
     * Every meta key this plugin owns, for uninstall and export.
     *
     * @return string[]
     */
    public static function all_keys()
    {
        $keys = array();
        foreach (array_keys(self::fields()) as $field) {
            $keys[] = self::key($field);
        }
        foreach (get_taxonomies(array(), 'names') as $taxonomy) {
            $keys[] = self::primary_term_key($taxonomy);
        }
        return $keys;
    }

    /**
     * @param string $field
     * @return mixed
     */
    private static function empty_value($field)
    {
        $fields = self::fields();
        return (isset($fields[$field]) && 'array' === $fields[$field]) ? array() : '';
    }
}
