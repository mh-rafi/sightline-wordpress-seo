<?php

namespace Sightline\SEO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turning stored content into the one-line strings SEO output needs.
 *
 * The three rules below were each a visible bug on the reference store
 * before they were rules (spec section 8), and they are the reason this
 * is a class rather than a call to wp_strip_all_tags():
 *
 *   1. A tag becomes a space, not nothing. Stripping tags outright welds
 *      the last word of one block to the first of the next, and the
 *      welded pair is what a search result then shows:
 *      "...E-commerce SaaSStocky SaaS is a complete..."
 *
 *   2. A bare URL is dropped as a whole token. A line holding nothing but
 *      a URL is an oEmbed -- a video, not a sentence -- and it is usually
 *      butted straight against the markup after it:
 *      "https://www.youtube.com/watch?v=GIaFNs-gs7UGet Mentor LMS..."
 *
 *   3. &nbsp; decodes to U+00A0, which PHP's \s does not match even with
 *      the /u modifier. It survives the whitespace collapse and the trim
 *      and surfaces as leading blank space in the description.
 *
 * R9 lives here too. Everything Sightline emits is decoded to plain text
 * exactly once, here, and encoded exactly once at the point of output --
 * esc_attr() for a meta tag, wp_json_encode() for JSON-LD. That is what
 * stops `&amp;` leaking into a JSON-LD name value, which is a JSON string
 * and not HTML.
 */
class Text
{
    /**
     * Plain, single-line, entity-free text.
     *
     * Safe to hand to esc_attr() or wp_json_encode(); do not run it through
     * either of those twice.
     *
     * @param string $text
     * @return string
     */
    public static function plain($text)
    {
        $text = (string) $text;
        if ('' === $text) {
            return '';
        }

        // Rule 1: a tag is a word boundary.
        $text = preg_replace('#<[^>]+>#', ' ', $text);

        // Decode once. ENT_QUOTES so &#039; and &quot; both resolve, and
        // ENT_HTML5 so &nbsp; and the newer named entities do too.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Rule 3: the non-breaking space is not whitespace to preg.
        $text = str_replace(array("\xC2\xA0", "\xE2\x80\x8B"), ' ', $text);

        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }

    /**
     * Plain text derived from post content, for use as a description.
     *
     * @param string $content Raw post_content.
     * @return string
     */
    public static function from_content($content)
    {
        $content = strip_shortcodes((string) $content);
        $content = excerpt_remove_blocks($content);

        // Rule 2, before tags collapse to spaces: while a bare URL still
        // sits on its own it can be recognised and removed as a token.
        $content = preg_replace('#^\s*https?://\S+\s*$#mu', ' ', $content);
        $content = preg_replace('#https?://\S+#u', ' ', $content);

        return self::plain($content);
    }

    /**
     * Cut to a length on a word boundary.
     *
     * @param string $text   Already plain.
     * @param int    $length Maximum characters, 0 for no limit.
     * @return string
     */
    public static function truncate($text, $length = 160)
    {
        $text = (string) $text;
        if ($length <= 0 || mb_strlen($text) <= $length) {
            return $text;
        }

        $text = mb_substr($text, 0, $length);
        $space = mb_strrpos($text, ' ');
        // Only back up to a word boundary if one exists late enough that the
        // result is still a description rather than a fragment.
        if (false !== $space && $space > (int) ($length / 4)) {
            $text = mb_substr($text, 0, $space);
        }

        // preg, not rtrim(): rtrim's character list is a set of *bytes*, so
        // trimming an em dash there can strip one byte of a multi-byte
        // sequence and leave broken UTF-8 behind. Every string that reaches
        // here is UTF-8 body copy, so this is not hypothetical.
        return preg_replace('/[\s,.;:\-\x{2013}\x{2014}]+$/u', '', $text) . '…';
    }

    /**
     * JSON-LD encoding, once.
     *
     * Slashes and unicode stay unescaped: both are legal in a JSON string
     * and escaping them makes the output unreadable in a source view for no
     * gain. Values reaching here must already be plain text.
     *
     * @param mixed $data
     * @return string
     */
    public static function json($data)
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return (string) wp_json_encode($data, $flags);
    }
}
