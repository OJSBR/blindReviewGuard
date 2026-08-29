<?php

/**
 * @file plugins/generic/blindReviewGuard/tests/PdfScannerTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PdfScannerTest
 *
 * @brief The PDF scanner, including the honesty of its own limits.
 */

namespace APP\plugins\generic\blindReviewGuard\tests;

use APP\plugins\generic\blindReviewGuard\classes\Finding;
use APP\plugins\generic\blindReviewGuard\classes\FileScanner;
use APP\plugins\generic\blindReviewGuard\classes\IdentityProfile;
use APP\plugins\generic\blindReviewGuard\classes\scanners\PdfScanner;

class PdfScannerTest extends TestCase
{
    private function profile(): IdentityProfile
    {
        return (new IdentityProfile())->addAuthor('Maria', 'Souza', 'maria.souza@ufxx.br');
    }

    public function testFindsTheAuthorInTheInformationDictionary(): void
    {
        $findings = (new PdfScanner())->scan(FixtureFactory::dirtyPdf(), $this->profile(), FileScanner::DEFAULT_CHECKS);
        $properties = array_map(fn (Finding $f) => $f->match, array_filter($findings, fn (Finding $f) => $f->type === Finding::TYPE_DOCUMENT_PROPERTY));

        $this->assertTrue(in_array('Maria Souza', $properties, true), '/Author was not reported');
    }

    public function testFindsTheEmailInsideACompressedPageStream(): void
    {
        $findings = (new PdfScanner())->scan(FixtureFactory::dirtyPdf(), $this->profile(), FileScanner::DEFAULT_CHECKS);
        $text = array_map(fn (Finding $f) => $f->match, array_filter($findings, fn (Finding $f) => $f->type === Finding::TYPE_TEXT));

        $this->assertTrue(in_array('maria.souza@ufxx.br', $text, true), 'the compressed page stream was not read');
    }

    public function testReportsWhenTheTextCouldNotBeRead(): void
    {
        // A PDF with no readable stream (a scan, in practice) must not be
        // presented as clean: the caller asks the scanner whether it could read.
        $path = FixtureFactory::directory() . '/no-text.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<< /Author (Maria Souza) >>\nendobj\n%%EOF\n");

        $scanner = new PdfScanner();
        $findings = $scanner->scan($path, $this->profile(), FileScanner::DEFAULT_CHECKS);

        $this->assertNotEmpty($findings, 'the metadata is still readable');
        $this->assertFalse($scanner->textExtractionReliable(), 'the scanner claimed it had read a body it could not read');
    }

    public function testDecodesUtf16HexStrings(): void
    {
        // Non-ASCII author names are written as UTF-16BE hex strings by most
        // PDF writers; read as bytes they look like harmless noise.
        $hex = 'FEFF' . bin2hex(mb_convert_encoding('José Antônio', 'UTF-16BE', 'UTF-8'));
        $this->assertSame('José Antônio', PdfScanner::decodeHex($hex));
    }

    public function testDecodesEscapedLiterals(): void
    {
        $this->assertSame('Souza (org.)', PdfScanner::decodeLiteral('Souza \\(org.\\)'));
    }

    public function testHandlesPdfOnly(): void
    {
        $scanner = new PdfScanner();
        $this->assertTrue($scanner->handles('pdf'));
        $this->assertFalse($scanner->handles('docx'));
    }
}
