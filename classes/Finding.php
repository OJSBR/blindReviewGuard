<?php

/**
 * @file plugins/generic/blindReviewGuard/classes/Finding.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Finding
 *
 * @brief A single identity leak found in a file that is about to be seen by a reviewer.
 *
 * Deliberately free of any OJS dependency, so the scanners can be unit tested
 * without bootstrapping the application.
 */

namespace APP\plugins\generic\blindReviewGuard\classes;

class Finding
{
    /** Author name, e-mail, ORCID or affiliation found in a document property. */
    public const TYPE_DOCUMENT_PROPERTY = 'documentProperty';

    /** Author name found in a tracked change (insertion/deletion). */
    public const TYPE_REVISION_MARK = 'revisionMark';

    /** Author name found in a comment. */
    public const TYPE_COMMENT = 'comment';

    /** Author name, e-mail or ORCID found in the visible text. */
    public const TYPE_TEXT = 'text';

    /** Author name found in the file name itself. */
    public const TYPE_FILENAME = 'filename';

    public function __construct(
        public readonly string $type,
        /** Where it was found: a property name, a file part, a page number. */
        public readonly string $where,
        /** The identifying value that leaked, as found. */
        public readonly string $match,
        /** True when this finding can be removed by BlindReviewGuard itself. */
        public readonly bool $cleanable = false
    ) {
    }

    /**
     * A stable key used to collapse repeated findings (the same author name in
     * forty tracked changes is one problem, not forty).
     */
    public function key(): string
    {
        return $this->type . '|' . $this->where . '|' . mb_strtolower($this->match);
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'where' => $this->where,
            'match' => $this->match,
            'cleanable' => $this->cleanable,
        ];
    }
}
