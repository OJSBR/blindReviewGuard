<?php

/**
 * @file plugins/generic/blindReviewGuard/BlindReviewGuardSettingsForm.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BlindReviewGuardSettingsForm
 *
 * @brief Which checks run, and whether the metadata is removed automatically.
 */

namespace APP\plugins\generic\blindReviewGuard;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\form\Form;

class BlindReviewGuardSettingsForm extends Form
{
    public function __construct(private BlindReviewGuardPlugin $plugin)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
        $this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        $contextId = $this->contextId();
        foreach (array_keys(BlindReviewGuardPlugin::DEFAULT_SETTINGS) as $name) {
            $this->setData($name, $this->plugin->getSettingOrDefault($contextId, $name));
        }

        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars(array_keys(BlindReviewGuardPlugin::DEFAULT_SETTINGS));

        parent::readInputData();
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $contextId = $this->contextId();
        foreach (array_keys(BlindReviewGuardPlugin::DEFAULT_SETTINGS) as $name) {
            $this->plugin->updateSetting($contextId, $name, (bool) $this->getData($name), 'bool');
        }

        return parent::execute(...$functionArgs);
    }

    private function contextId(): int
    {
        $context = Application::get()->getRequest()->getContext();

        return $context ? $context->getId() : Application::SITE_CONTEXT_ID;
    }
}
