/**
 * Relation Injector — picker UI on JE CCT edit screens.
 *
 * Trojan-horse pattern (RI's verified approach, see L-014):
 *   1. Poll for the JE CCT save form.
 *   2. Inject a "Relations" block + hidden inputs (nonce + JSON payload).
 *   3. Modal-based search via AJAX.
 *   4. On form submit, serialize selected items into the hidden input.
 *   5. Backend reads the POST in the CCT save hook and writes relation rows.
 *
 * @package JEDB
 */
(function ($) {
	'use strict';

	if (typeof window.jedbRelationConfig === 'undefined') {
		return;
	}

	var config = window.jedbRelationConfig;
	var FORM_POLL_INTERVAL_MS = 100;
	var FORM_POLL_MAX_ATTEMPTS = 50;
	var SEARCH_DEBOUNCE_MS = 300;

	var Picker = {

		selected: {},
		searchTimer: null,
		blockInjected: false,

		init: function () {

			(config.relations || []).forEach(function (rel) {
				Picker.selected[rel.id] = [];
			});

			Picker.waitForForm();
		},

		waitForForm: function () {

			var attempts = 0;

			var tick = setInterval(function () {

				attempts++;

				var $form = $('form[action*="jet-cct-save-item"]');
				if (!$form.length) {
					$form = $('form[method="post"]').filter(function () {
						return $(this).find('[name="cct_action"]').length > 0;
					});
				}

				if ($form.length) {
					clearInterval(tick);
					Picker.injectBlock($form.first());
					return;
				}

				if (attempts >= FORM_POLL_MAX_ATTEMPTS) {
					clearInterval(tick);
					if (window.console && console.warn) {
						console.warn('[JEDB Relation] CCT save form not found after ' + attempts + ' polls; picker not injected.');
					}
				}
			}, FORM_POLL_INTERVAL_MS);
		},

		injectBlock: function ($form) {

			if (Picker.blockInjected) {
				return;
			}
			Picker.blockInjected = true;

			var $block = Picker.buildBlock();

			var $submit = $form.find('[type="submit"]').last();
			if ($submit.length) {
				$submit.before($block);
			} else {
				$form.append($block);
			}

			var $hidden = $('<div class="jedb-relations-hidden"></div>').css('display', 'none');
			$hidden.append(
				$('<input>').attr({
					type: 'hidden',
					name: 'jedb_relations_nonce',
					value: config.nonce
				})
			);
			$hidden.append(
				$('<input>').attr({
					type: 'hidden',
					name: 'jedb_relations',
					id: 'jedb-relations-payload'
				})
			);
			$form.append($hidden);

			$form.on('submit', Picker.onSubmit);
		},

		buildBlock: function () {

			var $block = $('<div class="jedb-relations-block"></div>');
			$block.append('<h3 class="jedb-relations-title">' + escapeHtml(config.i18n.block_title) + '</h3>');

			(config.relations || []).forEach(function (rel) {
				$block.append(Picker.buildRelationRow(rel));
			});

			return $block;
		},

		buildRelationRow: function (rel) {

			var $row = $('<div class="jedb-relation-row"></div>').attr('data-relation-id', rel.id);

			var $header = $('<div class="jedb-relation-row-header"></div>');
			$header.append('<span class="jedb-relation-row-label">' + escapeHtml(rel.name) + '</span>');
			$header.append('<code class="jedb-relation-row-meta">' + escapeHtml(rel.type) + ' &middot; ' + escapeHtml(rel.other_target_label) + '</code>');
			$row.append($header);

			var $selected = $('<div class="jedb-relation-row-selected"></div>').attr('data-relation-id', rel.id);
			$row.append($selected);

			var $actions = $('<div class="jedb-relation-row-actions"></div>');
			var $btn = $('<button type="button" class="button"></button>')
				.text(config.i18n.select)
				.on('click', function (e) {
					e.preventDefault();
					Picker.openModal(rel);
				});
			$actions.append($btn);
			$row.append($actions);

			if (rel.type === 'one_to_one') {
				$row.append(
					$('<p class="description"></p>').text(config.i18n.one_to_one_warning)
				);
			}

			if (!rel.table_exists) {
				$row.append(
					$('<p class="jedb-relation-row-error"></p>').text('Relation table missing — JE may not have created it yet.')
				);
			}

			return $row;
		},

		openModal: function (rel) {

			var $modal = Picker.buildModal(rel);
			$('body').append($modal);

			setTimeout(function () { $modal.addClass('active'); }, 10);

			Picker.searchItems(rel, '', $modal);
		},

		buildModal: function (rel) {

			var $modal   = $('<div class="jedb-modal"></div>');
			var $overlay = $('<div class="jedb-modal-overlay"></div>');
			var $content = $('<div class="jedb-modal-content"></div>');

			var $header = $('<div class="jedb-modal-header"></div>');
			$header.append('<h3>' + escapeHtml(rel.name) + ' — ' + escapeHtml(rel.other_target_label) + '</h3>');
			$header.append(
				$('<button type="button" class="jedb-modal-close" aria-label="Close">&times;</button>')
					.on('click', function () { Picker.closeModal($modal); })
			);
			$content.append($header);

			var $body = $('<div class="jedb-modal-body"></div>');
			var $searchWrap = $('<div class="jedb-modal-search"></div>');
			var $searchInput = $('<input type="text" />').attr('placeholder', config.i18n.search_placeholder);
			$searchInput.on('input', function () {
				clearTimeout(Picker.searchTimer);
				var term = $(this).val();
				Picker.searchTimer = setTimeout(function () {
					Picker.searchItems(rel, term, $modal);
				}, SEARCH_DEBOUNCE_MS);
			});
			$searchWrap.append($searchInput);
			$body.append($searchWrap);
			$body.append('<div class="jedb-modal-results" data-relation-id="' + escapeAttr(rel.id) + '"></div>');
			$content.append($body);

			var $footer = $('<div class="jedb-modal-footer"></div>');
			$footer.append(
				$('<button type="button" class="button"></button>')
					.text(config.i18n.cancel)
					.on('click', function () { Picker.closeModal($modal); })
			);
			$content.append($footer);

			$modal.append($overlay, $content);
			$overlay.on('click', function () { Picker.closeModal($modal); });

			return $modal;
		},

		closeModal: function ($modal) {
			$modal.removeClass('active');
			setTimeout(function () { $modal.remove(); }, 250);
		},

		searchItems: function (rel, term, $modal) {

			var $results = $modal.find('.jedb-modal-results');
			$results.html('<div class="jedb-modal-loading">' + escapeHtml(config.i18n.loading) + '</div>');

			$.ajax({
				url:  config.ajax_url,
				type: 'POST',
				data: {
					action:      'jedb_relation_search_items',
					nonce:       config.nonce,
					object_slug: rel.other_target_slug,
					search:      term,
					limit:       25
				},
				success: function (response) {
					if (response && response.success) {
						Picker.renderResults(rel, response.data.items || [], $results, $modal);
					} else {
						var message = (response && response.data && response.data.message) ? response.data.message : config.i18n.error;
						$results.html('<div class="jedb-modal-empty">' + escapeHtml(message) + '</div>');
					}
				},
				error: function () {
					$results.html('<div class="jedb-modal-empty">' + escapeHtml(config.i18n.error) + '</div>');
				}
			});
		},

		renderResults: function (rel, items, $container, $modal) {

			$container.empty();

			if (!items || !items.length) {
				$container.html('<div class="jedb-modal-empty">' + escapeHtml(config.i18n.no_results) + '</div>');
				return;
			}

			items.forEach(function (item) {

				var $item = $('<div class="jedb-modal-result"></div>');
				$item.append('<div class="jedb-modal-result-label">' + escapeHtml(String(item.label)) + '</div>');
				$item.append('<div class="jedb-modal-result-meta">ID: ' + escapeHtml(String(item.id)) + '</div>');

				var $btn = $('<button type="button" class="button button-primary"></button>')
					.text(config.i18n.select)
					.on('click', function (e) {
						e.preventDefault();
						Picker.addSelected(rel, item);
						Picker.closeModal($modal);
					});

				$item.append($btn);
				$container.append($item);
			});
		},

		addSelected: function (rel, item) {

			if (rel.type === 'one_to_one') {
				Picker.selected[rel.id] = [];
			}

			var already = Picker.selected[rel.id].some(function (i) { return String(i.id) === String(item.id); });
			if (!already) {
				Picker.selected[rel.id].push(item);
			}

			Picker.renderSelected(rel);
		},

		removeSelected: function (rel, itemId) {

			Picker.selected[rel.id] = Picker.selected[rel.id].filter(function (i) {
				return String(i.id) !== String(itemId);
			});

			Picker.renderSelected(rel);
		},

		renderSelected: function (rel) {

			var $container = $('.jedb-relation-row-selected[data-relation-id="' + escapeAttr(rel.id) + '"]');
			$container.empty();

			Picker.selected[rel.id].forEach(function (item) {

				var $chip = $('<span class="jedb-chip"></span>');
				$chip.append('<span class="jedb-chip-label">' + escapeHtml(String(item.label)) + '</span>');
				$chip.append('<small>#' + escapeHtml(String(item.id)) + '</small>');

				var $remove = $('<button type="button" class="jedb-chip-remove" aria-label="Remove">&times;</button>')
					.on('click', function (e) {
						e.preventDefault();
						Picker.removeSelected(rel, item.id);
					});

				$chip.append($remove);
				$container.append($chip);
			});
		},

		onSubmit: function () {

			var payload = {};

			Object.keys(Picker.selected).forEach(function (relId) {
				var items = Picker.selected[relId];
				if (items && items.length) {
					payload[relId] = items.map(function (i) { return i.id; });
				}
			});

			$('#jedb-relations-payload').val(JSON.stringify(payload));

			return true;
		}
	};

	function escapeHtml(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function escapeAttr(s) {
		return String(s).replace(/"/g, '&quot;');
	}

	$(document).ready(function () { Picker.init(); });

})(jQuery);
