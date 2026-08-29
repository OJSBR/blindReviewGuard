<?php

/**
 * @file plugins/generic/blindReviewGuard/tests/NeedlesTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class NeedlesTest
 *
 * @brief Matching of identifying strings: the difference between a useful report
 *        and one editors learn to ignore.
 */

namespace APP\plugins\generic\blindReviewGuard\tests;

use APP\plugins\generic\blindReviewGuard\classes\Needles;

class NeedlesTest extends TestCase
{
    public function testMatchesAWholeName(): void
    {
        $this->assertSame(['Maria Souza'], Needles::findIn('Agradecemos a Maria Souza pela revisao.', ['Maria Souza']));
    }

    public function testDoesNotMatchInsideALongerWord(): void
    {
        // "Sousa" must not fire on "Sousada"; this is the check that keeps the
        // report credible for short surnames.
        $this->assertEmpty(Needles::findIn('A vila de Sousada fica no interior.', ['Sousa']));
    }

    public function testMatchesNextToPunctuation(): void
    {
        $this->assertSame(['Souza'], Needles::findIn('(Souza, 2026) demonstrou que...', ['Souza']));
    }

    public function testIsCaseInsensitiveAndAccentAware(): void
    {
        $this->assertSame(['José Antônio'], Needles::findIn('Contato: JOSÉ ANTÔNIO da Silva', ['José Antônio']));
        // An accented letter must not be treated as a word boundary, otherwise
        // every name with an accent produces a false positive on its neighbours.
        $this->assertEmpty(Needles::findIn('Trabalhamos com Antônios diversos', ['Antônio']));
    }

    public function testFindsEmailAddresses(): void
    {
        $this->assertSame(['maria.souza@ufxx.br'], Needles::findIn('Autor correspondente: maria.souza@ufxx.br', ['maria.souza@ufxx.br']));
    }

    public function testXmlToTextJoinsRunsSplitByWord(): void
    {
        // Word routinely splits a name across runs; the text must be rebuilt
        // before searching or the name escapes untouched.
        $xml = '<w:r><w:t>Mari</w:t></w:r><w:r><w:t>a Souza</w:t></w:r>';
        $this->assertSame('Maria Souza', Needles::xmlToText($xml));
    }

    public function testXmlToTextKeepsParagraphsApart(): void
    {
        $xml = '<w:p><w:r><w:t>Maria</w:t></w:r></w:p><w:p><w:r><w:t>Souza</w:t></w:r></w:p>';
        // Paragraphs are different sentences: they must not be glued into a name
        // that nobody wrote.
        $this->assertSame('Maria Souza', Needles::xmlToText($xml));
        $this->assertStringContainsString(' ', Needles::xmlToText($xml));
    }

    public function testDoesNotGlueAParagraphToTheNextBlock(): void
    {
        // Regression, found by running the plugin against a real submission and
        // not by reading the code: a paragraph followed by anything other than
        // another paragraph - here a tracked insertion - used to come out as
        // "...@ufxx.brTrecho", and the address stopped matching on its word
        // boundary. The leak was in the file and the report said nothing.
        $xml = '<w:p><w:r><w:t xml:space="preserve">Autor correspondente: </w:t></w:r>'
            . '<w:r><w:t>maria.souza@ufxx.br</w:t></w:r></w:p>'
            . '<w:ins w:id="1" w:author="Maria Souza"><w:r><w:t>Trecho inserido.</w:t></w:r></w:ins>';

        $text = Needles::xmlToText($xml);
        $this->assertStringContainsString('maria.souza@ufxx.br ', $text);
        $this->assertSame(['maria.souza@ufxx.br'], Needles::findIn($text, ['maria.souza@ufxx.br']));
    }

    public function testKeepsTableCellsApart(): void
    {
        // The same failure mode in a table: two cells are two texts, never one word.
        $xml = '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Souza</w:t></w:r></w:p></w:tc>'
            . '<w:tc><w:p><w:r><w:t>Maria</w:t></w:r></w:p></w:tc></w:tr></w:tbl>';
        $this->assertSame('Souza Maria', Needles::xmlToText($xml));
    }

    public function testIgnoresAttributeValuesInTheText(): void
    {
        // Only the content of the text elements counts: an author name sitting in
        // a w:author attribute is a revision-mark finding, not a text finding, and
        // reporting it twice would inflate the report.
        $xml = '<w:ins w:author="Maria Souza"><w:r><w:t>Texto neutro.</w:t></w:r></w:ins>';
        $this->assertSame('Texto neutro.', Needles::xmlToText($xml));
    }

    public function testDecodesEntities(): void
    {
        $this->assertSame('Souza & Silva', Needles::xmlToText('<w:t>Souza &amp; Silva</w:t>'));
    }
}
