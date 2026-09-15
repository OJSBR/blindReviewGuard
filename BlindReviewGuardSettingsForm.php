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

use APP\template\TemplateManager;
use PKP\context\Context;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class BlindReviewGuardSettingsForm extends Form
{
    public function __construct(private BlindReviewGuardPlugin $plugin, private Context $context)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * Load the current settings of the journal.
     */
    public function initData(): void
    {
        $contextId = $this->context->getId();
        foreach (array_keys(BlindReviewGuardPlugin::DEFAULT_SETTINGS) as $name) {
            $this->setData($name, $this->plugin->getSettingOrDefault($contextId, $name));
        }

        parent::initData();
    }

    /**
     * Read the submitted settings.
     */
    public function readInputData(): void
    {
        $this->readUserVars(array_keys(BlindReviewGuardPlugin::DEFAULT_SETTINGS));

        parent::readInputData();
    }

    /**
     * Render the form.
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
     * Save the settings of the journal.
     */
    public function execute(...$functionArgs)
    {
        $contextId = $this->context->getId();
        foreach (array_keys(BlindReviewGuardPlugin::DEFAULT_SETTINGS) as $name) {
            $this->plugin->updateSetting($contextId, $name, (bool) $this->getData($name), 'bool');
        }

        return parent::execute(...$functionArgs);
    }
}
