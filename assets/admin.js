/* global jQuery, wp */
/**
 * Anuncios entre productos: panel de administracion.
 *
 * - Vista previa de la grilla que simula exactamente como la tienda coloca
 *   los anuncios (misma logica que show_ad() en PHP).
 * - Tarjetas de anuncio con estado (visible, programado, finalizado...).
 * - Selector de modo en categorias y marcas.
 */
(function ($) {
	'use strict';

	var config = window.apAnuncioAdmin || {};
	var TODAY = config.today || new Date().toISOString().slice(0, 10);
	var MAX_PREVIEW_ROWS = 12;
	var WARNING_STATUSES = ['missing', 'error', 'hidden'];
	var dirty = false;

	/* Utilidades ------------------------------------------------------- */

	function toInt(value, fallback) {
		var number = parseInt(value, 10);
		return isNaN(number) ? fallback : number;
	}

	function parseRows(value) {
		var matches = String(value || '').match(/\d+/g) || [];
		return matches.map(Number).filter(function (row) {
			return row > 0;
		});
	}

	function formatDate(ymd) {
		var parts = String(ymd).split('-');
		return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : String(ymd);
	}

	function getScope($element) {
		var $form = $element.closest('form');
		return $form.length ? $form : $(document.body);
	}

	function getColumns($editor) {
		var $input = getScope($editor).find('.ap-grid-columns-input');
		var columns = $input.length ? toInt($input.val(), 0) : 0;

		if (columns < 1) {
			columns = toInt($editor.attr('data-grid-columns'), 4);
		}

		return Math.max(1, Math.min(8, columns));
	}

	function getSkipRows($editor) {
		var rows = parseRows($editor.attr('data-extra-skip-rows'));

		getScope($editor).find('.ap-skip-rows-input').each(function () {
			rows = rows.concat(parseRows($(this).val()));
		});

		return rows;
	}

	function markDirty() {
		if (!$('.ap-settings-form').length) {
			return;
		}

		dirty = true;
		$('.ap-unsaved').prop('hidden', false);
	}

	/* Lectura y estado de los anuncios --------------------------------- */

	function readAds($editor, columns) {
		return $editor.find('.ap-ad-card').map(function (index) {
			var $card = $(this);
			var row = Math.max(1, toInt($card.find('.ap-ad-row').val(), 1));
			var column = Math.max(1, Math.min(columns, toInt($card.find('.ap-ad-column').val(), columns)));

			return {
				index: index,
				$card: $card,
				row: row,
				column: column,
				position: (row - 1) * columns + column,
				hasImage: toInt($card.find('.ap-ad-image-id').val(), 0) > 0,
				thumb: $card.find('.ap-ad-thumb').attr('src') || '',
				start: $card.find('.ap-ad-start').val() || '',
				end: $card.find('.ap-ad-end').val() || ''
			};
		}).get();
	}

	function hasInvalidDates(ad) {
		return Boolean(ad.start && ad.end && ad.end < ad.start);
	}

	function getStatus(ad, skipRows) {
		if (!ad.hasImage) {
			return {
				key: 'missing',
				label: 'Falta la imagen',
				note: ad.$card.attr('data-image-missing')
					? 'La imagen que tenía este anuncio ya no existe en la biblioteca de medios. Elige otra.'
					: 'Elige una imagen. Los anuncios sin imagen no se guardan.'
			};
		}

		if (hasInvalidDates(ad)) {
			return {
				key: 'error',
				label: 'Revisa las fechas',
				note: '«Mostrar hasta» es anterior a «Mostrar desde», así que este anuncio nunca se verá.'
			};
		}

		if (skipRows.indexOf(ad.row) !== -1) {
			return {
				key: 'hidden',
				label: 'Oculto',
				note: 'La fila ' + ad.row + ' está marcada como fila sin anuncios. Cambia la fila o quítala de esa lista.'
			};
		}

		if (ad.end && ad.end < TODAY) {
			return { key: 'ended', label: 'Finalizado', note: 'Se mostró hasta el ' + formatDate(ad.end) + '.' };
		}

		if (ad.start && ad.start > TODAY) {
			return {
				key: 'scheduled',
				label: 'Programado',
				note: 'Se mostrará desde el ' + formatDate(ad.start) + (ad.end ? ' hasta el ' + formatDate(ad.end) : '') + '.'
			};
		}

		return {
			key: 'active',
			label: 'Visible',
			note: ad.end ? 'Visible hasta el ' + formatDate(ad.end) + ', inclusive.' : ''
		};
	}

	/**
	 * Reproduce show_ad(): antes de cada producto se insertan los anuncios
	 * cuya posicion ya llego. Si dos comparten casilla, van seguidos.
	 */
	function simulate(ads, skipRows) {
		var placed = ads.filter(function (ad) {
			return skipRows.indexOf(ad.row) === -1 && !hasInvalidDates(ad);
		}).sort(function (a, b) {
			return (a.position - b.position) || (a.index - b.index);
		});

		var cells = [];
		var products = 0;
		var next = 0;

		while (next < placed.length) {
			if (placed[next].position <= cells.length + 1) {
				cells.push({ ad: placed[next], productsBefore: products });
				next++;
			} else {
				products++;
				cells.push({ product: products });
			}
		}

		return { cells: cells, products: products };
	}

	/* Vista previa ----------------------------------------------------- */

	function buildCell(cell, row, column, skipped) {
		var $cell;
		var text;

		if (cell.ad) {
			var ad = cell.ad;
			text = 'Anuncio ' + (ad.index + 1) + ' (' + ad.status.label.toLowerCase() + '). Clic para editarlo.';
			$cell = $('<button type="button" class="ap-cell ap-cell--ad"></button>')
				.attr({ 'data-ad-index': ad.index, title: text, 'aria-label': text });

			if (ad.hasImage && ad.thumb) {
				$cell.append($('<img alt="">').attr('src', ad.thumb));
			} else {
				$cell.addClass('is-empty');
			}

			if (ad.status.key !== 'active' && ad.status.key !== 'missing') {
				$cell.addClass('is-inactive');
			}

			return $cell.append($('<span class="ap-cell__num" aria-hidden="true"></span>').text(ad.index + 1));
		}

		text = 'Producto ' + cell.product + (skipped ? ' (fila sin anuncios)' : '. Clic para poner un anuncio aquí.');
		$cell = $('<button type="button" class="ap-cell ap-cell--product"></button>')
			.attr({ 'data-row': row, 'data-column': column, title: text, 'aria-label': text })
			.prop('disabled', skipped);

		if (!skipped) {
			$cell.append('<span class="ap-cell__plus" aria-hidden="true">+</span>');
		}

		return $cell;
	}

	function renderPreview($editor, cells, productCount, columns, skipRows) {
		var $preview = $editor.find('.ap-preview');

		if (!$preview.length) {
			return;
		}

		var lastAdCell = 0;
		cells.forEach(function (cell, i) {
			if (cell.ad) {
				lastAdCell = i + 1;
			}
		});

		var lastAdRow = Math.ceil(lastAdCell / columns);
		var visibleRows = Math.min(Math.max(3, lastAdRow + 1), MAX_PREVIEW_ROWS);
		var totalCells = visibleRows * columns;
		var products = productCount;
		var grid = cells.slice(0, totalCells);
		var rows = [];

		while (grid.length < totalCells) {
			products++;
			grid.push({ product: products });
		}

		for (var r = 0; r < visibleRows; r++) {
			var rowNumber = r + 1;
			var skipped = skipRows.indexOf(rowNumber) !== -1;
			var $cells = $('<div class="ap-preview__cells"></div>');

			for (var c = 0; c < columns; c++) {
				$cells.append(buildCell(grid[r * columns + c], rowNumber, c + 1, skipped));
			}

			rows.push(
				$('<div class="ap-preview__row"></div>')
					.toggleClass('is-skipped', skipped)
					.append($('<span class="ap-preview__label"></span>').text('Fila ' + rowNumber))
					.append($cells)
			);
		}

		$preview[0].style.setProperty('--ap-cols', String(columns));
		$preview.empty().append(rows);

		$editor.find('.ap-preview-more')
			.text(lastAdRow > visibleRows ? 'Hay más anuncios debajo, hasta la fila ' + lastAdRow + '.' : '')
			.prop('hidden', lastAdRow <= visibleRows);
	}

	/* Refresco general del editor -------------------------------------- */

	function refresh($editor) {
		if (!$editor || !$editor.length) {
			return;
		}

		var columns = getColumns($editor);
		var skipRows = getSkipRows($editor);
		var ads = readAds($editor, columns);
		var result = simulate(ads, skipRows);
		var placedAt = {};
		var byPosition = {};

		result.cells.forEach(function (cell) {
			if (cell.ad) {
				placedAt[cell.ad.index] = cell.productsBefore;
			}
		});

		ads.forEach(function (ad) {
			(byPosition[ad.position] = byPosition[ad.position] || []).push(ad.index + 1);
		});

		ads.forEach(function (ad) {
			var status = getStatus(ad, skipRows);
			var notes = status.note ? [status.note] : [];
			var others = byPosition[ad.position].filter(function (number) {
				return number !== ad.index + 1;
			});
			var productsBefore = placedAt[ad.index];
			var $card = ad.$card;
			var where = '';

			ad.status = status;

			if (others.length && status.key !== 'hidden') {
				notes.push('Comparte casilla con el anuncio ' + others.join(', ') + ': se mostrarán uno a continuación del otro.');
			}

			if (typeof productsBefore === 'number') {
				where = productsBefore
					? 'Ubicación: después del producto ' + productsBefore + '.'
					: 'Ubicación: antes del primer producto.';
			}

			$card.toggleClass('has-image', ad.hasImage);
			$card.find('.ap-ad-number').text(ad.index + 1);
			$card.find('.ap-badge').attr('class', 'ap-badge ap-badge--' + status.key).text(status.label);
			$card.find('.ap-ad-card__note')
				.text(notes.join(' '))
				.toggleClass('is-warning', WARNING_STATUSES.indexOf(status.key) !== -1 || others.length > 0)
				.prop('hidden', notes.length === 0);
			$card.find('.ap-ad-card__where').text(where).prop('hidden', !where);
			$card.find('.ap-ad-column').attr('max', columns);
		});

		renderPreview($editor, result.cells, result.products, columns, skipRows);
		$editor.find('.ap-empty').prop('hidden', ads.length > 0);
	}

	function refreshAll($scope) {
		($scope || $(document)).find('.ap-ads-editor').each(function () {
			refresh($(this));
		});
	}

	/* Acciones sobre tarjetas ------------------------------------------ */

	function highlight($card, scroll) {
		if (!$card || !$card.length) {
			return;
		}

		if (scroll && $card[0].scrollIntoView) {
			$card[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
		}

		$card.addClass('is-highlighted');
		window.setTimeout(function () {
			$card.removeClass('is-highlighted');
		}, 1600);
	}

	function setImage($card, id, url) {
		var $thumb = $card.find('.ap-ad-thumb');

		$card.removeAttr('data-image-missing');
		$card.find('.ap-ad-image-id').val(id || '');

		if (url) {
			$thumb.attr('src', url).prop('hidden', false);
		} else {
			$thumb.removeAttr('src').prop('hidden', true);
		}

		$card.find('.ap-ad-card__placeholder').prop('hidden', Boolean(url));
		markDirty();
		refresh($card.closest('.ap-ads-editor'));
	}

	function openMediaFrame($card) {
		if (!window.wp || !wp.media) {
			window.alert('No se pudo abrir la biblioteca de medios. Recarga la página e inténtalo de nuevo.');
			return;
		}

		var frame = wp.media({
			title: 'Elegir la imagen del anuncio',
			button: { text: 'Usar esta imagen' },
			library: { type: 'image' },
			multiple: false
		});

		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			var sizes = attachment.sizes || {};
			var url = (sizes.medium && sizes.medium.url) || (sizes.large && sizes.large.url) || attachment.url;

			setImage($card, attachment.id, url);
		});

		frame.open();
	}

	function nextPlacement($editor) {
		var columns = getColumns($editor);
		var skipRows = getSkipRows($editor);
		var ads = readAds($editor, columns);
		var row = 1;
		var column = columns;

		// Por defecto: debajo del ultimo anuncio, en la misma columna.
		if (ads.length) {
			var last = ads.reduce(function (a, b) {
				return b.position > a.position ? b : a;
			});
			row = last.row + 1;
			column = last.column;
		}

		while (skipRows.indexOf(row) !== -1) {
			row++;
		}

		return { row: row, column: column };
	}

	function addCard($editor, placement) {
		var template = $editor.find('.ap-ad-template').html();

		if (!template) {
			return;
		}

		var index = toInt($editor.attr('data-next-index'), $editor.find('.ap-ad-card').length);
		var html = template.replace(/__INDEX__/g, String(index)).trim();
		var $card = $($.parseHTML(html)).filter('.ap-ad-card').first();

		$editor.attr('data-next-index', index + 1);
		$card.find('.ap-ad-row').val(placement.row);
		$card.find('.ap-ad-column').val(placement.column);
		$editor.find('.ap-ad-list').append($card);

		markDirty();
		refresh($editor);
		highlight($card, true);
		openMediaFrame($card);
	}

	/* Categorias y marcas ---------------------------------------------- */

	function updateMode($box) {
		var mode = $box.find('.ap-mode-input:checked').val() || 'global';

		$box.find('.ap-mode__option').each(function () {
			var $option = $(this);
			$option.toggleClass('is-selected', $option.find('.ap-mode-input').is(':checked'));
		});

		$box.find('[data-ap-modes]').each(function () {
			var modes = String($(this).attr('data-ap-modes')).split(/\s+/);
			$(this).prop('hidden', modes.indexOf(mode) === -1);
		});

		refreshAll($box);
	}

	function updateColumnsHint() {
		var $hint = $('.ap-columns-hint');

		if (!$hint.length) {
			return;
		}

		var detected = toInt($hint.attr('data-detected'), 0);
		var current = toInt($('.ap-grid-columns-input').val(), 0);

		$hint.find('.ap-hint--ok').prop('hidden', detected !== current);
		$hint.find('.ap-hint--warn').prop('hidden', detected === current);
	}

	/* Eventos ---------------------------------------------------------- */

	$(document)
		.on('click', '.ap-select-image', function (event) {
			event.preventDefault();
			openMediaFrame($(this).closest('.ap-ad-card'));
		})
		.on('click', '.ap-remove-image', function (event) {
			event.preventDefault();
			setImage($(this).closest('.ap-ad-card'), '', '');
		})
		.on('click', '.ap-remove-ad', function (event) {
			event.preventDefault();

			var $card = $(this).closest('.ap-ad-card');
			var $editor = $card.closest('.ap-ads-editor');
			var hasContent = toInt($card.find('.ap-ad-image-id').val(), 0) > 0 || $card.find('.ap-ad-link').val();

			if (hasContent && !window.confirm('¿Eliminar este anuncio? El cambio se aplica al guardar.')) {
				return;
			}

			$card.remove();
			markDirty();
			refresh($editor);
		})
		.on('click', '.ap-add-ad', function (event) {
			event.preventDefault();

			var $editor = $(this).closest('.ap-ads-editor');
			addCard($editor, nextPlacement($editor));
		})
		.on('click', '.ap-cell--product', function (event) {
			event.preventDefault();

			var $cell = $(this);

			if ($cell.prop('disabled')) {
				return;
			}

			addCard($cell.closest('.ap-ads-editor'), {
				row: toInt($cell.attr('data-row'), 1),
				column: toInt($cell.attr('data-column'), 1)
			});
		})
		.on('click', '.ap-cell--ad', function (event) {
			event.preventDefault();

			var $editor = $(this).closest('.ap-ads-editor');
			var $card = $editor.find('.ap-ad-card').eq(toInt($(this).attr('data-ad-index'), 0));

			highlight($card, true);
			$card.find('.ap-ad-link').trigger('focus');
		})
		.on('input change', '.ap-ads-editor .ap-input', function (event) {
			var $input = $(this);
			var $editor = $input.closest('.ap-ads-editor');

			if (event.type === 'change' && $input.is('.ap-ad-row, .ap-ad-column')) {
				var max = $input.hasClass('ap-ad-column') ? getColumns($editor) : 999;
				var value = Math.max(1, Math.min(max, toInt($input.val(), 1)));

				if (String(value) !== String($input.val())) {
					$input.val(value);
				}
			}

			refresh($editor);
		})
		.on('input change', '.ap-grid-columns-input, .ap-skip-rows-input', function (event) {
			var $input = $(this);
			var $scope = getScope($input);

			if (event.type === 'change' && $input.hasClass('ap-grid-columns-input')) {
				var columns = Math.max(1, Math.min(8, toInt($input.val(), 4)));

				$input.val(columns);
				$scope.find('.ap-ad-column').each(function () {
					if (toInt($(this).val(), 1) > columns) {
						$(this).val(columns);
					}
				});
			}

			refreshAll($scope);
			updateColumnsHint();
		})
		.on('click', '.ap-use-detected', function (event) {
			event.preventDefault();
			$('.ap-grid-columns-input').val($(this).attr('data-columns')).trigger('change');
		})
		.on('change', '.ap-mode-input', function () {
			updateMode($(this).closest('.ap-term-box'));
		})
		.on('input change', '.ap-settings-form :input', markDirty)
		.on('submit', '.ap-settings-form', function () {
			dirty = false;
		});

	window.addEventListener('beforeunload', function (event) {
		if (!dirty) {
			return;
		}

		event.preventDefault();
		event.returnValue = '';
	});

	// Al crear una categoria o marca por AJAX, WordPress solo limpia los
	// campos de texto visibles: dejamos el formulario de anuncios como nuevo.
	$(document).ajaxSuccess(function (event, xhr, settings) {
		if (!settings || typeof settings.data !== 'string' || settings.data.indexOf('action=add-tag') === -1) {
			return;
		}

		if (xhr && typeof xhr.responseText === 'string' && xhr.responseText.indexOf('<wp_error') !== -1) {
			return;
		}

		var $box = $('#addtag .ap-term-box');

		if (!$box.length) {
			return;
		}

		$box.find('.ap-ad-card').remove();
		$box.find('.ap-skip-rows-input').val('');
		$box.find('.ap-mode-input[value="global"]').prop('checked', true);
		updateMode($box);
	});

	$(function () {
		refreshAll();
		$('.ap-term-box').each(function () {
			updateMode($(this));
		});
		updateColumnsHint();
	});
})(jQuery);
