<?php

/**
 * @file plugins/generic/blindReviewGuard/classes/scanners/PdfScanner.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PdfScanner
 *
 * @brief Scans PDF files for identity leaks, in pure PHP.
 *
 * Two sources are read without any external binary:
 *
 *   - the document information dictionary (/Author, /Creator, /Title, /Subject,
 *     /Keywords), which is where Word puts the account name when it exports;
 *   - the XMP packet (dc:creator, pdf:Author), which survives operations that
 *     drop the /Info dictionary.
 *
 * The visible text is read on a best-effort basis: page content streams that are
 * FlateDecode-compressed are inflated and their text-showing operators are
 * extracted. That covers ordinary PDFs written by Word or LaTeX and misses
 * scanned images (no text at all) and exotic encodings. The report says so
 * rather than pretending the file is clean - see textExtractionReliable().
 *
 * The file is never rewritten: a PDF is a fragile container, and a broken
 * manuscript is worse than a metadata leak the editor was told about.
 */

namespace APP\plugins\generic\blindReviewGuard\classes\scanners;

use APP\plugins\generic\blindReviewGuard\classes\Finding;
use APP\plugins\generic\blindReviewGuard\classes\IdentityProfile;
use APP\plugins\generic\blindReviewGuard\classes\Needles;

class PdfScanner implements Scanner
{
    public const EXTENSIONS = ['pdf'];

    /** Identifying keys of the document information dictionary. */
    public const INFO_KEYS = ['Author', 'Creator', 'Title', 'Subject', 'Keywords'];

    /** Do not inflate more than this per stream, to bound memory on a web request. */
    public const MAX_STREAM_BYTES = 4194304;

    /** Streams beyond this are not inflated; the report flags the file as partially read. */
    public const MAX_STREAMS = 400;

    private bool $textReliable = true;

    public function handles(string $extension): bool
    {
        return in_array(strtolower($extension), self::EXTENSIONS, true);
    }

    /**
     * True when the text of the last scanned file could be read. False means the
     * metadata was checked but the body was not - the editor must still look.
     */
    public function textExtractionReliable(): bool
    {
        return $this->textReliable;
    }

    /**
     * @return Finding[]
     */
    public function scan(string $path, IdentityProfile $profile, array $checks): array
    {
        $this->textReliable = true;

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $findings = [];
        if ($checks['metadata'] ?? true) {
            $findings = array_merge($findings, $this->scanInfoDictionary($raw), $this->scanXmp($raw));
        }
        if ($checks['text'] ?? true) {
            $findings = array_merge($findings, $this->scanText($raw, $profile));
        }

        return OoxmlScanner::deduplicate($findings);
    }

    /**
     * @return Finding[]
     */
    private function scanInfoDictionary(string $raw): array
    {
        $findings = [];
        foreach (self::INFO_KEYS as $key) {
            // Literal strings: /Author (Maria Souza)
            if (preg_match_all('#/' . $key . '\s*\(((?:\\\\.|[^\\\\()])*)\)#s', $raw, $matches)) {
                foreach ($matches[1] as $value) {
                    $findings = $this->addIfIdentifying($findings, $key, self::decodeLiteral($value));
                }
            }
            // Hexadecimal strings: /Author <FEFF004D...>
            if (preg_match_all('#/' . $key . '\s*<([0-9A-Fa-f\s]+)>#s', $raw, $matches)) {
                foreach ($matches[1] as $value) {
                    $findings = $this->addIfIdentifying($findings, $key, self::decodeHex($value));
                }
            }
        }

        return $findings;
    }

    /**
     * @return Finding[]
     */
    private function scanXmp(string $raw): array
    {
        if (!preg_match('#<x:xmpmeta.*?</x:xmpmeta>#s', $raw, $packet)) {
            return [];
        }

        $findings = [];
        foreach (['dc:creator' => 'dc:creator', 'pdf:Author' => 'pdf:Author', 'dc:title' => 'dc:title'] as $tag => $label) {
            if (!preg_match_all('#<' . preg_quote($tag, '#') . '[^>]*>(.*?)</' . preg_quote($tag, '#') . '>#si', $packet[0], $matches)) {
                continue;
            }
            foreach ($matches[1] as $value) {
                $findings = $this->addIfIdentifying($findings, $label, Needles::xmlToText($value));
            }
        }

        return $findings;
    }

    /**
     * @return Finding[]
     */
    private function scanText(string $raw, IdentityProfile $profile): array
    {
        $needles = $profile->textNeedles();
        if (empty($needles)) {
            return [];
        }

        $text = $this->extractText($raw);
        if ($text === '') {
            $this->textReliable = false;
            return [];
        }

        $findings = [];
        foreach (Needles::findIn($text, $needles) as $match) {
            $findings[] = new Finding(Finding::TYPE_TEXT, 'pdf', $match, false);
        }

        return $findings;
    }

    /**
     * Inflate the FlateDecode streams and pull out what the text operators show.
     */
    private function extractText(string $raw): string
    {
        if (!preg_match_all('#stream\r?\n?(.*?)endstream#s', $raw, $streams)) {
            $this->textReliable = false;
            return '';
        }

        if (count($streams[1]) > self::MAX_STREAMS) {
            $this->textReliable = false;
            $streams[1] = array_slice($streams[1], 0, self::MAX_STREAMS);
        }

        $text = '';
        $inflatedAny = false;
        foreach ($streams[1] as $stream) {
            if (strlen($stream) > self::MAX_STREAM_BYTES) {
                $this->textReliable = false;
                continue;
            }
            $inflated = @gzuncompress($stream);
            if ($inflated === false) {
                $inflated = @gzinflate($stream);
            }
            if ($inflated === false) {
                continue;
            }
            $inflatedAny = true;
            $text .= ' ' . self::textFromContentStream($inflated);
        }

        if (!$inflatedAny) {
            $this->textReliable = false;
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Pull the strings out of the text-showing operators Tj and TJ.
     */
    public static function textFromContentStream(string $content): string
    {
        $out = '';
        if (preg_match_all('#\(((?:\\\\.|[^\\\\()])*)\)\s*(?:Tj|TJ|\')#s', $content, $matches)) {
            foreach ($matches[1] as $chunk) {
                $out .= self::decodeLiteral($chunk) . ' ';
            }
        }
        // TJ arrays: [(Mar) -20 (ia Souza)] TJ - the pieces above are captured
        // individually, which is why the caller collapses whitespace afterwards.
        if (preg_match_all('#\[((?:[^\[\]\\\\]|\\\\.)*)\]\s*TJ#s', $content, $arrays)) {
            foreach ($arrays[1] as $array) {
                if (preg_match_all('#\(((?:\\\\.|[^\\\\()])*)\)#s', $array, $pieces)) {
                    $out .= implode('', array_map([self::class, 'decodeLiteral'], $pieces[1])) . ' ';
                }
            }
        }

        return $out;
    }

    public static function decodeLiteral(string $value): string
    {
        $value = preg_replace('#\\\\([()\\\\])#', '$1', $value) ?? $value;
        $value = str_replace(['\\n', '\\r', '\\t'], [' ', ' ', ' '], $value);

        return trim($value);
    }

    public static function decodeHex(string $value): string
    {
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $value) ?? '';
        if ($hex === '' || strlen($hex) % 2 !== 0) {
            return '';
        }
        $bytes = hex2bin($hex);
        if ($bytes === false) {
            return '';
        }
        // UTF-16BE with byte order mark, which is how PDF writes non-ASCII names.
        if (str_starts_with($bytes, "\xFE\xFF")) {
            $converted = @mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
            return trim($converted === false ? '' : $converted);
        }

        return trim($bytes);
    }

    /**
     * @param Finding[] $findings
     *
     * @return Finding[]
     */
    private function addIfIdentifying(array $findings, string $where, string $value): array
    {
        if (OoxmlScanner::isIdentifying($value)) {
            $findings[] = new Finding(Finding::TYPE_DOCUMENT_PROPERTY, $where, $value, false);
        }

        return $findings;
    }
}
