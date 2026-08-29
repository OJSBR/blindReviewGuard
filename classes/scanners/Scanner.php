<?php

/**
 * @file plugins/generic/blindReviewGuard/classes/scanners/Scanner.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Scanner
 *
 * @brief Contract for a file-format scanner.
 */

namespace APP\plugins\generic\blindReviewGuard\classes\scanners;

use APP\plugins\generic\blindReviewGuard\classes\Finding;
use APP\plugins\generic\blindReviewGuard\classes\IdentityProfile;

interface Scanner
{
    /**
     * Whether this scanner handles the given file extension (lower case, no dot).
     */
    public function handles(string $extension): bool;

    /**
     * @param array $checks Which checks are enabled: metadata, revisionMarks, text
     *
     * @return Finding[]
     */
    public function scan(string $path, IdentityProfile $profile, array $checks): array;
}
