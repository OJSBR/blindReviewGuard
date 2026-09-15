<?php

/**
 * @file plugins/generic/blindReviewGuard/tests/OoxmlCleanerTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
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

    /** Clean $source into a fresh target and return the target. */
    private function cleaned(string $source, ?array &$removed = null): string
    {
        $target = FixtureFactory::directory() . '/cleaned-' . basename($source);
        @unlink($target);
        $removed = (new OoxmlCleaner())->clean($source, $target);

        return $target;
    }

    public function testRemovesPropertiesAndAuthorAttributes(): void
    {
        $path = $this->cleaned(FixtureFactory::dirtyDocx('to-clean.docx'), $removed);

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

    public function testNeverWritesTheSourceFile(): void
    {
        // In OJS the review copy and the author's upload share one stored file:
        // writing the source would clean the author's original too.
        $source = FixtureFactory::dirtyDocx('shared.docx');
        $before = md5_file($source);
        $target = $this->cleaned($source, $removed);

        $this->assertNotEmpty($removed);
        $this->assertSame($before, md5_file($source), 'the source file was modified');
        $this->assertTrue($before !== md5_file($target), 'the target is not a cleaned copy');
        $this->assertEmpty((new OoxmlCleaner())->clean($source, $source), 'cleaning onto the source itself must be refused');
        $this->assertSame($before, md5_file($source));
    }

    public function testLeavesTheManuscriptTextUntouched(): void
    {
        // The one thing the plugin must never do is edit the submission.
        $document = $this->part($this->cleaned(FixtureFactory::dirtyDocx('keep-text.docx')), 'word/document.xml');

        $this->assertStringContainsString('Estudo sobre letramento cientifico', $document);
        $this->assertStringContainsString('maria.souza@ufxx.br', $document, 'the e-mail in the body is reported, never silently deleted');
        $this->assertStringContainsString('Trecho inserido durante a revisao.', $document);
    }

    public function testTheCleanedFileIsStillAValidPackage(): void
    {
        $path = $this->cleaned(FixtureFactory::dirtyDocx('still-valid.docx'));

        $zip = new ZipArchive();
        $this->assertSame(true, $zip->open($path, ZipArchive::CHECKCONS) === true, 'the cleaned file is no longer a readable package');
        $zip->close();
    }

    public function testAfterCleaningOnlyTheTextFindingsRemain(): void
    {
        $path = $this->cleaned(FixtureFactory::dirtyDocx('rescan.docx'));

        $findings = (new OoxmlScanner())->scan($path, $this->profile(), FileScanner::DEFAULT_CHECKS);
        foreach ($findings as $finding) {
            $this->assertSame(Finding::TYPE_TEXT, $finding->type, 'a cleanable finding survived the cleaning: ' . $finding->where);
        }
        $this->assertNotEmpty($findings, 'the text findings must survive - only the editor can decide about the body');
    }

    public function testCleaningACleanFileLeavesNoTargetBehind(): void
    {
        $source = FixtureFactory::cleanDocx('already-clean.docx');
        $before = md5_file($source);
        $target = $this->cleaned($source, $removed);

        $this->assertEmpty($removed);
        $this->assertFalse(is_file($target), 'a target was left behind although there was nothing to remove');
        $this->assertSame($before, md5_file($source));
    }
}
