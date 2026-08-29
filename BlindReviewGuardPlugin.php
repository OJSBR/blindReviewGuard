<?php

/**
 * @file plugins/generic/blindReviewGuard/BlindReviewGuardPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com.br)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BlindReviewGuardPlugin
 *
 * @brief Checks the files a reviewer is about to receive for anything that
 *        identifies the authors, and optionally removes what can be removed.
 *
 * WHERE IT ACTS, AND WHY THERE
 *
 * OJS keeps a submission's files in stages, and a reviewer never sees the file
 * the author uploaded. When an editor takes the decision to send a submission to
 * review, the core COPIES the selected files into the review stage
 * (PKP\decision\steps\PromoteFiles: "allows the editor to copy files from one or
 * more file stages to a new stage"). The original stays in
 * SUBMISSION_FILE_SUBMISSION, untouched.
 *
 * That is why this plugin works on the copy, at the moment the copy is created:
 * journals that require an identified version - with the title page, the funding
 * statement and the full author list - keep it intact for the editorial team and
 * for production, while what reaches the reviewer is checked. Nothing here ever
 * touches the author's original upload.
 *
 * Two moments are covered:
 *
 *   1. SubmissionFile::add for a review stage - the copy into the review round,
 *      and the author's revised version in later rounds;
 *   2. ReviewAssignment::add - the last moment before a person outside the
 *      editorial team can open the file.
 *
 * WHEN IT STAYS QUIET
 *
 * In open review the author's name is not a leak, it is the arrangement, so the
 * plugin does nothing unless the journal asks otherwise. Files in the submission
 * stage are never scanned, never reported and never modified.
 */

namespace APP\plugins\generic\blindReviewGuard;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\blindReviewGuard\classes\FileScanner;
use APP\plugins\generic\blindReviewGuard\classes\IdentityProfile;
use APP\plugins\generic\blindReviewGuard\classes\ScanReport;
use PKP\config\Config;
use PKP\context\Context;
use PKP\core\Core;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\log\event\PKPSubmissionEventLogEntry;
use PKP\notification\Notification;
use APP\notification\NotificationManager;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Validation;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submissionFile\SubmissionFile;

class BlindReviewGuardPlugin extends GenericPlugin
{
    /**
     * The stages whose files a reviewer can open. Everything else - and above
     * all SUBMISSION_FILE_SUBMISSION, where the identified version lives - is
     * out of scope by design.
     */
    public const REVIEW_FILE_STAGES = [
        SubmissionFile::SUBMISSION_FILE_REVIEW_FILE,
        SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_FILE,
        SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION,
        SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION,
    ];

    /** Settings, with the defaults applied when the journal has never saved any. */
    public const DEFAULT_SETTINGS = [
        'checkMetadata' => true,
        'checkRevisionMarks' => true,
        'checkText' => true,
        'checkFilename' => true,
        'autoClean' => true,
        'notify' => true,
        'scanOpenReview' => false,
    ];

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        if ($this->getEnabled($mainContextId)) {
            Hook::add('SubmissionFile::add', [$this, 'checkPromotedFile']);
            Hook::add('ReviewAssignment::add', [$this, 'checkBeforeReviewerSeesIt']);
        }

        return true;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.blindReviewGuard.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.blindReviewGuard.description');
    }

    //
    // Settings
    //

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        ));

        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') === 'settings') {
            $form = new BlindReviewGuardSettingsForm($this);
            if (!$request->getUserVar('save')) {
                $form->initData();
                return new JSONMessage(true, $form->fetch($request));
            }
            $form->readInputData();
            if ($form->validate()) {
                $form->execute();
                $notificationManager = new NotificationManager();
                $notificationManager->createTrivialNotification(
                    $request->getUser()->getId(),
                    Notification::NOTIFICATION_TYPE_SUCCESS,
                    ['contents' => __('plugins.generic.blindReviewGuard.settings.saved')]
                );
                return new JSONMessage(true);
            }
            return new JSONMessage(true, $form->fetch($request));
        }

        return parent::manage($args, $request);
    }

    /**
     * Read a setting, falling back to the shipped default.
     */
    public function getSettingOrDefault(int $contextId, string $name): bool
    {
        $value = $this->getSetting($contextId, $name);

        return $value === null ? self::DEFAULT_SETTINGS[$name] : (bool) $value;
    }

    //
    // The two moments
    //

    /**
     * A file has just been copied into a review stage: check it before anyone
     * outside the editorial team can open it.
     *
     * @param array $args [$submissionFile]
     */
    public function checkPromotedFile(string $hookName, array $args): bool
    {
        $submissionFile = $args[0];
        if (!$submissionFile instanceof SubmissionFile) {
            return Hook::CONTINUE;
        }
        if (!in_array((int) $submissionFile->getData('fileStage'), self::REVIEW_FILE_STAGES, true)) {
            return Hook::CONTINUE;
        }

        $submission = Repo::submission()->get((int) $submissionFile->getData('submissionId'));
        if (!$submission) {
            return Hook::CONTINUE;
        }

        $context = $this->resolveContext($submission);
        if (!$context || !$this->shouldScan($context)) {
            return Hook::CONTINUE;
        }

        $report = $this->scan($submissionFile, $submission, $context);
        if ($report) {
            $this->record($report, $submission, $context);
        }

        return Hook::CONTINUE;
    }

    /**
     * A reviewer has just been assigned. Anything still identifying in the round
     * is now one click away from a person outside the editorial team, so this is
     * the last useful warning.
     *
     * @param array $args [$reviewAssignment]
     */
    public function checkBeforeReviewerSeesIt(string $hookName, array $args): bool
    {
        $reviewAssignment = $args[0];
        if (!$reviewAssignment instanceof ReviewAssignment) {
            return Hook::CONTINUE;
        }
        // In open review there is nothing to protect.
        if ((int) $reviewAssignment->getReviewMethod() === ReviewAssignment::SUBMISSION_REVIEW_METHOD_OPEN) {
            return Hook::CONTINUE;
        }

        $submission = Repo::submission()->get((int) $reviewAssignment->getSubmissionId());
        if (!$submission) {
            return Hook::CONTINUE;
        }
        $context = $this->resolveContext($submission);
        if (!$context) {
            return Hook::CONTINUE;
        }

        $files = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->filterByFileStages(self::REVIEW_FILE_STAGES)
            ->getMany();

        foreach ($files as $file) {
            // Nothing is cleaned here: by now the copy has been through the
            // first check, and rewriting a file under a reviewer's feet would be
            // worse than telling the editor about it.
            $report = $this->scan($file, $submission, $context, false);
            if ($report && $report->hasFindings()) {
                $this->notify($report, $submission, 'plugins.generic.blindReviewGuard.notification.beforeReviewer');
            }
        }

        return Hook::CONTINUE;
    }

    //
    // Internals
    //

    /**
     * Whether this journal wants files checked at all.
     */
    private function shouldScan(Context $context): bool
    {
        if ($this->getSettingOrDefault($context->getId(), 'scanOpenReview')) {
            return true;
        }

        return (int) $context->getData('defaultReviewMode') !== ReviewAssignment::SUBMISSION_REVIEW_METHOD_OPEN;
    }

    /**
     * @param bool $allowClean Pass false to inspect without modifying anything
     */
    private function scan(SubmissionFile $submissionFile, $submission, Context $context, bool $allowClean = true): ?ScanReport
    {
        $profile = $this->buildProfile($submission);
        if ($profile->isEmpty()) {
            return null;
        }

        $path = $this->resolvePath($submissionFile);
        if (!$path) {
            return null;
        }

        $contextId = $context->getId();
        $checks = [
            'metadata' => $this->getSettingOrDefault($contextId, 'checkMetadata'),
            'revisionMarks' => $this->getSettingOrDefault($contextId, 'checkRevisionMarks'),
            'text' => $this->getSettingOrDefault($contextId, 'checkText'),
            'filename' => $this->getSettingOrDefault($contextId, 'checkFilename'),
        ];
        $autoClean = $allowClean && $this->getSettingOrDefault($contextId, 'autoClean');

        $filename = $submissionFile->getLocalizedData('name') ?: basename($path);

        return (new FileScanner())->scan($path, $filename, $profile, $checks, $autoClean, (int) $submissionFile->getId());
    }

    /**
     * The identifying strings of this submission's contributors.
     */
    private function buildProfile($submission): IdentityProfile
    {
        $profile = new IdentityProfile();
        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            return $profile;
        }

        // Read the contributors from the repository rather than from
        // $publication->getData('authors'): a publication obtained through
        // Repo::submission()->get() does not necessarily carry them hydrated,
        // and the property can hold a lazy placeholder instead of Author objects.
        $authors = Repo::author()->getCollector()
            ->filterByPublicationIds([$publication->getId()])
            ->getMany();

        foreach ($authors as $author) {
            $affiliations = [];
            foreach ($author->getAffiliations() as $affiliation) {
                $names = $affiliation->getName(null);
                foreach ((array) (is_array($names) ? $names : [$names]) as $name) {
                    if ($name) {
                        $affiliations[] = (string) $name;
                    }
                }
            }

            // Every locale of the name matters: a submission in Portuguese with
            // English metadata leaks in whichever of the two the author typed.
            $givenNames = (array) $author->getGivenName(null);
            $familyNames = (array) $author->getFamilyName(null);
            foreach ($givenNames as $locale => $givenName) {
                $profile->addAuthor(
                    (string) $givenName,
                    (string) ($familyNames[$locale] ?? ''),
                    $author->getEmail(),
                    $author->getOrcid(),
                    $affiliations
                );
            }
        }

        return $profile;
    }

    /**
     * Absolute path of the file on disk, or null when it cannot be located.
     */
    private function resolvePath(SubmissionFile $submissionFile): ?string
    {
        $fileId = $submissionFile->getData('fileId');
        if (!$fileId) {
            return null;
        }

        $file = app()->get('file')->get((int) $fileId);
        if (!$file || !$file->path) {
            return null;
        }

        $path = rtrim(Config::getVar('files', 'files_dir'), '/') . '/' . ltrim($file->path, '/');

        return is_readable($path) ? $path : null;
    }

    private function resolveContext($submission): ?Context
    {
        $request = Application::get()->getRequest();
        $context = $request ? $request->getContext() : null;
        if ($context && $context->getId() === (int) $submission->getData('contextId')) {
            return $context;
        }

        return Application::getContextDAO()->getById((int) $submission->getData('contextId'));
    }

    /**
     * Write the result to the submission's activity log and, if asked, put it in
     * front of whoever is doing the work right now.
     */
    private function record(ScanReport $report, $submission, Context $context): void
    {
        $remaining = $report->remaining();
        $cleaned = $report->cleaned;

        if (empty($remaining) && empty($cleaned)) {
            // Nothing to say. The silence is the point: a report that fires on
            // every file is a report editors stop reading.
            return;
        }

        $this->log($submission, $report, $remaining, $cleaned, $context);

        if (!empty($remaining)) {
            $this->notify($report, $submission, 'plugins.generic.blindReviewGuard.notification.findings');
        }
    }

    private function log($submission, ScanReport $report, array $remaining, array $cleaned, Context $context): void
    {
        // The message is composed here, already translated, instead of being
        // stored as a key plus parameters. The event log persists only the
        // properties declared in schemas/eventLog.json - "filename" survives,
        // but a custom "details" or "total" is silently dropped - and a report
        // that loses its own findings on the way to the log is worthless.
        // It is written in the journal's primary locale so that the entry reads
        // the same for everyone, whatever language the editor happened to use.
        $locale = $context->getPrimaryLocale();
        $key = empty($remaining)
            ? 'plugins.generic.blindReviewGuard.log.cleaned'
            : 'plugins.generic.blindReviewGuard.log.findings';

        $eventLog = Repo::eventLog()->newDataObject([
            'assocType' => PKPApplication::ASSOC_TYPE_SUBMISSION,
            'assocId' => $submission->getId(),
            'eventType' => PKPSubmissionEventLogEntry::SUBMISSION_LOG_METADATA_UPDATE,
            'userId' => Validation::loggedInAs() ?? Application::get()->getRequest()?->getUser()?->getId(),
            'message' => __($key, [
                'filename' => $report->filename,
                // Not named "count": that parameter name is reserved by the ICU
                // message formatter and would turn the string into a plural rule.
                'total' => count($remaining),
                'removed' => count($cleaned),
                'details' => $this->summarise($remaining ?: $cleaned),
            ], $locale),
            'isTranslated' => true,
            'dateLogged' => Core::getCurrentDate(),
            'filename' => $report->filename,
            'submissionFileId' => $report->submissionFileId,
        ]);
        Repo::eventLog()->add($eventLog);
    }

    private function notify(ScanReport $report, $submission, string $key): void
    {
        $request = Application::get()->getRequest();
        $user = $request ? $request->getUser() : null;
        if (!$user) {
            return;
        }

        $context = $this->resolveContext($submission);
        if ($context && !$this->getSettingOrDefault($context->getId(), 'notify')) {
            return;
        }

        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification(
            $user->getId(),
            Notification::NOTIFICATION_TYPE_WARNING,
            ['contents' => __($key, [
                'filename' => htmlspecialchars($report->filename),
                'details' => htmlspecialchars($this->summarise($report->remaining())),
            ])]
        );
    }

    /**
     * A one-line, human summary: "dc:creator: Maria Souza; comment: Joao Pereira".
     */
    private function summarise(array $findings): string
    {
        $parts = [];
        foreach (array_slice($findings, 0, 6) as $finding) {
            $parts[] = $finding->where . ': ' . $finding->match;
        }
        if (count($findings) > 6) {
            $parts[] = '...';
        }

        return implode('; ', $parts);
    }
}
