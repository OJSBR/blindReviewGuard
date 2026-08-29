<?php

/**
 * @file plugins/generic/blindReviewGuard/classes/OoxmlCleaner.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OoxmlCleaner
 *
 * @brief Removes the identifying metadata of an OOXML file, leaving the content
 *        untouched.
 *
 * What it changes: the identifying document properties, and the author name on
 * every tracked change and comment. What it never changes: a single character of
 * the text. Rewriting the manuscript is the author's job and the editor's call;
 * silently editing a submission would be worse than the leak.
 *
 * The work is done on a copy and only then moved over the original, so an
 * interrupted run cannot leave a truncated manuscript behind.
 */

namespace APP\plugins\generic\blindReviewGuard\classes;

use APP\plugins\generic\blindReviewGuard\classes\scanners\OoxmlScanner;
use ZipArchive;

class OoxmlCleaner
{
    /** The value written in place of a person's name. */
    public const ANONYMOUS = 'Author';

    /**
     * Clean the file in place.
     *
     * @return Finding[] The findings that were removed (empty if nothing changed)
     */
    public function clean(string $path): array
    {
        $temp = $path . '.brg-tmp';
        if (!@copy($path, $temp)) {
            return [];
        }

        $zip = new ZipArchive();
        if ($zip->open($temp) !== true) {
            @unlink($temp);
            return [];
        }

        $removed = [];
        try {
            $removed = array_merge($removed, $this->cleanProperties($zip));
            $removed = array_merge($removed, $this->cleanAuthorAttributes($zip));
        } finally {
            $zip->close();
        }

        if (empty($removed)) {
            @unlink($temp);
            return [];
        }

        if (!@rename($temp, $path)) {
            @unlink($temp);
            return [];
        }

        return OoxmlScanner::deduplicate($removed);
    }

    /**
     * @return Finding[]
     */
    private function cleanProperties(ZipArchive $zip): array
    {
        $removed = [];
        foreach (['docProps/core.xml', 'docProps/app.xml'] as $part) {
            $xml = $zip->getFromName($part);
            if ($xml === false) {
                continue;
            }
            $original = $xml;
            foreach (OoxmlScanner::IDENTIFYING_PROPERTIES as $tag => $label) {
                $quoted = preg_quote($tag, '#');
                $xml = preg_replace_callback(
                    '#(<' . $quoted . '[^>]*>)(.*?)(</' . $quoted . '>)#si',
                    function (array $m) use ($label, &$removed) {
                        $value = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        if (!OoxmlScanner::isIdentifying($value)) {
                            return $m[0];
                        }
                        $removed[] = new Finding(Finding::TYPE_DOCUMENT_PROPERTY, $label, $value, true);

                        return $m[1] . $m[3];
                    },
                    $xml
                ) ?? $xml;
            }
            if ($xml !== $original) {
                $zip->addFromString($part, $xml);
            }
        }

        return $removed;
    }

    /**
     * @return Finding[]
     */
    private function cleanAuthorAttributes(ZipArchive $zip): array
    {
        $removed = [];
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^word/(document|comments|commentsExtended|people|footnotes|endnotes|header\d*|footer\d*)\.xml$#i', $name)) {
                $parts[] = $name;
            }
        }

        foreach ($parts as $part) {
            $xml = $zip->getFromName($part);
            if ($xml === false) {
                continue;
            }
            $isComments = stripos($part, 'comments') !== false;
            $original = $xml;
            $xml = preg_replace_callback(
                '#(\sw:author=")([^"]*)(")#i',
                function (array $m) use ($part, $isComments, &$removed) {
                    $value = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    if (!OoxmlScanner::isIdentifying($value)) {
                        return $m[0];
                    }
                    $removed[] = new Finding(
                        $isComments ? Finding::TYPE_COMMENT : Finding::TYPE_REVISION_MARK,
                        $part,
                        $value,
                        true
                    );

                    return $m[1] . self::ANONYMOUS . $m[3];
                },
                $xml
            ) ?? $xml;

            // Word 2013+ keeps a roster of commenters with their e-mail address.
            $xml = preg_replace('#\sw15:providerId="[^"]*"#i', '', $xml) ?? $xml;
            $xml = preg_replace('#(<w15:person\s+w15:author=")[^"]*(")#i', '$1' . self::ANONYMOUS . '$2', $xml) ?? $xml;

            if ($xml !== $original) {
                $zip->addFromString($part, $xml);
            }
        }

        return $removed;
    }
}
