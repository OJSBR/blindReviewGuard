<?php

/**
 * @file plugins/generic/blindReviewGuard/classes/scanners/FilenameScanner.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FilenameScanner
 *
 * @brief Looks for the authors' names in the name of the file itself.
 *
 * OJS no longer builds the download name out of the author's surname, but the
 * name the author typed ("Silva - artigo final.docx") travels with the upload.
 */

namespace APP\plugins\generic\blindReviewGuard\classes\scanners;

use APP\plugins\generic\blindReviewGuard\classes\Finding;
use APP\plugins\generic\blindReviewGuard\classes\IdentityProfile;
use APP\plugins\generic\blindReviewGuard\classes\Needles;

class FilenameScanner
{
    /**
     * @return Finding[]
     */
    public function scan(string $filename, IdentityProfile $profile): array
    {
        // Separators are not letters, so the boundary matching in Needles works
        // on "Silva_artigo.docx" as well as on "Silva artigo.docx".
        $haystack = str_replace(['_', '-', '.', '+'], ' ', $filename);

        $findings = [];
        foreach (Needles::findIn($haystack, $profile->filenameNeedles()) as $match) {
            $findings[] = new Finding(Finding::TYPE_FILENAME, $filename, $match, false);
        }

        return $findings;
    }
}
