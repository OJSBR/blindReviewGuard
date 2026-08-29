<?php

/**
 * @file plugins/generic/blindReviewGuard/classes/IdentityProfile.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IdentityProfile
 *
 * @brief The identifying strings of one submission: who must NOT appear in the
 *        files a reviewer receives.
 *
 * This is what separates BlindReviewGuard from a generic metadata stripper: it
 * does not look for "a name", it looks for THESE names, e-mails, ORCID iDs and
 * affiliations, taken from the submission's own contributor list.
 *
 * Free of OJS dependencies on purpose; the plugin builds it from the publication.
 */

namespace APP\plugins\generic\blindReviewGuard\classes;

class IdentityProfile
{
    /**
     * Family names shorter than this are ignored as free text. "Sá" or "Li" would
     * match half of any manuscript; the full name is still matched exactly.
     */
    public const MIN_NAME_LENGTH = 4;

    /** Affiliations shorter than this are too generic to be evidence. */
    public const MIN_AFFILIATION_LENGTH = 6;

    /** @var string[] Full names, e.g. "Maria Souza" */
    private array $fullNames = [];

    /** @var string[] Family names on their own, e.g. "Souza" */
    private array $familyNames = [];

    /** @var string[] */
    private array $emails = [];

    /** @var string[] Bare ORCID iDs, e.g. "0000-0002-1825-0097" */
    private array $orcids = [];

    /** @var string[] */
    private array $affiliations = [];

    public function addAuthor(string $givenName, string $familyName, ?string $email = null, ?string $orcid = null, array $affiliations = []): self
    {
        $givenName = trim($givenName);
        $familyName = trim($familyName);

        if ($givenName !== '' && $familyName !== '') {
            $this->fullNames[] = $givenName . ' ' . $familyName;
        }
        if ($familyName !== '' && mb_strlen($familyName) >= self::MIN_NAME_LENGTH) {
            $this->familyNames[] = $familyName;
        }
        if ($givenName !== '' && $familyName === '' && mb_strlen($givenName) >= self::MIN_NAME_LENGTH) {
            // Single-name authors (some traditions, and OJS allows it).
            $this->familyNames[] = $givenName;
        }
        if ($email) {
            $this->emails[] = trim($email);
        }
        if ($orcid) {
            // Accept the canonical URL or the bare iD; store the digits.
            if (preg_match('#(\d{4}-\d{4}-\d{4}-\d{3}[\dXx])#', $orcid, $m)) {
                $this->orcids[] = strtoupper($m[1]);
            }
        }
        foreach ($affiliations as $affiliation) {
            $affiliation = trim((string) $affiliation);
            if (mb_strlen($affiliation) >= self::MIN_AFFILIATION_LENGTH) {
                $this->affiliations[] = $affiliation;
            }
        }

        return $this;
    }

    /**
     * Needles for the strict checks (document properties, tracked changes,
     * comments, file name), where any occurrence is evidence.
     */
    public function strictNeedles(): array
    {
        return $this->unique(array_merge($this->fullNames, $this->familyNames, $this->emails, $this->orcids));
    }

    /**
     * Needles for the body text. Affiliations are included here only: an
     * affiliation in the document properties is a leak, but in the text it may
     * legitimately be a cited institution, so it is reported with less weight.
     */
    public function textNeedles(): array
    {
        return $this->unique(array_merge($this->fullNames, $this->emails, $this->orcids, $this->affiliations));
    }

    /**
     * The file name check is the noisiest one, so it uses names only.
     */
    public function filenameNeedles(): array
    {
        return $this->unique(array_merge($this->fullNames, $this->familyNames));
    }

    public function isEmpty(): bool
    {
        return empty($this->strictNeedles());
    }

    private function unique(array $needles): array
    {
        $seen = [];
        foreach ($needles as $needle) {
            $needle = trim($needle);
            if ($needle === '') {
                continue;
            }
            $seen[mb_strtolower($needle)] = $needle;
        }
        return array_values($seen);
    }
}
