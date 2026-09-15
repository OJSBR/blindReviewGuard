<?php

/**
 * @file plugins/generic/blindReviewGuard/tests/BlindReviewGuardPluginTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BlindReviewGuardPluginTest
 *
 * @brief The invariants the plugin rests on: which files are in scope, where the
 *        hooks are registered, what the site level offers and what is never written.
 */

namespace APP\plugins\generic\blindReviewGuard\tests;

use APP\plugins\generic\blindReviewGuard\BlindReviewGuardPlugin;
use APP\plugins\generic\blindReviewGuard\BlindReviewGuardSettingsForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\plugins\Hook;
use PKP\submissionFile\SubmissionFile;
use PKP\tests\PKPTestCase;

#[CoversClass(BlindReviewGuardPlugin::class)]
#[CoversClass(BlindReviewGuardSettingsForm::class)]
class BlindReviewGuardPluginTest extends PKPTestCase
{
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/BlindReviewGuardPlugin.php');
    }

    public function testOnlyTheStagesAReviewerCanOpenAreInScope(): void
    {
        $stages = BlindReviewGuardPlugin::REVIEW_FILE_STAGES;
        sort($stages);
        $expected = [
            SubmissionFile::SUBMISSION_FILE_REVIEW_FILE,
            SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION,
            SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_FILE,
            SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION,
        ];
        sort($expected);

        $this->assertSame($expected, $stages);
        $this->assertNotContains(SubmissionFile::SUBMISSION_FILE_SUBMISSION, $stages, 'The identified version must never be in scope.');
    }

    public function testAFileOutsideTheReviewStagesIsLeftAlone(): void
    {
        $submissionFile = new SubmissionFile();
        $submissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_SUBMISSION);
        // No submission exists with this id: reaching the repository would fail the test.
        $submissionFile->setData('submissionId', PHP_INT_MAX);

        $this->assertSame(Hook::CONTINUE, (new BlindReviewGuardPlugin())->checkPromotedFile('SubmissionFile::add', [$submissionFile]));
    }

    public function testTheHooksAreRegisteredEvenWhenNoJournalIsInTheRequest(): void
    {
        // pkp/pkp-lib#11793: files created from the command line or a queued job
        // have no journal in the request, so the check belongs in the callbacks.
        $source = $this->source();
        preg_match('/function register\(.*?\n    \}/s', $source, $register);

        $this->assertStringContainsString("Hook::add('SubmissionFile::add'", $register[0]);
        $this->assertStringContainsString("Hook::add('ReviewAssignment::add'", $register[0]);
        $this->assertStringNotContainsString('getEnabled', $register[0]);
        $this->assertSame(2, substr_count($source, '!$this->getEnabled($context->getId())'), 'Each callback must check the journal of the submission.');
    }

    public function testTheSiteLevelHasNoSettingsToOpen(): void
    {
        $request = new class () {
            public function getContext()
            {
                return null;
            }

            public function getUserVar($name)
            {
                return $name === 'verb' ? 'settings' : null;
            }

            public function getRouter()
            {
                throw new \RuntimeException('The site level must not build a settings URL.');
            }
        };
        $plugin = new class () extends BlindReviewGuardPlugin {
            public function getEnabled($contextId = null)
            {
                return true;
            }
        };

        $this->assertSame([], array_filter($plugin->getActions($request, []), fn ($action) => $action->getId() === 'settings'));
        // Like any action the plugin does not handle, it is left to the core, which refuses it.
        $this->expectExceptionMessage('Unhandled management action!');
        $plugin->manage([], $request);
    }

    public function testEveryDefaultSettingIsABooleanWithAFormField(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__) . '/templates/settingsForm.tpl');

        $this->assertCount(7, BlindReviewGuardPlugin::DEFAULT_SETTINGS);
        foreach (BlindReviewGuardPlugin::DEFAULT_SETTINGS as $name => $default) {
            $this->assertIsBool($default, "{$name} is not a boolean.");
            $this->assertStringContainsString('id="' . $name . '"', $template, "No field for {$name}.");
        }
        // Open review is out of scope unless the journal asks for it.
        $this->assertFalse(BlindReviewGuardPlugin::DEFAULT_SETTINGS['scanOpenReview']);
    }

    public function testACleanedFileIsNeverWrittenOverTheStoredFile(): void
    {
        // The review copy shares the stored file with the author's upload. The
        // cleaned package must be stored as a new file for the copy only.
        $source = $this->source();

        $this->assertStringContainsString("app()->get('file')->add(", $source);
        $this->assertStringContainsString("Repo::submissionFile()->edit(\$submissionFile, ['fileId' => \$fileId])", $source);
        $this->assertSame(0, preg_match('/\b(rename|file_put_contents|fopen)\s*\(/', $source), 'The plugin must not write files on its own.');
    }

    public function testTheReviewerCheckIsLimitedToTheAssignedRound(): void
    {
        $this->assertStringContainsString('->filterByReviewRoundIds([(int) $reviewAssignment->getReviewRoundId()])', $this->source());
    }

    public function testNoAuthorDataIsWrittenToTheServerLog(): void
    {
        foreach (array_merge(glob(dirname(__DIR__) . '/*.php'), glob(dirname(__DIR__) . '/classes/{,*/}*.php', GLOB_BRACE)) as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $number => $line) {
                if (!str_contains($line, 'error_log(')) {
                    continue;
                }
                foreach (['summarise', 'findings', 'match', 'getEmail', 'getGivenName', 'getFamilyName', 'filename'] as $personal) {
                    $this->assertStringNotContainsString($personal, $line, basename($file) . ':' . ($number + 1) . ' logs author data.');
                }
            }
        }
    }

    public function testTranslationsCarryNoMarkup(): void
    {
        foreach (glob(dirname(__DIR__) . '/locale/*/locale.po') as $file) {
            $po = new PoFile($file);
            foreach ($po->entries as $key => $value) {
                $this->assertSame(strip_tags($value), $value, "Markup in {$key} (" . basename(dirname($file)) . ').');
            }
        }
    }
}
