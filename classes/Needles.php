<?php

/**
 * @file plugins/generic/blindReviewGuard/classes/Needles.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Needles
 *
 * @brief Matching of identifying strings inside a piece of text.
 */

namespace APP\plugins\generic\blindReviewGuard\classes;

class Needles
{
    /**
     * Return the needles that occur in the haystack.
     *
     * Names are matched on letter boundaries so that "Sousa" does not match
     * "Sousada"; the boundary is expressed in Unicode letter classes rather than
     * \b, because \b treats an accented letter as a boundary and would produce
     * false positives on every "José" in the text.
     *
     * @param string[] $needles
     *
     * @return string[] The needles that were found, in the order given
     */
    public static function findIn(string $haystack, array $needles): array
    {
        if ($haystack === '') {
            return [];
        }

        $found = [];
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/ui';
            if (preg_match($pattern, $haystack) === 1) {
                $found[] = $needle;
            }
        }

        return $found;
    }

    /**
     * Turn an XML fragment into readable text.
     *
     * Two opposite mistakes have to be avoided at once, and a naive strip_tags()
     * makes both:
     *
     *   - Word splits a single word across runs after any edit
     *     (<w:t>Mari</w:t> ... <w:t>a Souza</w:t>), so joining runs with a space
     *     would hide the very name we are looking for;
     *   - consecutive blocks carry no whitespace between them, so concatenating
     *     everything glues the end of one block to the start of the next
     *     ("...@ufxx.brTrecho"), and a name or an address stops matching on its
     *     word boundary. This one was found by running the plugin against a real
     *     submission, not by reading the code.
     *
     * So: take the content of the text elements only (w:t, a:t, and the bare t of
     * SpreadsheetML), join them with nothing inside a paragraph, and separate
     * paragraphs with a space.
     */
    public static function xmlToText(string $xml): string
    {
        // Paragraph-level boundaries. Tabs and breaks are real spaces in the text.
        $blocks = preg_split('#</(?:w:p|a:p|text:p)>|<(?:w:br|w:tab|a:br)\b[^>]*/?>#i', $xml) ?: [$xml];

        $paragraphs = [];
        foreach ($blocks as $block) {
            if (preg_match_all('#<(?:w:t|a:t|t)(?:\s[^>]*)?>(.*?)</(?:w:t|a:t|t)>#si', $block, $matches)) {
                $paragraphs[] = implode('', $matches[1]);
            } elseif (!str_contains($block, '<')) {
                // A fragment with no markup at all: keep it as it is, so the
                // helper still works on plain strings. Anything holding markup
                // but no text element - a run of closing tags at the end of a
                // table, for instance - contributes nothing.
                $paragraphs[] = $block;
            }
        }

        $text = implode(' ', $paragraphs);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
