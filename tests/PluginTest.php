<?php

/**
 * @file plugins/generic/blindReviewGuard/tests/PluginTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PluginTest
 *
 * @brief The plugin classes compiled against the PKP classes of the
 *        installation, and the invariants the design rests on.
 *
 * Skipped outside an OJS installation: the scanners are tested on their own.
 */

namespace APP\plugins\generic\blindReviewGuard\tests;

use APP\plugins\generic\blindReviewGuard\BlindReviewGuardPlugin;
use APP\plugins\generic\blindReviewGuard\BlindReviewGuardSettingsForm;
use PKP\submissionFile\SubmissionFile;
use ReflectionClass;
use ReflectionNamedType;

class PluginTest extends TestCase
{
    protected function withOjs(): bool
    {
        return class_exists('\PKP\plugins\GenericPlugin');
    }

    public function testOverriddenMethodsDeclareTheReturnTypesOfThisPkpVersion(): void
    {
        if (!$this->withOjs()) {
            $this->assertTrue(true);
            return;
        }
        // A missing or different return type on an override is a fatal error
        // that php -l does not catch: it only shows next to the parent class.
        // Loading the classes is itself the first check.
        $this->assertTrue(is_subclass_of(BlindReviewGuardPlugin::class, \PKP\plugins\GenericPlugin::class));
        $this->assertTrue(is_subclass_of(BlindReviewGuardSettingsForm::class, \PKP\form\Form::class));
        foreach ([BlindReviewGuardPlugin::class, BlindReviewGuardSettingsForm::class] as $class) {
            $reflection = new ReflectionClass($class);
            $parent = $reflection->getParentClass();
            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || !$parent->hasMethod($method->getName())) {
                    continue;
                }
                $parentType = $parent->getMethod($method->getName())->getReturnType();
                if ($parentType === null) {
                    continue;
                }
                $type = $method->getReturnType();
                $this->assertTrue(
                    $type instanceof ReflectionNamedType && $type->getName() === (string) $parentType,
                    sprintf('%s::%s() must declare the return type %s.', $reflection->getShortName(), $method->getName(), $parentType)
                );
            }
        }
    }

    public function testOnlyTheStagesAReviewerCanOpenAreInScope(): void
    {
        if (!$this->withOjs()) {
            $this->assertTrue(true);
            return;
        }
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
        $this->assertFalse(in_array(SubmissionFile::SUBMISSION_FILE_SUBMISSION, $stages, true), 'The identified version must never be in scope.');
    }

    public function testEveryDefaultSettingIsABooleanWithAFormField(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__) . '/templates/settingsForm.tpl');
        $source = (string) file_get_contents(dirname(__DIR__) . '/BlindReviewGuardPlugin.php');
        preg_match("/DEFAULT_SETTINGS = \\[(.*?)\\];/s", $source, $m);
        preg_match_all("/'(\\w+)' => (true|false)/", $m[1] ?? '', $settings);

        $this->assertCount(7, $settings[1]);
        foreach ($settings[1] as $name) {
            $this->assertStringContainsString('id="' . $name . '"', $template, "No field for {$name}.");
        }
        // Open review is out of scope unless the journal asks for it.
        $this->assertSame(1, preg_match("/'scanOpenReview' => false/", $m[1]));
    }

    public function testACleanedFileIsNeverWrittenOverTheStoredFile(): void
    {
        // The review copy shares the stored file with the author's upload. The
        // cleaned package must be stored as a new file for the copy only.
        $source = (string) file_get_contents(dirname(__DIR__) . '/BlindReviewGuardPlugin.php');

        $this->assertStringContainsString("app()->get('file')->add(", $source);
        $this->assertStringContainsString("Repo::submissionFile()->edit(\$submissionFile, ['fileId' => \$fileId])", $source);
        $this->assertSame(0, preg_match('/\b(rename|file_put_contents|fopen)\s*\(/', $source), 'The plugin must not write files on its own.');
    }

    public function testTheReviewerCheckIsLimitedToTheAssignedRound(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/BlindReviewGuardPlugin.php');
        $this->assertStringContainsString('->filterByReviewRoundIds([(int) $reviewAssignment->getReviewRoundId()])', $source);
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
}
