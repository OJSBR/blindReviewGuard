{**
 * plugins/generic/blindReviewGuard/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Settings of the Blind Review Guard plugin.
 *}
<script>
	$(function() {ldelim}
		$('#blindReviewGuardSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="blindReviewGuardSettings" method="POST"
	action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}

	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="blindReviewGuardSettingsNotification"}

	{fbvFormArea id="blindReviewGuardChecks" title="plugins.generic.blindReviewGuard.settings.checks"}
		{fbvFormSection list=true description="plugins.generic.blindReviewGuard.settings.checks.description"}
			{fbvElement type="checkbox" id="checkMetadata" label="plugins.generic.blindReviewGuard.settings.checkMetadata" checked=$checkMetadata}
			{fbvElement type="checkbox" id="checkRevisionMarks" label="plugins.generic.blindReviewGuard.settings.checkRevisionMarks" checked=$checkRevisionMarks}
			{fbvElement type="checkbox" id="checkText" label="plugins.generic.blindReviewGuard.settings.checkText" checked=$checkText}
			{fbvElement type="checkbox" id="checkFilename" label="plugins.generic.blindReviewGuard.settings.checkFilename" checked=$checkFilename}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="blindReviewGuardBehaviour" title="plugins.generic.blindReviewGuard.settings.behaviour"}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="autoClean" label="plugins.generic.blindReviewGuard.settings.autoClean" checked=$autoClean}
			{fbvElement type="checkbox" id="notify" label="plugins.generic.blindReviewGuard.settings.notify" checked=$notify}
			{fbvElement type="checkbox" id="scanOpenReview" label="plugins.generic.blindReviewGuard.settings.scanOpenReview" checked=$scanOpenReview}
		{/fbvFormSection}
		<p class="pkp_help">{translate key="plugins.generic.blindReviewGuard.settings.autoClean.description"}</p>
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>
