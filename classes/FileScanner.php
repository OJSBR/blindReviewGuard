<?php

/**
 * @file plugins/generic/blindReviewGuard/classes/FileScanner.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FileScanner
 *
 * @brief Picks the right scanner for a file, runs it, and optionally cleans.
 *
 * The whole class is free of OJS dependencies so that it can be exercised by the
 * test suite against real files, without a database or a request.
 */

namespace APP\plugins\generic\blindReviewGuard\classes;

use APP\plugins\generic\blindReviewGuard\classes\scanners\FilenameScanner;
use APP\plugins\generic\blindReviewGuard\classes\scanners\OoxmlScanner;
use APP\plugins\generic\blindReviewGuard\classes\scanners\PdfScanner;

class FileScanner
{
    public const DEFAULT_CHECKS = [
        'metadata' => true,
        'revisionMarks' => true,
        'text' => true,
        'filename' => true,
    ];

    public function __construct(
        private readonly OoxmlScanner $ooxml = new OoxmlScanner(),
        private readonly PdfScanner $pdf = new PdfScanner(),
        private readonly FilenameScanner $filename = new FilenameScanner(),
        private readonly OoxmlCleaner $cleaner = new OoxmlCleaner()
    ) {
    }

    /**
     * @param array $checks Which checks to run; see DEFAULT_CHECKS
     * @param bool $autoClean Remove what can be removed (OOXML metadata only).
     *                        The file at $path is never modified: the cleaned
     *                        copy is written next to it and its path returned in
     *                        ScanReport::$cleanedPath, for the caller to store
     *                        and then delete.
     */
    public function scan(string $path, string $filename, IdentityProfile $profile, array $checks = self::DEFAULT_CHECKS, bool $autoClean = false, ?int $submissionFileId = null): ScanReport
    {
        $checks += self::DEFAULT_CHECKS;
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $findings = [];
        $textReliable = true;

        if (is_readable($path)) {
            if ($this->ooxml->handles($extension)) {
                $findings = $this->ooxml->scan($path, $profile, $checks);
            } elseif ($this->pdf->handles($extension)) {
                $findings = $this->pdf->scan($path, $profile, $checks);
                $textReliable = $this->pdf->textExtractionReliable();
            }
        }

        if ($checks['filename']) {
            $findings = array_merge($findings, $this->filename->scan($filename, $profile));
        }

        $findings = OoxmlScanner::deduplicate($findings);

        $cleaned = [];
        $cleanedPath = null;
        if ($autoClean && $this->ooxml->handles($extension) && is_readable($path)) {
            $target = self::cleanedPathFor($path);
            $cleaned = $this->cleaner->clean($path, $target);
            $cleanedPath = $cleaned ? $target : null;
        }

        return new ScanReport($filename, $findings, $cleaned, $textReliable, $submissionFileId, $cleanedPath);
    }

    /**
     * A free path for the cleaned copy, in the system temporary directory so
     * that nothing is ever created inside the journal's files directory unless
     * the caller stores it there.
     */
    public static function cleanedPathFor(string $path): string
    {
        return sys_get_temp_dir() . '/brg-' . bin2hex(random_bytes(8)) . '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}
