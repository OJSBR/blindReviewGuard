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

	// REST calls made from the page, carrying its session and token.
	const send = (path, method, body) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, {
			method: method,
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-Csrf-Token': win.pkp.currentUser.csrfToken},
			body: body === undefined ? undefined : JSON.stringify(body),
		}).then((response) => response.json().then((answer) => ({status: response.status, body: answer}))),
		{log: false, timeout: 60000}
	));

	// The bytes of a submission file, as the download of the interface delivers
	// them, read as text: the parts of the document are stored uncompressed, so
	// what it says is readable here.
	const contentsOf = (submissionId, submissionFileId, stageId) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(pageUrl('$$$call$$$/api/file/file-api/download-file')
			+ '?submissionFileId=' + submissionFileId
			+ '&submissionId=' + submissionId
			+ '&stageId=' + stageId
			+ '&inline=1', {credentials: 'same-origin'})
			.then((response) => response.arrayBuffer())
			.then((buffer) => new win.TextDecoder('latin1').decode(new Uint8Array(buffer))),
		{log: false, timeout: 60000}
	));

	// Turns the given checks on, leaving the rest as the journal has them.
	const configure = (values) => {
		openPluginsTab();
		openSettings();
		Object.keys(values).forEach((name) => {
			cy.get(settingsForm + ' input[name="' + name + '"]')[values[name] ? 'check' : 'uncheck']({force: true});
		});
		save();
	};

	// Submissions made by the tests, deleted in after() even when one fails.
	const madeHere = [];

	// The kinds of file the journal declares, read from the page of the wizard, so
	// that no id of any data set is written into the test.
	const genresOf = (submissionId) => request(pageUrl('submission') + '?id=' + submissionId).then((page) => {
		const at = String(page.body).indexOf('"genres":');
		expect(at, 'the page of the wizard names the kinds of file').to.be.greaterThan(-1);
		const text = String(page.body).slice(at + '"genres":'.length);
		let depth = 0;
		let end = -1;
		for (let i = 0; i < text.length; i++) {
			if (text[i] === '[') {
				depth++;
			} else if (text[i] === ']') {
				depth--;
				if (depth === 0) {
					end = i + 1;
					break;
				}
			}
		}

		return cy.wrap(JSON.parse(text.slice(0, end).replace(/&quot;/g, '"')).slice(0, 12), {log: false});
	});

	const uploadDocx = (made, genreId) => cy.window({log: false}).then((win) => cy.wrap(
		(async () => {
			const form = new win.FormData();
			form.append('file', new win.File([aDocxWithTheAuthorInIt(win)], 'ojsbr-author.docx', {
				type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			}));
			form.append('fileStage', '2');
			form.append('genreId', String(genreId));
			form.append('name[' + made.locale + ']', 'ojsbr-author.docx');
			const response = await win.fetch(pageUrl('api/v1/submissions/' + made.submissionId + '/files'), {
				method: 'POST',
				credentials: 'same-origin',
				headers: {'X-Csrf-Token': win.pkp.currentUser.csrfToken},
				body: form,
			});

			return {status: response.status, body: await response.json()};
		})(),
		{log: false, timeout: 60000}
	));

	// The author group of the journal, from the submission itself or from the page
	// of the wizard, which names it for the contributor form.
	const authorGroupId = (publication, submissionId) => {
		if ((publication.authors || []).length) {
			return cy.wrap(publication.authors[0].userGroupId, {log: false});
		}

		return request(pageUrl('submission') + '?id=' + submissionId).then((response) => {
			const found = /userGroupId(?:&quot;|")[\s\S]{0,600}?(?:&quot;|")value(?:&quot;|")\s*:\s*(\d+)/.exec(response.body);
			expect(found, 'the page of the wizard names an author group').to.not.eq(null);

			return cy.wrap(Number(found[1]), {log: false});
		});
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

	// A .docx made in the browser: a zip whose entries are stored, not compressed,
	// so what it carries — the name of the author in the properties of the
	// document — is readable in the bytes of the file. That is what lets the test
	// see, without any library, whether the plugin took it out.
	// The plugin looks for the names of the contributors of that submission,
	// not for any name: the test puts one of them inside the file.
	const AUTHOR_GIVEN = 'Ojsbr';
	const AUTHOR_FAMILY = 'Blindauthor';
	const AUTHOR_IN_THE_FILE = AUTHOR_GIVEN + ' ' + AUTHOR_FAMILY;

	const storedZip = (win, entries) => {
		const encoder = new win.TextEncoder();
		const table = [];
		for (let i = 0; i < 256; i++) {
			let value = i;
			for (let bit = 0; bit < 8; bit++) {
				value = value & 1 ? (value >>> 1) ^ 0xEDB88320 : value >>> 1;
			}
			table[i] = value >>> 0;
		}
		const crc32 = (bytes) => {
			let crc = 0xFFFFFFFF;
			for (let i = 0; i < bytes.length; i++) {
				crc = (crc >>> 8) ^ table[(crc ^ bytes[i]) & 0xFF];
			}
			return (crc ^ 0xFFFFFFFF) >>> 0;
		};
		const parts = [];
		const central = [];
		let offset = 0;
		const number = (value, size) => {
			const out = new Uint8Array(size);
			for (let i = 0; i < size; i++) {
				out[i] = (value >>> (8 * i)) & 0xFF;
			}
			return out;
		};
		const join = (chunks) => {
			const total = chunks.reduce((sum, chunk) => sum + chunk.length, 0);
			const out = new Uint8Array(total);
			let at = 0;
			chunks.forEach((chunk) => {
				out.set(chunk, at);
				at += chunk.length;
			});
			return out;
		};

		entries.forEach(({name, content}) => {
			const nameBytes = encoder.encode(name);
			const data = encoder.encode(content);
			const crc = crc32(data);
			const header = join([
				number(0x04034b50, 4), number(20, 2), number(0, 2), number(0, 2), number(0, 2), number(0, 2),
				number(crc, 4), number(data.length, 4), number(data.length, 4),
				number(nameBytes.length, 2), number(0, 2), nameBytes,
			]);
			parts.push(header, data);
			central.push(join([
				number(0x02014b50, 4), number(20, 2), number(20, 2), number(0, 2), number(0, 2), number(0, 2), number(0, 2),
				number(crc, 4), number(data.length, 4), number(data.length, 4),
				number(nameBytes.length, 2), number(0, 2), number(0, 2), number(0, 2), number(0, 2), number(0, 4),
				number(offset, 4), nameBytes,
			]));
			offset += header.length + data.length;
		});

		const directory = join(central);
		const end = join([
			number(0x06054b50, 4), number(0, 2), number(0, 2),
			number(entries.length, 2), number(entries.length, 2),
			number(directory.length, 4), number(offset, 4), number(0, 2),
		]);

		return new win.Blob([join(parts), directory, end], {type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'});
	};

	const aDocxWithTheAuthorInIt = (win) => storedZip(win, [
		{name: '[Content_Types].xml', content: '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			+ '<Default Extension="xml" ContentType="application/xml"/>'
			+ '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
			+ '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>'},
		{name: '_rels/.rels', content: '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			+ '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
			+ '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>'},
		{name: 'docProps/core.xml', content: '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties '
			+ 'xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/">'
			+ '<dc:creator>' + AUTHOR_IN_THE_FILE + '</dc:creator><cp:lastModifiedBy>' + AUTHOR_IN_THE_FILE + '</cp:lastModifiedBy></cp:coreProperties>'},
		{name: 'word/document.xml', content: '<?xml version="1.0" encoding="UTF-8"?><w:document '
			+ 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Text of the test.</w:t></w:r></w:p></w:body></w:document>'},
	]);

	describe('A file sent to review', function() {
		let copyId = null;
		const submissionApi = (path) => pageUrl('api/v1/submissions/' + submissionId + '/files/' + path);
		const withToken = (method, body) => cy.window({log: false}).then((win) => ({
			method,
			headers: {'Content-Type': 'application/json', 'X-Csrf-Token': win.pkp.currentUser.csrfToken},
			body: body ? JSON.stringify(body) : undefined,
		}));

		// What the plugin is for: the copy the reviewer opens carries no name of
		// the author, and the file the author sent is left exactly as it was.
		// The submission, the file and its contents are made by the test.
		it('Takes the author out of the copy the reviewer gets, and leaves the upload alone', function() {
			login(adminUser, adminPassword);
			// Every check on, and the cleaning: this is about the cleaning itself.
			cy.then(() => configure({checkMetadata: true, autoClean: true}));
			cy.visit(pageUrl('submissions') + '?reload=' + Date.now());

			const made = {};
			cy.window({log: false}).its('pkp.context.primaryLocale').then((locale) => {
				made.locale = locale;

				return request({url: pageUrl('api/v1/sections?count=1'), failOnStatusCode: false});
			}).then((response) => {
				let body = response.body;
				if (typeof body === 'string') {
					try {
						body = JSON.parse(body);
					} catch (error) {
						body = {};
					}
				}
				const sectionId = (body && body.items && body.items.length) ? body.items[0].id : null;

				return send(pageUrl('api/v1/submissions'), 'POST', sectionId ? {locale: made.locale, sectionId: sectionId} : {locale: made.locale});
			}).then((created) => {
				expect(created.status, 'the submission of the test was created: ' + JSON.stringify(created.body)).to.be.within(200, 201);
				made.submissionId = created.body.id;
				made.publicationId = created.body.currentPublicationId;
				madeHere.push(created.body.id);

				// A contributor whose name is the one carried inside the file.
				// What other plugins of the journal ask of a contributor is sent
				// along, so the test reports on this plugin only.
				return api(pageUrl('api/v1/submissions/' + made.submissionId + '/publications/' + made.publicationId))
					.then((publication) => authorGroupId(publication, made.submissionId))
					.then((groupId) => {
						const contributor = {
							givenName: {[made.locale]: AUTHOR_GIVEN},
							familyName: {[made.locale]: AUTHOR_FAMILY},
							email: 'blindauthor' + Date.now().toString().slice(-8) + '@mailinator.com',
							country: 'BR',
							affiliations: [{name: {[made.locale]: 'OJSBR'}}],
							biography: {[made.locale]: '<p>Contributor of the test.</p>'},
							userGroupId: groupId,
						};
						const url = pageUrl('api/v1/submissions/' + made.submissionId + '/publications/' + made.publicationId + '/contributors');

						// An iD is sent only where the journal refuses the contributor
						// for want of one: the core of 3.5 turns it down by itself,
						// and a plugin of the journal may ask for it.
						return send(url, 'POST', contributor).then((answer) => (
							answer.status === 400 && answer.body && answer.body.orcid
								&& !/not permitted/i.test(JSON.stringify(answer.body.orcid))
								? send(url, 'POST', Object.assign({}, contributor, {orcid: 'https://orcid.org/0000-0002-1825-0097'}))
								: cy.wrap(answer, {log: false})
						));
					})
					.then((contributor) => {
						expect(contributor.status, 'the contributor of the test was added: ' + JSON.stringify(contributor.body))
							.to.be.within(200, 201);

						return cy.wrap(made, {log: false});
					});
			}).then(() => {

				// The file of the author, with their name inside it. A journal may
				// ask for a file of more than one kind before it takes the
				// submission, so one is sent under each kind the page of the
				// wizard offers; the first one is the one sent to review.
				return genresOf(made.submissionId).then((genres) => {
					genres.forEach((genre) => {
						uploadDocx(made, genre.id).then((uploaded) => {
							expect(uploaded.status, 'the file of the author was uploaded: ' + JSON.stringify(uploaded.body))
								.to.be.within(200, 201);
							if (!made.fileId) {
								made.fileId = uploaded.body.id;
								made.storedFileId = uploaded.body.fileId;
							}
						});
					});

					return cy.then(() => made);
				});
			}).then(() => {

				// The submission is completed and sent to review, which is the
				// editorial path that puts a file in front of a reviewer.
				return send(
					pageUrl('api/v1/submissions/' + made.submissionId + '/publications/' + made.publicationId),
					'PUT',
					{
						title: {[made.locale]: 'OJSBR blindReviewGuard ' + Date.now()},
						abstract: {[made.locale]: '<p>Abstract of the test of the blind review guard.</p>'},
					}
				).then(() => send(pageUrl('api/v1/submissions/' + made.submissionId + '/submit'), 'PUT', {}))
					.then((submitted) => {
						expect(submitted.status, 'the submission was completed: ' + JSON.stringify(submitted.body)).to.eq(200);

						return send(pageUrl('api/v1/submissions/' + made.submissionId + '/decisions'), 'POST', {decision: 3});
					})
					.then((decided) => {
						expect(decided.status, 'the submission was sent to review: ' + JSON.stringify(decided.body)).to.be.within(200, 201);

						return api(pageUrl('api/v1/submissions/' + made.submissionId));
					})
					.then((submission) => {
						const rounds = submission.reviewRounds || [];
						expect(rounds, 'the submission has a review round').to.not.be.empty;
						made.reviewRoundId = rounds[rounds.length - 1].id;

						return send(
							pageUrl('api/v1/submissions/' + made.submissionId + '/files/' + made.fileId + '/copy?stageId=3'),
							'PUT',
							{toFileStage: 4, reviewRoundId: made.reviewRoundId}
						);
					});
			}).then((copy) => {
				expect(copy.status, 'the file was sent to review: ' + JSON.stringify(copy.body)).to.be.within(200, 201);
				made.copyId = copy.body.id;

				return api(pageUrl('api/v1/submissions/' + made.submissionId + '/files/' + made.copyId + '?stageId=3'));
			}).then((review) => {
				expect(review.fileId, 'the review copy has to point at a stored file of its own')
					.to.not.eq(made.storedFileId);

				// And the bytes say the rest: the name is in what the author sent
				// and gone from what the reviewer gets.
				return contentsOf(made.submissionId, made.fileId, 1).then((original) => {
					expect(original, 'the file of the author carries their name, as it was written')
						.to.contain(AUTHOR_IN_THE_FILE);

					return contentsOf(made.submissionId, made.copyId, 3);
				});
			}).then((cleaned) => {
				expect(cleaned, 'the copy the reviewer opens still names the author')
					.to.not.contain(AUTHOR_IN_THE_FILE);
			});
		});

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
			if (madeHere.length) {
				login(adminUser, adminPassword);
				cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
				madeHere.forEach((id) => send(pageUrl('api/v1/submissions/' + id), 'DELETE'));
			}
			if (copyId) {
				// Still on the page of the test above, in its session.
				withToken('DELETE').then((options) => api(submissionApi(copyId + '?stageId=3'), options));
			}
		});
	});
});
