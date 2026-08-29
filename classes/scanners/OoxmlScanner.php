<?php

/**
 * @file plugins/generic/blindReviewGuard/classes/scanners/OoxmlScanner.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OoxmlScanner
 *
 * @brief Scans Office Open XML files (.docx, .xlsx, .pptx) for identity leaks.
 *
 * OOXML is a zip of XML parts, so everything below is done with ZipArchive and
 * plain string handling - no external binary, no library. Three places leak, in
 * this order of frequency:
 *
 *   1. docProps/core.xml and docProps/app.xml carry the account name of whoever
 *      created and last saved the file. Practically every manuscript has this,
 *      and practically nobody looks.
 *   2. Tracked changes and comments carry the name of whoever made each mark,
 *      in w:author attributes spread over document.xml, comments.xml and
 *      people.xml. Accepting all changes does not remove the comments, and
 *      removing the comments does not remove people.xml.
 *   3. The visible text: the cover page, the corresponding author's e-mail, the
 *      funding statement.
 */

namespace APP\plugins\generic\blindReviewGuard\classes\scanners;

use APP\plugins\generic\blindReviewGuard\classes\Finding;
use APP\plugins\generic\blindReviewGuard\classes\IdentityProfile;
use APP\plugins\generic\blindReviewGuard\classes\Needles;
use ZipArchive;

class OoxmlScanner implements Scanner
{
    public const EXTENSIONS = ['docx', 'docm', 'xlsx', 'xlsm', 'pptx', 'pptm'];

    /**
     * Document properties that identify a person. Kept explicit rather than
     * "everything in core.xml", so that dates and revision counts stay out of
     * the report.
     */
    public const IDENTIFYING_PROPERTIES = [
        'dc:creator' => 'dc:creator',
        'cp:lastModifiedBy' => 'cp:lastModifiedBy',
        'dc:contributor' => 'dc:contributor',
        'Company' => 'Company',
        'Manager' => 'Manager',
    ];

    /**
     * Values Word itself writes when a document has already been anonymised.
     * Reporting them would train editors to ignore the report.
     */
    public const NEUTRAL_VALUES = ['author', 'anonymous', 'anónimo', 'anonimo', 'user', 'usuario', 'usuário', 'windows user', 'microsoft office user', 'unknown'];

    /** Text parts worth reading, per format. */
    public const TEXT_PARTS = [
        'word/document.xml',
        'word/footnotes.xml',
        'word/endnotes.xml',
        'word/header1.xml',
        'word/header2.xml',
        'word/header3.xml',
        'word/footer1.xml',
        'word/footer2.xml',
        'word/footer3.xml',
        'xl/sharedStrings.xml',
    ];

    public function handles(string $extension): bool
    {
        return in_array(strtolower($extension), self::EXTENSIONS, true);
    }

    /**
     * @return Finding[]
     */
    public function scan(string $path, IdentityProfile $profile, array $checks): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        $findings = [];
        try {
            if ($checks['metadata'] ?? true) {
                $findings = array_merge($findings, $this->scanProperties($zip));
            }
            if ($checks['revisionMarks'] ?? true) {
                $findings = array_merge($findings, $this->scanAuthorAttributes($zip));
            }
            if ($checks['text'] ?? true) {
                $findings = array_merge($findings, $this->scanText($zip, $profile));
            }
        } finally {
            $zip->close();
        }

        return self::deduplicate($findings);
    }

    /**
     * @return Finding[]
     */
    private function scanProperties(ZipArchive $zip): array
    {
        $findings = [];
        foreach (['docProps/core.xml', 'docProps/app.xml'] as $part) {
            $xml = $zip->getFromName($part);
            if ($xml === false) {
                continue;
            }
            foreach (self::IDENTIFYING_PROPERTIES as $tag => $label) {
                if (!preg_match_all('#<' . preg_quote($tag, '#') . '[^>]*>(.*?)</' . preg_quote($tag, '#') . '>#si', $xml, $matches)) {
                    continue;
                }
                foreach ($matches[1] as $raw) {
                    $value = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    if (!self::isIdentifying($value)) {
                        continue;
                    }
                    $findings[] = new Finding(Finding::TYPE_DOCUMENT_PROPERTY, $label, $value, true);
                }
            }
        }

        return $findings;
    }

    /**
     * Tracked changes, comments and the Word 2013+ people part all use the same
     * w:author attribute, so one pass over every relevant part covers them.
     *
     * @return Finding[]
     */
    private function scanAuthorAttributes(ZipArchive $zip): array
    {
        $findings = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!preg_match('#^word/(document|comments|commentsExtended|people|footnotes|endnotes|header\d*|footer\d*)\.xml$#i', $name)) {
                continue;
            }
            $xml = $zip->getFromIndex($i);
            if ($xml === false) {
                continue;
            }
            if (!preg_match_all('#\sw:author="([^"]*)"#i', $xml, $matches)) {
                continue;
            }
            $isComments = stripos($name, 'comments') !== false;
            foreach (array_unique($matches[1]) as $raw) {
                $value = trim(html_entity_decode($raw, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if (!self::isIdentifying($value)) {
                    continue;
                }
                $findings[] = new Finding(
                    $isComments ? Finding::TYPE_COMMENT : Finding::TYPE_REVISION_MARK,
                    $name,
                    $value,
                    true
                );
            }
        }

        return $findings;
    }

    /**
     * @return Finding[]
     */
    private function scanText(ZipArchive $zip, IdentityProfile $profile): array
    {
        $needles = $profile->textNeedles();
        if (empty($needles)) {
            return [];
        }

        $findings = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $isSlide = (bool) preg_match('#^ppt/(slides|notesSlides)/[^/]+\.xml$#i', $name);
            if (!in_array($name, self::TEXT_PARTS, true) && !$isSlide) {
                continue;
            }
            $xml = $zip->getFromIndex($i);
            if ($xml === false) {
                continue;
            }
            $text = Needles::xmlToText($xml);
            foreach (Needles::findIn($text, $needles) as $match) {
                $findings[] = new Finding(Finding::TYPE_TEXT, $name, $match, false);
            }
        }

        return $findings;
    }

    /**
     * A value is evidence only if it is not empty and not one of the neutral
     * placeholders.
     */
    public static function isIdentifying(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return !in_array(mb_strtolower($value), self::NEUTRAL_VALUES, true);
    }

    /**
     * @param Finding[] $findings
     *
     * @return Finding[]
     */
    public static function deduplicate(array $findings): array
    {
        $unique = [];
        foreach ($findings as $finding) {
            $unique[$finding->key()] ??= $finding;
        }

        return array_values($unique);
    }
}
