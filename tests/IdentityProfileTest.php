<?php

/**
 * @file plugins/generic/blindReviewGuard/tests/IdentityProfileTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IdentityProfileTest
 *
 * @brief The list of strings that must not reach a reviewer.
 */

namespace APP\plugins\generic\blindReviewGuard\tests;

use APP\plugins\generic\blindReviewGuard\classes\IdentityProfile;

class IdentityProfileTest extends TestCase
{
    public function testBuildsFullAndFamilyNames(): void
    {
        $profile = (new IdentityProfile())->addAuthor('Maria', 'Souza');
        $needles = $profile->strictNeedles();
        $this->assertTrue(in_array('Maria Souza', $needles, true));
        $this->assertTrue(in_array('Souza', $needles, true));
    }

    public function testDropsVeryShortFamilyNames(): void
    {
        // "Sá" on its own would match a fragment of half the manuscripts; the
        // full name is still searched for.
        $profile = (new IdentityProfile())->addAuthor('Ana', 'Sá');
        $needles = $profile->strictNeedles();
        $this->assertTrue(in_array('Ana Sá', $needles, true));
        $this->assertFalse(in_array('Sá', $needles, true));
    }

    public function testNormalisesOrcidToTheBareId(): void
    {
        $profile = (new IdentityProfile())->addAuthor('Maria', 'Souza', null, 'https://orcid.org/0000-0002-1825-0097');
        $this->assertTrue(in_array('0000-0002-1825-0097', $profile->strictNeedles(), true));
    }

    public function testAffiliationsAreTextOnly(): void
    {
        $profile = (new IdentityProfile())->addAuthor('Maria', 'Souza', null, null, ['Universidade Federal de Exemplo']);
        // An affiliation in the body may be a legitimately cited institution, so
        // it is searched for in the text but is not strict evidence.
        $this->assertFalse(in_array('Universidade Federal de Exemplo', $profile->strictNeedles(), true));
        $this->assertTrue(in_array('Universidade Federal de Exemplo', $profile->textNeedles(), true));
    }

    public function testDeduplicatesAcrossAuthors(): void
    {
        $profile = (new IdentityProfile())
            ->addAuthor('Maria', 'Souza')
            ->addAuthor('maria', 'souza');
        $this->assertCount(2, $profile->strictNeedles());
    }

    public function testIsEmptyWithoutAuthors(): void
    {
        $this->assertTrue((new IdentityProfile())->isEmpty());
    }
}
