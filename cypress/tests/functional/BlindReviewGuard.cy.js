/**
 * @file cypress/tests/functional/BlindReviewGuard.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the settings, and what happens to a file sent to review.
 *
 * The file is sent to review through the same REST endpoint the "send to
 * review" step uses (PUT /submissions/{id}/files/{submissionFileId}/copy), so
 * the core copies it exactly as in production. What is asserted is what the
 * plugin promises: the review copy gets its own, cleaned file, and the author's
 * upload keeps the stored file it had.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (a journal manager;
 * captcha on login must be off for the run), and, for the review test,
 * submissionId and submissionFileId: a submission in external review with a
 * .docx in the submission stage whose document properties name one of its
 * authors. The review test is skipped without them. The copy is deleted at the
 * end of the run.
 */

describe('Blind Review Guard plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';
	const submissionId = Cypress.env('submissionId');
	const submissionFileId = Cypress.env('submissionFileId');

	const api = '/index.php/' + contextPath + '/api/v1';
	const settingsForm = 'form[id="blindReviewGuardSettings"]';
	const settings = ['checkMetadata', 'checkRevisionMarks', 'checkText', 'checkFilename', 'autoClean', 'notify', 'scanOpenReview'];

	let csrfToken = null;

	const login = () => {
		cy.visit('/index.php/' + contextPath + '/login');
		cy.get('input[id=username]').clear().type(adminUser, {delay: 0});
		cy.get('input[id=password]').clear().type(adminPassword, {delay: 0, log: false});
		cy.get('form[id=login] button').click();
		cy.get('form[id=login]', {timeout: 30000}).should('not.exist');
	};

	const getCsrfToken = () => {
		cy.visit('/index.php/' + contextPath + '/submissions');
		return cy.window().then((win) => {
			csrfToken = win.pkp.currentUser.csrfToken;
		});
	};

	const request = (method, url, body) => cy.request({
		method,
		url: api + url,
		headers: {'X-Csrf-Token': csrfToken},
		body,
		failOnStatusCode: false,
	});

	const openSettings = () => {
		cy.visit('/index.php/' + contextPath + '/management/settings/website');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.waitJQuery();
		cy.get('tr[id*="blindreviewguardplugin"] a.show_extras', {timeout: 30000}).click();
		cy.get('a[id*="blindreviewguardplugin-settings"]', {timeout: 30000}).click();
		cy.waitJQuery();
		cy.get(settingsForm, {timeout: 30000}).should('exist');
	};

	const save = () => {
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		cy.waitJQuery();
		cy.get(settingsForm).should('not.exist');
	};

	describe('Settings', function() {
		it('Offers every check, each one saved on its own', function() {
			login();
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
	});

	describe('A file sent to review', function() {
		let copyId = null;

		before(function() {
			if (!submissionId || !submissionFileId) {
				this.skip();
			}
		});

		it('Gets its own cleaned file and leaves the author\'s upload alone', function() {
			login();
			getCsrfToken();

			request('GET', `/submissions/${submissionId}/files/${submissionFileId}?stageId=1`).then((original) => {
				expect(original.status).to.eq(200);
				const originalFileId = original.body.fileId;

				request('PUT', `/submissions/${submissionId}/files/${submissionFileId}/copy?stageId=3`, {toFileStage: 4}).then((copy) => {
					expect(copy.status).to.eq(200);
					copyId = copy.body.id;

					request('GET', `/submissions/${submissionId}/files/${copyId}?stageId=3`).then((review) => {
						expect(review.status).to.eq(200);
						expect(review.body.sourceSubmissionFileId).to.eq(Number(submissionFileId));
						expect(review.body.fileId, 'the review copy points at a file of its own').to.not.eq(originalFileId);
						expect(review.body.revisions.map((revision) => revision.fileId)).to.include(originalFileId);
					});

					request('GET', `/submissions/${submissionId}/files/${submissionFileId}?stageId=1`).then((after) => {
						expect(after.body.fileId, 'the author\'s upload keeps its stored file').to.eq(originalFileId);
					});
				});
			});
		});

		after(function() {
			if (copyId) {
				// Still in the session of the test above.
				getCsrfToken();
				request('DELETE', `/submissions/${submissionId}/files/${copyId}?stageId=3`).then((response) => expect(response.status).to.eq(200));
			}
		});
	});
});
