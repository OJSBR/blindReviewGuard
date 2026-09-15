/**
 * @file cypress/tests/functional/BlindReviewGuard.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the settings, and what happens to a file sent to review.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (a journal manager;
 * captcha on login must be off for the run). The defaults match the data set of
 * PKP's continuous integration, and the first test enables the plugin when it is
 * off. Every setting touched is put back as it was.
 *
 * The review test also needs submissionId and submissionFileId: a submission in
 * external review with a .docx in the submission stage whose document properties
 * name one of its authors. It is skipped without them. The file is sent to review
 * through the same REST endpoint the "send to review" step uses
 * (PUT /submissions/{id}/files/{submissionFileId}/copy), so the core copies it
 * exactly as in production, and the copy is deleted at the end.
 */

describe('Blind Review Guard plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';
	const submissionId = Cypress.env('submissionId');
	const submissionFileId = Cypress.env('submissionFileId');

	const rowName = 'blindreviewguardplugin';
	const settingsForm = 'form[id="blindReviewGuardSettings"]';
	const settings = ['checkMetadata', 'checkRevisionMarks', 'checkText', 'checkFilename', 'autoClean', 'notify', 'scanOpenReview'];

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.3, 3.4 and 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(), which the support files of OJS 3.3 test sites may lack.
	const waitJQuery = () => cy.window().its('jQuery.active').should('eq', 0);

	// Requests carry the browser's User-Agent: OJS 3.3 drops a session whose agent changes.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// Signs in through requests (the login page can re-render while it is typed into), then
	// falls back to the form when the session did not stick (OJS 3.3 cookie handling).
	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	// REST API calls made from the page itself, so they carry the browser's own session.
	const api = (path, options = {}) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(path + ' answered ' + response.status + ': ' + text.slice(0, 300));
				});
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	// The website settings page on its Plugins tab (a new query string forces a load). Load it
	// once per test: loading it again while its plugin gallery request is pending stalls the
	// web server of PKP's CI; API calls and settings modals work on the page already open.
	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	// Enables the plugin in the grid when it is off (never turns it off).
	const enablePlugin = (rowName) => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	// Opens the settings modal from the grid, without reloading the page: a reload right
	// after saving can stall the web server of PKP's CI. The form is fetched each time.
	const openPluginSettings = (rowName, formSelector) => {
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]', {timeout: 30000}).then(($link) => {
			if (!$link.is(':visible')) {
				cy.get('tr[id$="-row-' + rowName + '"] a.show_extras').first().click();
			}
		});
		// The grid may still be animating the extras row: the link is clicked once it exists.
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]').first().click({force: true});
		waitJQuery();
		cy.window().should((win) => {
			expect(win.jQuery(formSelector).data('pkp.handler')).to.exist;
		});
	};

	// ---- end of helpers ----

	const openSettings = () => openPluginSettings(rowName, settingsForm);

	const save = () => {
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		waitJQuery();
		cy.get(settingsForm).should('not.exist');
	};

	it('Offers every check, each one saved on its own', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin(rowName);
		openSettings();
		settings.forEach((name) => cy.get(settingsForm + ' input[name="' + name + '"]').should('have.length', 1));

		cy.get(settingsForm + ' input[name="checkFilename"]').then(($input) => {
			const wasChecked = $input.is(':checked');
			cy.get(settingsForm + ' input[name="checkFilename"]').click({force: true});
			save();

			openSettings();
			cy.get(settingsForm + ' input[name="checkFilename"]').should(wasChecked ? 'not.be.checked' : 'be.checked');
			cy.get(settingsForm + ' input[name="autoClean"]').should('exist');

			// Put it back as it was.
			cy.get(settingsForm + ' input[name="checkFilename"]').click({force: true});
			save();
			openSettings();
			cy.get(settingsForm + ' input[name="checkFilename"]').should(wasChecked ? 'be.checked' : 'not.be.checked');
		});
	});

	describe('A file sent to review', function() {
		let copyId = null;
		const submissionApi = (path) => pageUrl('api/v1/submissions/' + submissionId + '/files/' + path);
		const withToken = (method, body) => cy.window({log: false}).then((win) => ({
			method,
			headers: {'Content-Type': 'application/json', 'X-Csrf-Token': win.pkp.currentUser.csrfToken},
			body: body ? JSON.stringify(body) : undefined,
		}));

		(submissionId && submissionFileId ? it : it.skip)('Gets its own cleaned file and leaves the author\'s upload alone', function() {
			login(adminUser, adminPassword);

			api(submissionApi(submissionFileId + '?stageId=1')).then((original) => {
				const originalFileId = original.fileId;

				withToken('PUT', {toFileStage: 4}).then((options) => api(submissionApi(submissionFileId + '/copy?stageId=3'), options)).then((copy) => {
					copyId = copy.id;

					api(submissionApi(copyId + '?stageId=3')).then((review) => {
						expect(review.sourceSubmissionFileId).to.eq(Number(submissionFileId));
						expect(review.fileId, 'the review copy points at a file of its own').to.not.eq(originalFileId);
						expect(review.revisions.map((revision) => revision.fileId)).to.include(originalFileId);
					});

					api(submissionApi(submissionFileId + '?stageId=1')).then((after) => {
						expect(after.fileId, 'the author\'s upload keeps its stored file').to.eq(originalFileId);
					});
				});
			});
		});

		after(function() {
			if (copyId) {
				// Still on the page of the test above, in its session.
				withToken('DELETE').then((options) => api(submissionApi(copyId + '?stageId=3'), options));
			}
		});
	});
});
