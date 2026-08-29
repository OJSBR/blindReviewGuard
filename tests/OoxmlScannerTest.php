<?php

/**
 * @file plugins/generic/blindReviewGuard/tests/OoxmlScannerTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OoxmlScannerTest
 *
 * @brief The .docx scanner against a document that leaks in every way at once.
 */

namespace APP\plugins\generic\blindReviewGuard\tests;

use APP\plugins\generic\blindReviewGuard\classes\Finding;
use APP\plugins\generic\blindReviewGuard\classes\FileScanner;
use APP\plugins\generic\blindReviewGuard\classes\IdentityProfile;
use APP\plugins\generic\blindReviewGuard\classes\scanners\OoxmlScanner;

class OoxmlScannerTest extends TestCase
{
    private function profile(): IdentityProfile
    {
        return (new IdentityProfile())
            ->addAuthor('Maria', 'Souza', 'maria.souza@ufxx.br', 'https://orcid.org/0000-0002-1825-0097', ['Universidade Federal de Exemplo']);
    }

    /** @return string[] The matched values of the findings of one type */
    private function matches(array $findings, string $type): array
    {
        return array_values(array_map(
            fn (Finding $f) => $f->match,
            array_filter($findings, fn (Finding $f) => $f->type === $type)
        ));
    }

    public function testHandlesOfficeExtensionsOnly(): void
    {
        $scanner = new OoxmlScanner();
        $this->assertTrue($scanner->handles('docx'));
        $this->assertTrue($scanner->handles('DOCX'));
        $this->assertFalse($scanner->handles('pdf'));
    }

    public function testFindsIdentifyingDocumentProperties(): void
    {
        $findings = (new OoxmlScanner())->scan(FixtureFactory::dirtyDocx(), $this->profile(), FileScanner::DEFAULT_CHECKS);
        $properties = $this->matches($findings, Finding::TYPE_DOCUMENT_PROPERTY);

        $this->assertTrue(in_array('Maria Souza', $properties, true), 'dc:creator was not reported');
        $this->assertTrue(in_array('msouza', $properties, true), 'cp:lastModifiedBy was not reported');
        $this->assertTrue(in_array('Universidade Federal de Exemplo', $properties, true), 'Company was not reported');
    }

    public function testDoesNotReportTheDocumentTitle(): void
    {
        // dc:title is not a person; reporting it would be noise.
        $findings = (new OoxmlScanner())->scan(FixtureFactory::dirtyDocx(), $this->profile(), FileScanner::DEFAULT_CHECKS);
        $this->assertFalse(in_array('Estudo sobre letramento', $this->matches($findings, Finding::TYPE_DOCUMENT_PROPERTY), true));
    }

    public function testFindsTrackedChangeAndCommentAuthors(): void
    {
        $findings = (new OoxmlScanner())->scan(FixtureFactory::dirtyDocx(), $this->profile(), FileScanner::DEFAULT_CHECKS);

        $this->assertTrue(in_array('Maria Souza', $this->matches($findings, Finding::TYPE_REVISION_MARK), true), 'w:ins author was not reported');
        // The commenter is not one of the authors and is reported all the same:
        // a supervisor's name breaks the anonymity just as effectively.
        $this->assertTrue(in_array('Joao Pereira', $this->matches($findings, Finding::TYPE_COMMENT), true), 'comment author was not reported');
    }

    public function testFindsTheAuthorEmailInTheBody(): void
    {
        $findings = (new OoxmlScanner())->scan(FixtureFactory::dirtyDocx(), $this->profile(), FileScanner::DEFAULT_CHECKS);
        $this->assertTrue(in_array('maria.souza@ufxx.br', $this->matches($findings, Finding::TYPE_TEXT), true));
    }

    public function testFindsANameSplitAcrossRuns(): void
    {
        // The fixture writes "Mari" + "a Souza" in separate runs, exactly as Word
        // does after editing. A search over the raw XML would miss it.
        $findings = (new OoxmlScanner())->scan(FixtureFactory::dirtyDocx(), $this->profile(), FileScanner::DEFAULT_CHECKS);
        $this->assertTrue(in_array('Maria Souza', $this->matches($findings, Finding::TYPE_TEXT), true));
    }

    public function testReportsNothingForACleanDocument(): void
    {
        $findings = (new OoxmlScanner())->scan(FixtureFactory::cleanDocx(), $this->profile(), FileScanner::DEFAULT_CHECKS);
        $this->assertEmpty($findings, 'a clean document must produce no findings at all');
    }

    public function testChecksCanBeDisabledIndividually(): void
    {
        $checks = ['metadata' => false, 'revisionMarks' => true, 'text' => false, 'filename' => false];
        $findings = (new OoxmlScanner())->scan(FixtureFactory::dirtyDocx(), $this->profile(), $checks);

        $this->assertEmpty($this->matches($findings, Finding::TYPE_DOCUMENT_PROPERTY));
        $this->assertEmpty($this->matches($findings, Finding::TYPE_TEXT));
        $this->assertNotEmpty($this->matches($findings, Finding::TYPE_REVISION_MARK));
    }

    public function testNeutralPlaceholdersAreNotReported(): void
    {
        $this->assertFalse(OoxmlScanner::isIdentifying('Author'));
        $this->assertFalse(OoxmlScanner::isIdentifying('  '));
        $this->assertFalse(OoxmlScanner::isIdentifying('Microsoft Office User'));
        $this->assertTrue(OoxmlScanner::isIdentifying('Maria Souza'));
    }

    public function testAnUnreadableFileIsNotAFalseNegative(): void
    {
        // A file that cannot be opened returns nothing; the plugin reports the
        // format as unsupported rather than claiming the file is clean.
        $this->assertEmpty((new OoxmlScanner())->scan('/nonexistent/file.docx', $this->profile(), FileScanner::DEFAULT_CHECKS));
    }
}
