<?php

/**
 * @file plugins/generic/blindReviewGuard/tests/FixtureFactory.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FixtureFactory
 *
 * @brief Builds the dirty documents the suite runs against.
 *
 * The fixtures are generated rather than committed as binaries: a reviewer of
 * this plugin can read exactly what makes each file "dirty", and the repository
 * stays free of opaque blobs.
 */

namespace APP\plugins\generic\blindReviewGuard\tests;

use ZipArchive;

class FixtureFactory
{
    public static function directory(): string
    {
        $dir = sys_get_temp_dir() . '/blindReviewGuard-fixtures';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }

    /**
     * A .docx that leaks in all four ways at once: document properties, a
     * tracked change, a comment, and the author's e-mail in the body.
     */
    public static function dirtyDocx(string $name = 'dirty.docx'): string
    {
        $path = self::directory() . '/' . $name;
        @unlink($path);

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');

        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:title>Estudo sobre letramento</dc:title>'
            . '<dc:creator>Maria Souza</dc:creator>'
            . '<cp:lastModifiedBy>msouza</cp:lastModifiedBy>'
            . '</cp:coreProperties>');

        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
            . '<Company>Universidade Federal de Exemplo</Company>'
            . '<Pages>12</Pages>'
            . '</Properties>');

        // The body: a title, the corresponding author's e-mail, and a tracked
        // insertion. Note that "Maria" and "Souza" sit in separate <w:t> runs,
        // which is how a name survives a naive search of the XML.
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . '<w:p><w:r><w:t>Estudo sobre letramento cientifico</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Autor correspondente: </w:t></w:r><w:r><w:t>maria.souza@ufxx.br</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t xml:space="preserve">Agradecemos a </w:t></w:r><w:r><w:t>Mari</w:t></w:r><w:r><w:t xml:space="preserve">a Souza</w:t></w:r>'
            . '<w:r><w:t xml:space="preserve"> pela revisao.</w:t></w:r></w:p>'
            . '<w:ins w:id="1" w:author="Maria Souza" w:date="2026-08-01T10:00:00Z">'
            . '<w:r><w:t>Trecho inserido durante a revisao.</w:t></w:r></w:ins>'
            . '</w:body></w:document>');

        $zip->addFromString('word/comments.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:comments xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:comment w:id="1" w:author="Joao Pereira" w:initials="JP" w:date="2026-08-01T10:05:00Z">'
            . '<w:p><w:r><w:t>Rever esta secao.</w:t></w:r></w:p></w:comment>'
            . '</w:comments>');

        $zip->close();

        return $path;
    }

    /**
     * A .docx with no identifying trace at all: the control that proves the
     * scanner does not simply flag everything.
     */
    public static function cleanDocx(string $name = 'clean.docx'): string
    {
        $path = self::directory() . '/' . $name;
        @unlink($path);

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:title>Estudo sobre letramento</dc:title>'
            . '<dc:creator>Author</dc:creator>'
            . '<cp:lastModifiedBy></cp:lastModifiedBy>'
            . '</cp:coreProperties>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . '<w:p><w:r><w:t>Estudo sobre letramento cientifico</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>O presente trabalho analisa dados de tres escolas.</w:t></w:r></w:p>'
            . '</w:body></w:document>');
        $zip->close();

        return $path;
    }

    /**
     * A structurally valid PDF whose /Info dictionary names the author and whose
     * (compressed) page stream shows her e-mail address.
     */
    public static function dirtyPdf(string $name = 'dirty.pdf'): string
    {
        $path = self::directory() . '/' . $name;
        @unlink($path);

        $content = "BT /F1 12 Tf 72 720 Td (Estudo sobre letramento cientifico) Tj ET\n"
            . "BT /F1 10 Tf 72 700 Td (Autor correspondente: maria.souza@ufxx.br) Tj ET\n";
        $stream = gzcompress($content);

        $objects = [
            1 => "<< /Type /Catalog /Pages 2 0 R >>",
            2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
            4 => "<< /Length " . strlen($stream) . " /Filter /FlateDecode >>\nstream\n" . $stream . "\nendstream",
            5 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
            6 => "<< /Author (Maria Souza) /Creator (Microsoft Word) /Title (Estudo sobre letramento) >>",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R /Info 6 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";

        file_put_contents($path, $pdf);

        return $path;
    }

    public static function cleanUp(): void
    {
        $dir = self::directory();
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
