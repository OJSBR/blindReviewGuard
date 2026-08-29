<?php

/**
 * @file plugins/generic/blindReviewGuard/tests/OoxmlCleanerTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OoxmlCleanerTest
 *
 * @brief Cleaning must remove the metadata and leave the manuscript alone.
 */

namespace APP\plugins\generic\blindReviewGuard\tests;

use APP\plugins\generic\blindReviewGuard\classes\Finding;
use APP\plugins\generic\blindReviewGuard\classes\FileScanner;
use APP\plugins\generic\blindReviewGuard\classes\IdentityProfile;
use APP\plugins\generic\blindReviewGuard\classes\OoxmlCleaner;
use APP\plugins\generic\blindReviewGuard\classes\scanners\OoxmlScanner;
use ZipArchive;

class OoxmlCleanerTest extends TestCase
{
    private function profile(): IdentityProfile
    {
        return (new IdentityProfile())
            ->addAuthor('Maria', 'Souza', 'maria.souza@ufxx.br', null, ['Universidade Federal de Exemplo']);
    }

    private function part(string $path, string $name): string
    {
        $zip = new ZipArchive();
        $zip->open($path);
        $xml = $zip->getFromName($name);
        $zip->close();

        return $xml === false ? '' : $xml;
    }

    public function testRemovesPropertiesAndAuthorAttributes(): void
    {
        $path = FixtureFactory::dirtyDocx('to-clean.docx');
        $removed = (new OoxmlCleaner())->clean($path);

        $this->assertNotEmpty($removed, 'nothing was reported as removed');

        $core = $this->part($path, 'docProps/core.xml');
        $this->assertStringNotContainsString('Maria Souza', $core);
        $this->assertStringNotContainsString('msouza', $core);
        $this->assertStringNotContainsString('Universidade Federal de Exemplo', $this->part($path, 'docProps/app.xml'));

        $document = $this->part($path, 'word/document.xml');
        $this->assertStringNotContainsString('w:author="Maria Souza"', $document);
        $this->assertStringContainsString('w:author="Author"', $document);
        $this->assertStringNotContainsString('Joao Pereira', $this->part($path, 'word/comments.xml'));
    }

    public function testLeavesTheManuscriptTextUntouched(): void
    {
        // The one thing the plugin must never do is edit the submission.
        $path = FixtureFactory::dirtyDocx('keep-text.docx');
        (new OoxmlCleaner())->clean($path);
        $document = $this->part($path, 'word/document.xml');

        $this->assertStringContainsString('Estudo sobre letramento cientifico', $document);
        $this->assertStringContainsString('maria.souza@ufxx.br', $document, 'the e-mail in the body is reported, never silently deleted');
        $this->assertStringContainsString('Trecho inserido durante a revisao.', $document);
    }

    public function testTheFileIsStillAValidPackage(): void
    {
        $path = FixtureFactory::dirtyDocx('still-valid.docx');
        (new OoxmlCleaner())->clean($path);

        $zip = new ZipArchive();
        $this->assertSame(true, $zip->open($path, ZipArchive::CHECKCONS) === true, 'the cleaned file is no longer a readable package');
        $zip->close();
        $this->assertEmpty(glob(FixtureFactory::directory() . '/*.brg-tmp') ?: [], 'a temporary file was left behind');
    }

    public function testAfterCleaningOnlyTheTextFindingsRemain(): void
    {
        $path = FixtureFactory::dirtyDocx('rescan.docx');
        (new OoxmlCleaner())->clean($path);

        $findings = (new OoxmlScanner())->scan($path, $this->profile(), FileScanner::DEFAULT_CHECKS);
        foreach ($findings as $finding) {
            $this->assertSame(Finding::TYPE_TEXT, $finding->type, 'a cleanable finding survived the cleaning: ' . $finding->where);
        }
        $this->assertNotEmpty($findings, 'the text findings must survive - only the editor can decide about the body');
    }

    public function testCleaningACleanFileChangesNothing(): void
    {
        $path = FixtureFactory::cleanDocx('already-clean.docx');
        $before = md5_file($path);

        $this->assertEmpty((new OoxmlCleaner())->clean($path));
        $this->assertSame($before, md5_file($path), 'the file was rewritten even though there was nothing to remove');
    }
}
