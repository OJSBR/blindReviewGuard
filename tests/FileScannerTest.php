<?php

/**
 * @file plugins/generic/blindReviewGuard/tests/FileScannerTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FileScannerTest
 *
 * @brief End to end: the facade the plugin actually calls.
 */

namespace APP\plugins\generic\blindReviewGuard\tests;

use APP\plugins\generic\blindReviewGuard\classes\FileScanner;
use APP\plugins\generic\blindReviewGuard\classes\Finding;
use APP\plugins\generic\blindReviewGuard\classes\IdentityProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;

#[CoversClass(FileScanner::class)]
class FileScannerTest extends PKPTestCase
{
    private function profile(): IdentityProfile
    {
        return (new IdentityProfile())
            ->addAuthor('Maria', 'Souza', 'maria.souza@ufxx.br', null, ['Universidade Federal de Exemplo']);
    }

    public function testFlagsTheAuthorNameInTheFileName(): void
    {
        $report = (new FileScanner())->scan(FixtureFactory::cleanDocx(), 'Souza - artigo final.docx', $this->profile());
        $filenameFindings = array_filter($report->findings, fn (Finding $f) => $f->type === Finding::TYPE_FILENAME);

        $this->assertNotEmpty($filenameFindings);
    }

    public function testDoesNotFlagANeutralFileName(): void
    {
        $report = (new FileScanner())->scan(FixtureFactory::cleanDocx(), 'estudo-letramento-cientifico.docx', $this->profile());
        $this->assertFalse($report->hasFindings());
    }

    public function testSeparatorsDoNotHideTheName(): void
    {
        // "Souza_artigo.docx" must be caught as surely as "Souza artigo.docx".
        $report = (new FileScanner())->scan(FixtureFactory::cleanDocx(), 'Souza_artigo.docx', $this->profile());
        $this->assertTrue($report->hasFindings());
    }

    public function testAutoCleanRemovesWhatItCanAndReportsTheRest(): void
    {
        $path = FixtureFactory::dirtyDocx('facade.docx');
        $before = md5_file($path);
        $report = (new FileScanner())->scan($path, 'facade.docx', $this->profile(), FileScanner::DEFAULT_CHECKS, true);

        $this->assertNotEmpty($report->cleaned, 'nothing was cleaned');
        $remaining = $report->remaining();
        $this->assertNotEmpty($remaining, 'the body findings must remain for the editor to judge');
        foreach ($remaining as $finding) {
            $this->assertSame(Finding::TYPE_TEXT, $finding->type);
        }

        // The scanned file is never modified: the cleaned copy is handed over.
        $this->assertSame($before, md5_file($path), 'the scanned file was modified');
        $this->assertTrue(is_string($report->cleanedPath) && is_file($report->cleanedPath), 'no cleaned copy was handed over');
        $this->assertTrue(!str_starts_with($report->cleanedPath, dirname($path) . '/'), 'the cleaned copy must not be written next to the stored file');
        $rescan = (new FileScanner())->scan($report->cleanedPath, 'facade.docx', $this->profile());
        $this->assertCount(count($remaining), $rescan->findings, 'the cleaned copy still carries what was reported as removed');
        @unlink($report->cleanedPath);
    }

    public function testNothingToCleanHandsOverNoCopy(): void
    {
        $report = (new FileScanner())->scan(FixtureFactory::cleanDocx('nothing.docx'), 'estudo.docx', $this->profile(), FileScanner::DEFAULT_CHECKS, true);

        $this->assertEmpty($report->cleaned);
        $this->assertSame(null, $report->cleanedPath);
    }

    public function testAReportWithoutCleaningKeepsEveryFinding(): void
    {
        // Used when the cleaned copy could not be stored: nothing was removed.
        $report = (new FileScanner())->scan(FixtureFactory::dirtyDocx('unstored.docx'), 'unstored.docx', $this->profile(), FileScanner::DEFAULT_CHECKS, true);
        @unlink((string) $report->cleanedPath);
        $unstored = $report->withoutCleaning();

        $this->assertEmpty($unstored->cleaned);
        $this->assertSame(null, $unstored->cleanedPath);
        $this->assertCount(count($report->findings), $unstored->remaining());
    }

    public function testWithoutAutoCleanTheFileIsNotTouched(): void
    {
        $path = FixtureFactory::dirtyDocx('untouched.docx');
        $before = md5_file($path);
        $report = (new FileScanner())->scan($path, 'untouched.docx', $this->profile());

        $this->assertTrue($report->hasFindings());
        $this->assertEmpty($report->cleaned);
        $this->assertSame($before, md5_file($path));
    }

    public function testAnUnsupportedFormatStillChecksTheFileName(): void
    {
        // A .zip of supplementary material cannot be read, but its name can.
        $path = FixtureFactory::directory() . '/Souza dados.zip';
        file_put_contents($path, 'not really a zip');
        $report = (new FileScanner())->scan($path, 'Souza dados.zip', $this->profile());

        $this->assertTrue($report->hasFindings());
        $this->assertSame(Finding::TYPE_FILENAME, $report->findings[0]->type);
    }

    public function testReportSerialisesForTheEventLog(): void
    {
        $report = (new FileScanner())->scan(FixtureFactory::dirtyDocx('serialise.docx'), 'serialise.docx', $this->profile());
        $array = $report->toArray();

        $this->assertSame('serialise.docx', $array['filename']);
        $this->assertTrue(is_array($array['findings']));
        $this->assertTrue(isset($array['findings'][0]['type'], $array['findings'][0]['match']));
    }

    public static function tearDownAfterClass(): void
    {
        FixtureFactory::cleanUp();
        parent::tearDownAfterClass();
    }
}
