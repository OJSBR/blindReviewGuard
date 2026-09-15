<?php

/**
 * @file plugins/generic/blindReviewGuard/classes/OoxmlCleaner.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
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
 * The source is never written. The cleaned package is written to a separate
 * target, because in OJS the stored file of a review copy is usually the very
 * same file as the author's upload: rewriting it in place would clean the
 * original too. Storing the target is the caller's job.
 */

namespace APP\plugins\generic\blindReviewGuard\classes;

use APP\plugins\generic\blindReviewGuard\classes\scanners\OoxmlScanner;
use ZipArchive;

class OoxmlCleaner
{
    /** The value written in place of a person's name. */
    public const ANONYMOUS = 'Author';

    /**
     * Write a cleaned copy of $source to $target.
     *
     * @return Finding[] The findings that were removed. When nothing was
     *                   removed, or the copy could not be written, the result is
     *                   empty and no target is left behind.
     */
    public function clean(string $source, string $target): array
    {
        if ($source === $target || !@copy($source, $target)) {
            return [];
        }

        $zip = new ZipArchive();
        if ($zip->open($target) !== true) {
            @unlink($target);
            return [];
        }

        $removed = [];
        $closed = false;
        try {
            $removed = array_merge($removed, $this->cleanProperties($zip));
            $removed = array_merge($removed, $this->cleanAuthorAttributes($zip));
        } finally {
            $closed = $zip->close();
        }

        if (empty($removed) || !$closed) {
            @unlink($target);
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
            $xml = OoxmlScanner::readPart($zip, $part);
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
            $xml = OoxmlScanner::readPart($zip, $part);
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
