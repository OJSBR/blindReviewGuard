<?php

/**
 * @file plugins/generic/blindReviewGuard/classes/ScanReport.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ScanReport
 *
 * @brief The result of scanning one file: what leaked, what was removed, and
 *        whether the whole file could actually be read.
 */

namespace APP\plugins\generic\blindReviewGuard\classes;

class ScanReport
{
    /** @param Finding[] $findings @param Finding[] $cleaned */
    public function __construct(
        public readonly string $filename,
        public readonly array $findings = [],
        public readonly array $cleaned = [],
        /** False when the body text could not be read (e.g. a scanned PDF). */
        public readonly bool $textReliable = true,
        /** The submission file this report refers to, when it came from OJS. */
        public readonly ?int $submissionFileId = null
    ) {
    }

    public function hasFindings(): bool
    {
        return !empty($this->findings);
    }

    /** Findings that are still there after the automatic cleaning. */
    public function remaining(): array
    {
        $cleanedKeys = array_flip(array_map(fn (Finding $f) => $f->key(), $this->cleaned));

        return array_values(array_filter(
            $this->findings,
            fn (Finding $f) => !isset($cleanedKeys[$f->key()])
        ));
    }

    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'findings' => array_map(fn (Finding $f) => $f->toArray(), $this->findings),
            'cleaned' => array_map(fn (Finding $f) => $f->toArray(), $this->cleaned),
            'textReliable' => $this->textReliable,
        ];
    }
}
