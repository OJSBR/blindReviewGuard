<?php

/**
 * @file plugins/generic/blindReviewGuard/tests/TemplateSafetyTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class TemplateSafetyTest
 *
 * @brief Static checks on the settings template and the source files.
 */

namespace APP\plugins\generic\blindReviewGuard\tests;

class TemplateSafetyTest extends TestCase
{
    protected function template(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/templates/settingsForm.tpl');
    }

    public function testFormIsProtectedAgainstCsrf(): void
    {
        $this->assertStringContainsString('{csrf}', $this->template());
        $this->assertStringContainsString('AjaxFormHandler', $this->template());

        $form = (string) file_get_contents(dirname(__DIR__) . '/BlindReviewGuardSettingsForm.php');
        $this->assertStringContainsString('FormValidatorCSRF', $form);
        $this->assertStringContainsString('FormValidatorPost', $form);
    }

    public function testTemplateHasNoHardcodedText(): void
    {
        $text = preg_replace('/\{\*.*?\*\}/s', '', $this->template());
        $text = preg_replace('/<script\b.*?<\/script>/s', '', $text);
        $text = preg_replace('/\{[^{}]*\}/', '', $text);
        $text = trim(strip_tags($text));

        $this->assertSame('', $text, 'Visible text must come from locale keys.');
    }

    public function testNoCoreTemplateIsReplaced(): void
    {
        foreach (glob(dirname(__DIR__) . '/*.php') as $file) {
            $this->assertStringNotContainsString('TemplateResource::getFilename', (string) file_get_contents($file));
        }
    }

    public function testSourceIsWrittenInEnglishWithTheStandardHeader(): void
    {
        $files = array_merge(
            glob(dirname(__DIR__) . '/*.php'),
            glob(dirname(__DIR__) . '/classes/{,*/}*.php', GLOB_BRACE),
            glob(__DIR__ . '/*.php'),
            glob(dirname(__DIR__) . '/templates/*.tpl')
        );
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $this->assertStringContainsString('Copyright (c) 2026 OJSBR (https://ojsbr.com)', $source, basename($file) . ' lacks the header.');
            $this->assertStringNotContainsString('https://ojsbr.com' . '.br', $source, basename($file) . ' points at the old address.');
        }
    }
}
