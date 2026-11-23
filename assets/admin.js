(function ($) {
	'use strict';

	$(function () {
		var $input      = $('#wss-input');
		var $clear      = $('#wss-clear');
		var $submit     = $('#wss-submit');
		var $status     = $('#wss-status');
		var $results    = $('#wss-results');
		var $progress   = $('#wss-progress');
		var $bar        = $progress.find('.wss-progress-bar');
		var $refangCb   = $('#wss-refang-cb');

		var MIN_LEN     = parseInt(WSS.minLen, 10) || 2;
		var timer       = null;
		var filterTimer = null;
		var lastQuery   = '';
		var xhrTags     = null;
		var xhrPosts    = null;

		/* ============================================================
		   Progress
		   ============================================================ */

		var progressTimer   = null;
		var progressValue   = 0;
		var progressHideTid = null;

		function startProgress() {
			clearTimeout(progressHideTid);
			clearInterval(progressTimer);

			progressValue = 0;
			$progress.removeClass('wss-progress-done').addClass('wss-progress-active');
			$bar.css('width', '0%');

			progressTimer = setInterval(function () {
				var step;
				if (progressValue < 30)      { step = 8; }
				else if (progressValue < 60) { step = 3; }
				else if (progressValue < 85) { step = 1; }
				else if (progressValue < 95) { step = 0.4; }
				else                         { step = 0; }

				progressValue = Math.min(95, progressValue + step);
				$bar.css('width', progressValue + '%');
			}, 180);

			$submit.prop('disabled', true);
		}

		function finishProgress(success) {
			clearInterval(progressTimer);
			progressTimer = null;

			progressValue = 100;
			if (success) {
				$progress.addClass('wss-progress-done');
			}
			$bar.css('width', '100%');

			progressHideTid = setTimeout(function () {
				$progress.removeClass('wss-progress-active wss-progress-done');
				$bar.css('width', '0%');
				progressValue = 0;
			}, 450);

			$submit.prop('disabled', false);
		}

		/* ============================================================
		   Utilities
		   ============================================================ */

		function escapeHtml(s) {
			return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}

		function emptyStateHtml() {
			return '<div class="wss-empty-state">' +
				'<span class="dashicons dashicons-search wss-empty-state-icon" aria-hidden="true"></span>' +
				'<p>' + escapeHtml(WSS.i18n.emptyState) + '</p>' +
				'</div>';
		}

		function getFilters() {
			var statuses = [];
			$('.wss-filter-status:checked').each(function () {
				statuses.push($(this).val());
			});

			return {
				statuses:   statuses,
				date_range: $('#wss-filter-date').val() || 'any',
				author:     parseInt($('#wss-filter-author').val(), 10) || 0,
				orderby:    $('#wss-filter-orderby').val() || 'date_desc'
			};
		}

		function updateUrl(query) {
			if (!window.history || !window.history.replaceState || !WSS.pageUrl) {
				return;
			}

			var params = [];
			if (query) {
				params.push('q=' + encodeURIComponent(query));
			}
			if ($refangCb.length && !$refangCb.is(':checked')) {
				params.push('refang=0');
			}

			var url = WSS.pageUrl;
			if (params.length) {
				url += (url.indexOf('?') === -1 ? '?' : '&') + params.join('&');
			}
			window.history.replaceState({}, '', url);
		}

		function isQueryTooShort(q) {
			if (q.length === 0) {
				return true;
			}
			if (q.length >= MIN_LEN) {
				return false;
			}
			try {
				return /^[\p{L}\p{N}]$/u.test(q);
			} catch (e) {
				return /^[0-9a-zA-Zа-яА-ЯёЁ_]$/.test(q);
			}
		}

		/* ============================================================
		   Skeleton
		   ============================================================ */

		function tagsSkeletonHtml() {
			return '<div class="wss-skeleton">' +
				'<div class="wss-skeleton-title"></div>' +
				'<div class="wss-skeleton-line lg"></div>' +
				'<div class="wss-skeleton-line md"></div>' +
				'<div class="wss-skeleton-line sm"></div>' +
				'</div>';
		}

		function postsSkeletonHtml() {
			var html = '<div class="wss-skeleton">';
			html += '<div class="wss-skeleton-title"></div>';
			for (var i = 0; i < 3; i++) {
				html += '<div class="wss-skeleton-card">' +
					'<div class="wss-skeleton-line md"></div>' +
					'<div class="wss-skeleton-line sm"></div>' +
					'</div>';
			}
			html += '</div>';
			return html;
		}

		/* ============================================================
		   Author
		   ============================================================ */

		function renderAuthor(a) {
			if (!a || !a.name) {
				return '';
			}
			var html = '<span class="wss-post-author" aria-label="' +
				escapeHtml(WSS.i18n.authorLabel || '') + '">';

			if (a.avatar) {
				html += '<img class="wss-post-author-avatar" src="' +
					escapeHtml(a.avatar) + '" alt="" loading="lazy" width="18" height="18">';
			}

			if (a.url) {
				html += '<a class="wss-post-author-name" href="' +
					escapeHtml(a.url) + '">' + escapeHtml(a.name) + '</a>';
			} else {
				html += '<span class="wss-post-author-name">' +
					escapeHtml(a.name) + '</span>';
			}

			html += '</span>';
			return html;
		}

		/* ============================================================
		   Post taxonomies
		   ============================================================ */

		function renderTaxRow(items, opts) {
			if (!items || !items.length) {
				return '';
			}

			var VISIBLE = 5;
			var visible = items.slice(0, VISIBLE);
			var hidden  = items.slice(VISIBLE);

			var html = '<div class="wss-post-tax-row">';
			html += '<span class="wss-tax-label ' + opts.labelClass + '">' +
					'<span class="dashicons ' + opts.iconClass + '" aria-hidden="true"></span>' +
					escapeHtml(opts.labelText) + '</span>';

			visible.forEach(function (t) {
				html += '<a class="wss-post-tax-chip ' + opts.chipClass + '" href="' +
						escapeHtml(t.url) + '">' + escapeHtml(t.name) + '</a>';
			});

			if (hidden.length) {
				html += '<button type="button" class="wss-post-tax-chip wss-post-tax-more ' +
						opts.chipClass + '" aria-label="' +
						escapeHtml(WSS.i18n.showAllTags) + '">+' + escapeHtml(hidden.length) + '</button>';
				hidden.forEach(function (t) {
					html += '<a class="wss-post-tax-chip ' + opts.chipClass + '" href="' +
							escapeHtml(t.url) + '" hidden>' + escapeHtml(t.name) + '</a>';
				});
			}

			html += '</div>';
			return html;
		}

		function renderPostTaxonomies(p) {
			var cats = renderTaxRow(p.categories, {
				chipClass:  'wss-post-cat',
				labelClass: 'wss-tax-label-cat',
				iconClass:  'dashicons-category',
				labelText:  WSS.i18n.catsLabel
			});

			var tags = renderTaxRow(p.tags, {
				chipClass:  'wss-post-tag',
				labelClass: 'wss-tax-label-tag',
				iconClass:  'dashicons-tag',
				labelText:  WSS.i18n.tagsLabel
			});

			if (!cats && !tags) {
				return '';
			}

			return '<div class="wss-post-taxonomies">' + cats + tags + '</div>';
		}

		/* ============================================================
		   Sections
		   ============================================================ */

		function tagsSectionHtml(tags) {
			if (!tags || !tags.length) {
				return '<div class="wss-section"><div class="wss-empty">' +
					escapeHtml(WSS.i18n.noTags) + '</div></div>';
			}
			var html = '<div class="wss-section">';
			html += '<h2 class="wss-section-title">' + escapeHtml(WSS.i18n.tags) +
					' <span class="wss-count">' + tags.length + '</span></h2>';
			html += '<div class="wss-tags">';
			tags.forEach(function (t) {
				html += '<a class="wss-tag" href="' + escapeHtml(t.url) + '">';
				html += '<span class="wss-tag-name">' + escapeHtml(t.name) + '</span>';
				html += '<span class="wss-tag-count">' + escapeHtml(t.count) + '</span>';
				html += '</a>';
			});
			html += '</div></div>';
			return html;
		}

		function buildPageRange(current, total) {
			var out = [];
			var WINDOW = 2;

			if (total <= 7) {
				for (var i = 1; i <= total; i++) { out.push(i); }
				return out;
			}

			out.push(1);

			var start = Math.max(2, current - WINDOW);
			var end   = Math.min(total - 1, current + WINDOW);

			if (start > 2) { out.push(-1); }
			for (var j = start; j <= end; j++) { out.push(j); }
			if (end < total - 1) { out.push(-1); }

			out.push(total);
			return out;
		}

		function renderPagination(current, total) {
			if (total <= 1) {
				return '';
			}

			var html = '<nav class="wss-pagination" aria-label="' +
					escapeHtml(WSS.i18n.posts) + '">';

			html += '<button type="button" class="wss-page' +
					(current <= 1 ? ' wss-page-disabled' : '') + '" ' +
					'data-page="' + (current - 1) + '" ' +
					'aria-label="' + escapeHtml(WSS.i18n.prevPage) + '">&larr;</button>';

			var range = buildPageRange(current, total);
			range.forEach(function (p) {
				if (p === -1) {
					html += '<span class="wss-page-gap">…</span>';
				} else if (p === current) {
					html += '<span class="wss-page wss-page-active" aria-current="page">' +
							escapeHtml(p) + '</span>';
				} else {
					html += '<button type="button" class="wss-page" data-page="' + p + '" ' +
							'aria-label="' + escapeHtml(WSS.i18n.pageLabel.replace('%d', p)) + '">' +
							escapeHtml(p) + '</button>';
				}
			});

			html += '<button type="button" class="wss-page' +
					(current >= total ? ' wss-page-disabled' : '') + '" ' +
					'data-page="' + (current + 1) + '" ' +
					'aria-label="' + escapeHtml(WSS.i18n.nextPage) + '">&rarr;</button>';

			html += '</nav>';
			return html;
		}

		function postsSectionHtml(data) {
			var items      = (data && data.items) || [];
			var total      = (data && data.total) || 0;
			var page       = (data && data.page) || 1;
			var totalPages = (data && data.total_pages) || 1;

			if (!items.length) {
				return '<div class="wss-section"><div class="wss-empty">' +
					escapeHtml(WSS.i18n.noPosts) + '</div></div>';
			}

			var html = '<div class="wss-section">';
			html += '<h2 class="wss-section-title">' + escapeHtml(WSS.i18n.posts) +
					' <span class="wss-count">' + escapeHtml(total) + '</span></h2>';
			html += '<div class="wss-posts">';

			items.forEach(function (p) {
				html += '<div class="wss-post">';

				html += '<a class="wss-post-link" href="' + escapeHtml(p.url) + '">';
				html += '<div class="wss-post-title">' + escapeHtml(p.title) + '</div>';
				if (p.excerpt) {
					html += '<div class="wss-post-excerpt">' + escapeHtml(p.excerpt) + '</div>';
				}
				html += '</a>';

				html += '<div class="wss-post-info">';
				html += '<span class="wss-badge wss-badge-' + escapeHtml(p.status) + '">' +
						escapeHtml(p.status_label || p.status) + '</span>';
				html += renderAuthor(p.author);
				html += '<span class="wss-post-date">' + escapeHtml(p.date) + '</span>';
				html += '</div>';

				html += renderPostTaxonomies(p);
				html += '</div>';
			});

			html += '</div>';
			html += renderPagination(page, totalPages);
			html += '</div>';
			return html;
		}

		/* ============================================================
		   Slots
		   ============================================================ */

		function resetSlots() {
			$results.html(
				'<div class="wss-slot wss-slot-tags">'  + tagsSkeletonHtml()  + '</div>' +
				'<div class="wss-slot wss-slot-posts">' + postsSkeletonHtml() + '</div>'
			);
		}

		function setTagsSlot(html) {
			$results.find('.wss-slot-tags').html(html);
		}

		function setPostsSlot(html) {
			$results.find('.wss-slot-posts').html(html);
		}

		/* ============================================================
		   Requests
		   ============================================================ */

		var pendingCount = 0;
		var anyError     = false;
		var errorMessage = '';

		function requestDone() {
			pendingCount = Math.max(0, pendingCount - 1);
			if (pendingCount > 0) {
				return;
			}
			if (anyError) {
				$status.text(errorMessage || WSS.i18n.error);
				finishProgress(false);
			} else {
				$status.text('');
				finishProgress(true);
			}
		}

		function extractServerMessage(xhrOrRes) {
			if (!xhrOrRes) {
				return '';
			}
			var payload = xhrOrRes.responseJSON || xhrOrRes;
			if (payload && payload.data && typeof payload.data.message === 'string') {
				return payload.data.message;
			}
			if (payload && typeof payload.message === 'string') {
				return payload.message;
			}
			return '';
		}

		function startRequest(type, page, onSuccess) {
			var filters = getFilters();

			var data = {
				action:     WSS.action,
				nonce:      WSS.nonce,
				query:      lastQuery,
				type:       type,
				refang:     ($refangCb.length && $refangCb.is(':checked')) ? 1 : 0,
				statuses:   filters.statuses,
				date_range: filters.date_range,
				author:     filters.author,
				orderby:    filters.orderby
			};
			if (type === 'posts') {
				data.page = page || 1;
			}

			return $.ajax({
				url:      WSS.ajaxUrl,
				type:     'POST',
				dataType: 'json',
				data:     data
			}).done(function (res) {
				if (res && res.success) {
					onSuccess(res.data);
				} else {
					anyError = true;
					var msg = extractServerMessage(res);
					if (msg && !errorMessage) {
						errorMessage = msg;
					}
					if (type === 'tags') {
						setTagsSlot('');
					} else {
						setPostsSlot('');
					}
				}
			}).fail(function (jqXHR, textStatus) {
				if (textStatus === 'abort') {
					return;
				}
				anyError = true;
				var msg = extractServerMessage(jqXHR);
				if (msg && !errorMessage) {
					errorMessage = msg;
				}
				if (type === 'tags') {
					setTagsSlot('');
				} else {
					setPostsSlot('');
				}
			}).always(function (jqXHR, textStatus) {
				if (textStatus === 'abort') {
					return;
				}
				requestDone();
			});
		}

		function doSearch(force) {
			var q = $.trim($input.val());

			if (q.length === 0) {
				$status.text('');
				updateUrl('');
				$results.html(emptyStateHtml());
				lastQuery = '';
				return;
			}

			if (isQueryTooShort(q)) {
				$status.text(WSS.i18n.minLenTpl.replace('%d', MIN_LEN));
				$results.html(emptyStateHtml());
				lastQuery = '';
				return;
			}

			if (!force && q === lastQuery) {
				return;
			}
			lastQuery = q;

			if (xhrTags)  { xhrTags.abort();  xhrTags  = null; }
			if (xhrPosts) { xhrPosts.abort(); xhrPosts = null; }

			pendingCount = 2;
			anyError     = false;
			errorMessage = '';

			$status.text(WSS.i18n.searching);
			resetSlots();
			startProgress();

			xhrTags = startRequest('tags', null, function (data) {
				setTagsSlot(tagsSectionHtml(data.tags));
			});

			xhrPosts = startRequest('posts', 1, function (data) {
				setPostsSlot(postsSectionHtml(data.posts));
			});

			updateUrl(q);
		}

		function loadPostsPage(page) {
			if (!lastQuery) {
				return;
			}
			if (xhrPosts) { xhrPosts.abort(); xhrPosts = null; }

			pendingCount = 1;
			anyError     = false;
			errorMessage = '';

			setPostsSlot(postsSkeletonHtml());
			startProgress();

			xhrPosts = startRequest('posts', page, function (data) {
				setPostsSlot(postsSectionHtml(data.posts));
			});
		}

		/* ============================================================
		   Tabs
		   ============================================================ */

		var $tabs   = $('.wss-tabs [role="tab"]');
		var $panels = $('.wss-panel');

		function tabSlug($tab) {
			return String($tab.attr('data-tab') || 'search');
		}

		/* Keeps ?tab= in the address bar in sync, without a reload. */
		function setTabParam(slug) {
			if (!window.history || !window.history.replaceState) {
				return;
			}

			var parts = window.location.href.split('#')[0].split('?');
			var base  = parts.shift();
			var query = parts.length ? parts.join('?').split('&') : [];
			var kept  = [];

			for (var i = 0; i < query.length; i++) {
				if (!query[i] || query[i].indexOf('tab=') === 0) {
					continue;
				}
				kept.push(query[i]);
			}
			if (slug !== 'search') {
				kept.push('tab=' + encodeURIComponent(slug));
			}

			window.history.replaceState({}, '', base + (kept.length ? '?' + kept.join('&') : ''));
		}

		function activateTab($tab, moveFocus) {
			if (!$tab || !$tab.length) {
				return;
			}

			var panel = String($tab.attr('aria-controls') || '');

			$tabs.each(function () {
				var $t = $(this);
				var isActive = $t[0] === $tab[0];
				$t.attr('aria-selected', isActive ? 'true' : 'false')
					.attr('tabindex', isActive ? '0' : '-1')
					.toggleClass('is-active', isActive);
			});

			$panels.each(function () {
				this.hidden = (this.id !== panel);
			});

			setTabParam(tabSlug($tab));

			if (moveFocus) {
				$tab.trigger('focus');
			}
		}

		$tabs.on('click', function (e) {
			// The same link without JS reloads the page with ?tab=… and the
			// server renders that tab; with JS the switch is instant.
			e.preventDefault();
			activateTab($(this), false);
		});

		$tabs.on('keydown', function (e) {
			var index = $tabs.index(this);
			var last  = $tabs.length - 1;
			var next  = null;

			if (e.key === 'ArrowRight' || e.key === 'Right') {
				next = index >= last ? 0 : index + 1;
			} else if (e.key === 'ArrowLeft' || e.key === 'Left') {
				next = index <= 0 ? last : index - 1;
			} else if (e.key === 'Home') {
				next = 0;
			} else if (e.key === 'End') {
				next = last;
			} else if (e.key === ' ' || e.key === 'Spacebar' || e.key === 'Enter') {
				e.preventDefault();
				activateTab($(this), true);
				return;
			}

			if (next !== null) {
				e.preventDefault();
				activateTab($tabs.eq(next), true);
			}
		});

		/* ============================================================
		   Browser indexing
		   ============================================================ */

		var $indexRunner  = $('#wss-index-runner');
		var $indexStart   = $('#wss-index-start');
		var $indexRestart = $('#wss-index-restart');
		var $indexStop    = $('#wss-index-stop');
		var $indexBar     = $('#wss-index-bar');
		var $indexText    = $('#wss-index-progress-text');
		var $indexCounts  = $('#wss-index-counts');
		var indexActive   = false;
		var indexTimer    = null;
		var indexRetries  = 0;

		/* One request at a time, a short pause between batches so a small
		   PHP-FPM pool is not saturated by the loop. */
		var INDEX_PAUSE   = 200;

		/* A 500 or a gateway timeout is normal for a heavy batch: the server
		   answers "partial" once the budget is spent, but a proxy may still
		   cut us off. Retry with growing delays before giving up — the cursor
		   is on the server, so a retry resumes instead of restarting. */
		var INDEX_RETRY_DELAYS = [2000, 4000, 8000];
		var INDEX_MAX_RETRY    = INDEX_RETRY_DELAYS.length;

		/* The runner only makes sense with JS: reveal it here. */
		if ($indexRunner.length) {
			$indexRunner.removeAttr('hidden');
		}

		function indexPaint(data) {
			var percent = parseInt(data.percent, 10);
			if (isNaN(percent)) {
				percent = 0;
			}
			percent = Math.max(0, Math.min(100, percent));

			$indexBar
				.attr('aria-valuenow', percent)
				.css('width', percent + '%');

			$indexText.text(
				WSS.i18n.indexProgress
					.replace('%1$d', data.processed || 0)
					.replace('%2$d', data.total || 0)
					.replace('%3$d', percent)
			);

			if ($indexCounts.length && typeof data.links === 'number') {
				$indexCounts.text(
					WSS.i18n.indexCounts
						.replace('%1$d', data.links)
						.replace('%2$d', data.processed || 0)
						.replace('%3$d', data.total || 0)
						.replace('%4$d', data.without_links || 0)
						.replace('%5$d', data.links_written || 0)
						.replace('%6$s', data.links_per_post || '0')
				);
			}
		}

		function indexReset() {
			indexActive = false;
			indexRetries = 0;
			if (indexTimer) {
				clearTimeout(indexTimer);
				indexTimer = null;
			}
			$indexStart.prop('disabled', false);
			$indexRestart.prop('disabled', false);
			$indexStop.attr('hidden', 'hidden');
		}

		function indexStopWith(message) {
			indexReset();
			$indexText.text(message);
			indexRelease();
		}

		/* Frees WP-Cron again; the cursor stays, so a restart continues. */
		function indexRelease() {
			$.ajax({
				url:      WSS.ajaxUrl,
				type:     'POST',
				dataType: 'json',
				data: {
					action: WSS.indexStopAction,
					nonce:  WSS.indexStopNonce
				}
			});
		}

		function indexStep(isFirst, isRestart) {
			if (!indexActive) {
				return;
			}

			$.ajax({
				url:      WSS.ajaxUrl,
				type:     'POST',
				dataType: 'json',
				data: {
					action:  WSS.indexStepAction,
					nonce:   WSS.indexStepNonce,
					start:   isFirst ? 1 : 0,
					restart: isRestart ? 1 : 0
				}
			}).done(function (res) {
				if (!indexActive) {
					return;
				}
				indexRetries = 0;

				if (!res || !res.success) {
					indexStopWith(extractServerMessage(res) || WSS.i18n.indexError);
					return;
				}

				var data = res.data || {};
				indexPaint(data);

				if (data.done) {
					indexReset();
					$indexText.text(data.message || WSS.i18n.indexDone);
					return;
				}

				indexTimer = setTimeout(function () {
					indexStep(false, false);
				}, INDEX_PAUSE);
			}).fail(function (jqXHR, textStatus) {
				if (textStatus === 'abort' || !indexActive) {
					return;
				}

				/* A dropped request must not lose the run: retry up to three
				   times with growing delays, then stop keeping the progress
				   (the cursor is on the server, so the next run continues). */
				indexRetries++;
				if (indexRetries <= INDEX_MAX_RETRY) {
					$indexText.text(WSS.i18n.indexRetry);
					indexTimer = setTimeout(function () {
						indexStep(false, false);
					}, INDEX_RETRY_DELAYS[indexRetries - 1]);
					return;
				}

				var status = jqXHR && jqXHR.status ? String(jqXHR.status) : '';
				indexStopWith(
					extractServerMessage(jqXHR)
						|| (status ? WSS.i18n.indexErrorStatus.replace('%s', status) : WSS.i18n.indexError)
				);
			});
		}

		function indexRun(isRestart) {
			if (indexActive) {
				return;
			}
			indexActive  = true;
			indexRetries = 0;
			$indexStart.prop('disabled', true);
			$indexRestart.prop('disabled', true);
			$indexStop.removeAttr('hidden');
			$indexText.text(WSS.i18n.indexRunning);
			$indexBar.attr('aria-valuenow', 0).css('width', '0%');
			indexStep(true, isRestart);
		}

		$indexStart.on('click', function () {
			indexRun(false);
		});

		$indexRestart.on('click', function () {
			if (indexActive) {
				return;
			}
			if (window.confirm && !window.confirm(WSS.i18n.indexRestart)) {
				return;
			}
			indexRun(true);
		});

		$indexStop.on('click', function () {
			if (!indexActive) {
				return;
			}
			indexReset();
			$indexText.text(WSS.i18n.indexStopped);
			indexRelease();
		});
		/* ============================================================
		   Events
		   ============================================================ */

		$input.on('input', function () {
			var hasText = $input.val().length > 0;
			$clear.toggleClass('is-visible', hasText);
			clearTimeout(timer);
			timer = setTimeout(function () { doSearch(false); }, 300);
		});

		$input.on('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				clearTimeout(timer);
				doSearch(true);
			}
		});

		$submit.on('click', function () {
			clearTimeout(timer);
			doSearch(true);
			$input.trigger('focus');
		});

		$clear.on('click', function () {
			$input.val('').trigger('focus');
			$clear.removeClass('is-visible');
			$status.text('');
			lastQuery = '';
			errorMessage = '';
			updateUrl('');
			$results.html(emptyStateHtml());
			if (xhrTags)  { xhrTags.abort();  xhrTags  = null; }
			if (xhrPosts) { xhrPosts.abort(); xhrPosts = null; }
		});

		$refangCb.on('change', function () {
			updateUrl($.trim($input.val()));
			var q = $.trim($input.val());
			if (!isQueryTooShort(q)) {
				doSearch(true);
			}
		});

		$('.wss-filter-status, #wss-filter-date, #wss-filter-author, #wss-filter-orderby').on('change', function () {
			clearTimeout(filterTimer);
			filterTimer = setTimeout(function () {
				if (!isQueryTooShort($.trim($input.val()))) {
					doSearch(true);
				}
			}, 250);
		});

		$('#wss-reset-filters').on('click', function () {
			$('.wss-filter-status').prop('checked', true);
			$('#wss-filter-date').val('any');
			$('#wss-filter-author').val('0');
			$('#wss-filter-orderby').val('date_desc');
			$refangCb.prop('checked', true);
			updateUrl($.trim($input.val()));
			doSearch(true);
		});

		$results.on('click', '.wss-post-tax-more', function (e) {
			e.preventDefault();
			var $btn  = $(this);
			var $row  = $btn.closest('.wss-post-tax-row');
			$row.find('.wss-post-tax-chip[hidden]').removeAttr('hidden');
			$btn.remove();
		});

		$results.on('click', '.wss-page[data-page]', function (e) {
			e.preventDefault();
			var $btn = $(this);
			if ($btn.hasClass('wss-page-disabled') || $btn.hasClass('wss-page-active')) {
				return;
			}
			var p = parseInt($btn.attr('data-page'), 10);
			if (p > 0) {
				loadPostsPage(p);
				var $section = $btn.closest('.wss-slot-posts');
				if ($section.length) {
					$('html, body').animate({
						scrollTop: $section.offset().top - 40
					}, 200);
				}
			}
		});

		/* Initialisation */
		$clear.toggleClass('is-visible', $input.val().length > 0);

		if (WSS.initialQuery && !isQueryTooShort(WSS.initialQuery)) {
			doSearch(true);
		}
	});
})(jQuery);