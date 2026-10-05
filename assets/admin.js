/* global jQuery, wp */
/**
 * Anuncios entre productos: panel de administracion.
 *
 * - Vista previa de la grilla que simula exactamente como la tienda coloca
 *   los anuncios (misma logica que show_ad() en PHP).
 * - Tarjetas de anuncio de tres tipos: imagen, video subido y YouTube.
 * - Selector de modo en categorias y marcas.
 */
(function ($) {
	'use strict';

	var config = window.apAnuncioAdmin || {};
	var TODAY = config.today || new Date().toISOString().slice(0, 10);
	var PRODUCT_RATIO = parseFloat(config.productRatio) || 1;
	var MAX_PREVIEW_ROWS = 12;
	var MAX_VIDEO_BYTES = 5 * 1024 * 1024;
	var MEDIA_BOX_WIDTH = 132;
	var WARNING_STATUSES = ['missing', 'error', 'hidden'];
	var DEFAULT_FORMAT = { image: 'auto', video: 'product', youtube: 'auto' };

	var PLACEHOLDERS = {
		image: { icon: 'dashicons-format-image', text: 'Elegir imagen' },
		video: { icon: 'dashicons-video-alt3', text: 'Elegir video' },
		youtube: { icon: 'dashicons-youtube', text: 'Pega el enlace abajo' }
	};

	var MISSING = {
		image: {
			label: 'Falta la imagen',
			note: 'Elige una imagen. Los anuncios sin imagen no se guardan.',
			gone: 'La imagen que tenía este anuncio ya no existe en la biblioteca de medios. Elige otra.',
			goneAttr: 'data-image-missing'
		},
		video: {
			label: 'Falta el video',
			note: 'Elige un video de la biblioteca de medios. Los anuncios sin video no se guardan.',
			gone: 'El video que tenía este anuncio ya no existe en la biblioteca de medios. Elige otro.',
			goneAttr: 'data-video-missing'
		},
		youtube: {
			label: 'Falta el enlace',
			note: 'Pega el enlace del Short o del video de YouTube. Los anuncios sin un enlace válido no se guardan.'
		}
	};

	var FRAMES = {
		image: { title: 'Elegir la imagen del anuncio', button: 'Usar esta imagen', library: 'image' },
		cover: { title: 'Elegir la portada', button: 'Usar como portada', library: 'image' },
		video: { title: 'Elegir el video del anuncio', button: 'Usar este video', library: 'video' }
	};

	var YOUTUBE_PATTERNS = [
		{ re: /youtube(?:-nocookie)?\.com\/shorts\/([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])/i, short: true },
		{ re: /youtu\.be\/([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])/i, short: false },
		{ re: /youtube(?:-nocookie)?\.com\/(?:embed|live|v)\/([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])/i, short: false },
		{ re: /youtube\.com\/.*[?&]v=([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])/i, short: false }
	];

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

	function formatMegabytes(bytes) {
		return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB';
	}

	/** Misma logica que parse_youtube() en PHP. */
	function parseYoutube(value) {
		var text = String(value || '').trim();

		if (!text) {
			return { id: '', short: false, empty: true };
		}

		if (/^[A-Za-z0-9_-]{11}$/.test(text)) {
			return { id: text, short: false, empty: false };
		}

		for (var i = 0; i < YOUTUBE_PATTERNS.length; i++) {
			var match = text.match(YOUTUBE_PATTERNS[i].re);

			if (match) {
				return { id: match[1], short: YOUTUBE_PATTERNS[i].short, empty: false };
			}
		}

		return { id: '', short: false, empty: false };
	}

	function youtubeThumb(id) {
		return 'https://i.ytimg.com/vi/' + id + '/hqdefault.jpg';
	}

	/** Misma logica que get_youtube_thumb_scale() en PHP. */
	function youtubeThumbScale(boxRatio, videoRatio) {
		var imageRatio = 4 / 3;
		var frameWidth = videoRatio < imageRatio ? videoRatio / imageRatio : 1;
		var frameHeight = videoRatio < imageRatio ? 1 : imageRatio / videoRatio;
		var imageWidth = boxRatio > imageRatio ? boxRatio : imageRatio;
		var imageHeight = boxRatio > imageRatio ? boxRatio / imageRatio : 1;

		return Math.max(1, boxRatio / (imageWidth * frameWidth), 1 / (imageHeight * frameHeight));
	}

	function setSrc($element, url) {
		if (url) {
			if ($element.attr('src') !== url) {
				$element.attr('src', url);
			}
			$element.prop('hidden', false);
		} else {
			$element.removeAttr('src').prop('hidden', true);
		}
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

	function getType($card) {
		return $card.find('.ap-ad-type:checked').val() || 'image';
	}

	function readAds($editor, columns) {
		return $editor.find('.ap-ad-card').map(function (index) {
			var $card = $(this);
			var type = getType($card);
			var row = Math.max(1, toInt($card.find('.ap-ad-row').val(), 1));
			var column = Math.max(1, Math.min(columns, toInt($card.find('.ap-ad-column').val(), columns)));
			var imageUrl = toInt($card.find('.ap-ad-image-id').val(), 0) > 0 ? ($card.attr('data-image-url') || '') : '';
			var videoUrl = toInt($card.find('.ap-ad-video-id').val(), 0) > 0 ? ($card.attr('data-video-url') || '') : '';
			var youtube = parseYoutube($card.find('.ap-ad-youtube').val());
			var thumb = imageUrl;
			var thumbIsYoutube = false;

			if (type === 'video' && !imageUrl) {
				thumb = '';
			}

			if (type === 'youtube' && !imageUrl && youtube.id) {
				thumb = youtubeThumb(youtube.id);
				thumbIsYoutube = true;
			}

			return {
				index: index,
				$card: $card,
				type: type,
				row: row,
				column: column,
				position: (row - 1) * columns + column,
				imageUrl: imageUrl,
				videoUrl: videoUrl,
				videoSize: toInt($card.attr('data-video-size'), 0),
				videoMime: $card.attr('data-video-mime') || '',
				videoRatio: parseFloat($card.attr('data-video-ratio')) || 0,
				youtube: youtube,
				hasMedia: type === 'video' ? Boolean(videoUrl) : (type === 'youtube' ? Boolean(youtube.id) : Boolean(imageUrl)),
				thumb: thumb,
				thumbIsYoutube: thumbIsYoutube,
				format: $card.find('.ap-ad-format').val() || DEFAULT_FORMAT[type],
				link: $card.find('.ap-ad-link').val() || '',
				start: $card.find('.ap-ad-start').val() || '',
				end: $card.find('.ap-ad-end').val() || ''
			};
		}).get();
	}

	/** Proporcion ancho/alto con la que se vera el anuncio de video en la tienda. */
	function getRatio(ad) {
		if (ad.format === 'vertical') {
			return 9 / 16;
		}

		if (ad.format === 'horizontal') {
			return 16 / 9;
		}

		if (ad.format === 'auto') {
			if (ad.type === 'youtube') {
				return ad.youtube.short ? 9 / 16 : 16 / 9;
			}

			if (ad.type === 'video' && ad.videoRatio) {
				return ad.videoRatio;
			}
		}

		return PRODUCT_RATIO;
	}

	function hasInvalidDates(ad) {
		return Boolean(ad.start && ad.end && ad.end < ad.start);
	}

	function getStatus(ad, skipRows) {
		if (!ad.hasMedia) {
			if (ad.type === 'youtube' && !ad.youtube.empty) {
				return {
					key: 'missing',
					label: 'Enlace no válido',
					note: 'Ese enlace no parece de YouTube. Cópialo con el botón «Compartir» del Short o del video.'
				};
			}

			var missing = MISSING[ad.type];

			return {
				key: 'missing',
				label: missing.label,
				note: missing.goneAttr && ad.$card.attr(missing.goneAttr) ? missing.gone : missing.note
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

	/** Avisos propios de los videos: peso, formato y forma. */
	function getMediaNotes(ad) {
		var notes = [];

		if (ad.type === 'video' && ad.hasMedia) {
			if (ad.videoSize > MAX_VIDEO_BYTES) {
				notes.push({ warn: true, text: 'Este video pesa ' + formatMegabytes(ad.videoSize) + '. Para que la tienda cargue rápido conviene que pese menos de 5 MB.' });
			}

			if (/quicktime/i.test(ad.videoMime)) {
				notes.push({ warn: true, text: 'Los videos .mov no se ven en todos los navegadores. Es mejor subirlo en formato MP4.' });
			}
		}

		if (ad.type !== 'image' && ad.hasMedia) {
			var ratio = getRatio(ad);

			if (ratio < PRODUCT_RATIO * 0.85) {
				notes.push({ warn: false, text: 'Con esta forma el anuncio queda más alto que los productos de su fila.' });
			} else if (ratio > PRODUCT_RATIO * 1.3) {
				notes.push({ warn: false, text: 'Con esta forma el anuncio queda más bajo que los productos de su fila.' });
			}
		}

		if (ad.type === 'youtube' && ad.hasMedia && !ad.link) {
			notes.push({ warn: false, text: 'Si agregas un enlace, debajo del video aparecerá un botón para ir a tu oferta.' });
		}

		return notes;
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

	/* Tarjeta: mostrar los campos y la vista previa segun el tipo ------- */

	function paintCard(ad) {
		var $card = ad.$card;
		var type = ad.type;
		var placeholder = PLACEHOLDERS[type];

		$card.removeClass('ap-ad-card--image ap-ad-card--video ap-ad-card--youtube')
			.addClass('ap-ad-card--' + type)
			.toggleClass('has-media', ad.hasMedia);

		$card.find('.ap-type__option').each(function () {
			var $option = $(this);
			$option.toggleClass('is-selected', $option.find('.ap-ad-type').is(':checked'));
		});

		$card.find('[data-ap-types]').each(function () {
			var types = String($(this).attr('data-ap-types')).split(/\s+/);
			$(this).prop('hidden', types.indexOf(type) === -1);
		});

		// Caja de la izquierda: imagen, video o miniatura de YouTube.
		var $picker = $card.find('.ap-media-picker');
		var $image = $card.find('.ap-media-img');
		var $video = $card.find('.ap-media-video');
		var boxRatio = 1;

		if (type === 'image') {
			$picker.css('height', '');
		} else {
			var boxHeight = Math.round(Math.max(74, Math.min(235, MEDIA_BOX_WIDTH / getRatio(ad))));
			$picker.css('height', boxHeight + 'px');
			boxRatio = MEDIA_BOX_WIDTH / boxHeight;
		}

		setSrc($image, type === 'image' ? ad.imageUrl : (type === 'youtube' ? ad.thumb : ''));
		$image.toggleClass('is-cover', type !== 'image')
			.css('transform', ad.thumbIsYoutube ? 'scale(' + youtubeThumbScale(boxRatio, ad.youtube.short ? 9 / 16 : 16 / 9).toFixed(3) + ')' : '');

		if (type === 'video' && ad.videoUrl) {
			var video = $video[0];

			if ($video.attr('src') !== ad.videoUrl) {
				$video.attr('src', ad.videoUrl);
			}

			if (ad.imageUrl) {
				$video.attr('poster', ad.imageUrl);
			} else {
				$video.removeAttr('poster');
			}

			$video.prop('hidden', false);
			video.muted = true;

			if (video.paused && video.play) {
				var promise = video.play();
				if (promise && promise.catch) {
					promise.catch(function () {});
				}
			}
		} else if ($video.attr('src')) {
			$video[0].pause();
			$video.removeAttr('src').removeAttr('poster').prop('hidden', true);
			$video[0].load();
		} else {
			$video.prop('hidden', true);
		}

		$card.find('.ap-media-play').prop('hidden', !(type === 'youtube' && ad.hasMedia));
		$card.find('.ap-ad-card__placeholder').prop('hidden', ad.hasMedia)
			.find('.dashicons').attr('class', 'dashicons ' + placeholder.icon).end()
			.find('.ap-placeholder-text').text(placeholder.text);
		$picker.attr('aria-label', type === 'youtube' ? 'Escribir el enlace de YouTube' : placeholder.text);

		// Portada opcional de video y YouTube (usa la misma imagen del anuncio).
		var $cover = $card.find('.ap-cover');
		setSrc($cover.find('.ap-cover__thumb'), ad.imageUrl);
		$cover.find('.ap-cover-select').text(ad.imageUrl ? 'Cambiar' : 'Elegir imagen');
		$cover.find('.ap-cover-remove').prop('hidden', !ad.imageUrl);
		$cover.find('.ap-cover__help').text($cover.find('.ap-cover__help').attr('data-text-' + type) || '');

		var $linkLabel = $card.find('.ap-link-label');
		$linkLabel.text($linkLabel.attr(type === 'youtube' ? 'data-text-youtube' : 'data-text-default'));
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

			if (ad.hasMedia && ad.thumb) {
				var $img = $('<img alt="">').attr('src', ad.thumb);

				if (ad.thumbIsYoutube) {
					$img.css('transform', 'scale(' + youtubeThumbScale(2.5, ad.youtube.short ? 9 / 16 : 16 / 9).toFixed(3) + ')');
				}

				$cell.append($img);
			} else if (ad.hasMedia) {
				$cell.addClass('is-dark');
			} else {
				$cell.addClass('is-empty');
			}

			if (ad.status.key !== 'active' && ad.status.key !== 'missing') {
				$cell.addClass('is-inactive');
			}

			if (ad.type !== 'image') {
				$cell.append($('<span class="ap-cell__kind dashicons" aria-hidden="true"></span>')
					.addClass(ad.type === 'video' ? 'dashicons-video-alt3' : 'dashicons-youtube'));
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
			var warn = WARNING_STATUSES.indexOf(status.key) !== -1;
			var others = byPosition[ad.position].filter(function (number) {
				return number !== ad.index + 1;
			});
			var productsBefore = placedAt[ad.index];
			var $card = ad.$card;
			var where = '';

			ad.status = status;

			if (others.length && status.key !== 'hidden') {
				notes.push('Comparte casilla con el anuncio ' + others.join(', ') + ': se mostrarán uno a continuación del otro.');
				warn = true;
			}

			getMediaNotes(ad).forEach(function (note) {
				notes.push(note.text);
				warn = warn || note.warn;
			});

			if (typeof productsBefore === 'number') {
				where = productsBefore
					? 'Ubicación: después del producto ' + productsBefore + '.'
					: 'Ubicación: antes del primer producto.';
			}

			paintCard(ad);
			$card.find('.ap-ad-number').text(ad.index + 1);
			$card.find('.ap-badge').attr('class', 'ap-badge ap-badge--' + status.key).text(status.label);
			$card.find('.ap-ad-card__note')
				.text(notes.join(' '))
				.toggleClass('is-warning', warn)
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
		$card.removeAttr('data-image-missing');
		$card.find('.ap-ad-image-id').val(id || '');
		$card.attr('data-image-url', url || '');
		markDirty();
		refresh($card.closest('.ap-ads-editor'));
	}

	function setVideo($card, attachment) {
		attachment = attachment || {};

		var ratio = attachment.width && attachment.height ? attachment.width / attachment.height : 0;

		$card.removeAttr('data-video-missing');
		$card.find('.ap-ad-video-id').val(attachment.id || '');
		$card.attr({
			'data-video-url': attachment.url || '',
			'data-video-mime': attachment.mime || '',
			'data-video-size': attachment.filesizeInBytes || 0,
			'data-video-ratio': ratio ? ratio.toFixed(4) : ''
		});
		markDirty();
		refresh($card.closest('.ap-ads-editor'));
	}

	function openMediaFrame($card, kind) {
		if (!window.wp || !wp.media) {
			window.alert('No se pudo abrir la biblioteca de medios. Recarga la página e inténtalo de nuevo.');
			return;
		}

		var settings = FRAMES[kind] || FRAMES.image;
		var frame = wp.media({
			title: settings.title,
			button: { text: settings.button },
			library: { type: settings.library },
			multiple: false
		});

		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();

			if (kind === 'video') {
				setVideo($card, attachment);
				return;
			}

			var sizes = attachment.sizes || {};
			var url = (sizes.medium && sizes.medium.url) || (sizes.large && sizes.large.url) || attachment.url;

			setImage($card, attachment.id, url);
		});

		frame.open();
	}

	/** Abre lo que corresponde para elegir el contenido principal del anuncio. */
	function chooseMedia($card) {
		var type = getType($card);

		if (type === 'youtube') {
			$card.find('.ap-ad-youtube').trigger('focus');
			return;
		}

		openMediaFrame($card, type === 'video' ? 'video' : 'image');
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

	function addCard($editor, placement, type) {
		var template = $editor.find('.ap-ad-template').html();

		if (!template) {
			return;
		}

		type = PLACEHOLDERS[type] ? type : 'image';

		var index = toInt($editor.attr('data-next-index'), $editor.find('.ap-ad-card').length);
		var html = template.replace(/__INDEX__/g, String(index)).trim();
		var $card = $($.parseHTML(html)).filter('.ap-ad-card').first();

		$editor.attr('data-next-index', index + 1);
		$card.find('.ap-ad-type[value="' + type + '"]').prop('checked', true);
		$card.find('.ap-ad-format').val(DEFAULT_FORMAT[type]);
		$card.find('.ap-ad-row').val(placement.row);
		$card.find('.ap-ad-column').val(placement.column);
		$editor.find('.ap-ad-list').append($card);

		markDirty();
		refresh($editor);
		highlight($card, true);
		chooseMedia($card);
	}

	/* Menu "¿Que quieres poner aqui?" de la vista previa ---------------- */

	function closeAddMenus() {
		$('.ap-add-menu').prop('hidden', true).removeData('placement');
		$('.ap-cell.is-pending').removeClass('is-pending');
	}

	function openAddMenu($editor, $cell) {
		var $wrap = $editor.find('.ap-preview-wrap');
		var $menu = $editor.find('.ap-add-menu');

		if (!$menu.length) {
			addCard($editor, { row: toInt($cell.attr('data-row'), 1), column: toInt($cell.attr('data-column'), 1) }, 'image');
			return;
		}

		closeAddMenus();

		var wrapRect = $wrap[0].getBoundingClientRect();
		var cellRect = $cell[0].getBoundingClientRect();

		$menu.data('placement', { row: toInt($cell.attr('data-row'), 1), column: toInt($cell.attr('data-column'), 1) })
			.prop('hidden', false);

		var left = cellRect.left - wrapRect.left + (cellRect.width / 2) - ($menu.outerWidth() / 2);
		left = Math.max(8, Math.min(left, wrapRect.width - $menu.outerWidth() - 8));

		$menu.css({ top: Math.round(cellRect.bottom - wrapRect.top + 6) + 'px', left: Math.round(left) + 'px' });
		$cell.addClass('is-pending');
		$menu.find('.ap-add-menu__item').first().trigger('focus');
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
		.on('click', '.ap-media-picker, .ap-media-change', function (event) {
			event.preventDefault();
			chooseMedia($(this).closest('.ap-ad-card'));
		})
		.on('click', '.ap-media-remove', function (event) {
			event.preventDefault();

			var $card = $(this).closest('.ap-ad-card');

			if (getType($card) === 'video') {
				setVideo($card, null);
			} else {
				setImage($card, '', '');
			}
		})
		.on('click', '.ap-cover-select', function (event) {
			event.preventDefault();
			openMediaFrame($(this).closest('.ap-ad-card'), 'cover');
		})
		.on('click', '.ap-cover-remove', function (event) {
			event.preventDefault();
			setImage($(this).closest('.ap-ad-card'), '', '');
		})
		.on('change', '.ap-ad-type', function () {
			var $card = $(this).closest('.ap-ad-card');

			if (!$card.attr('data-format-touched')) {
				$card.find('.ap-ad-format').val(DEFAULT_FORMAT[getType($card)]);
			}

			markDirty();
			refresh($card.closest('.ap-ads-editor'));
		})
		.on('change', '.ap-ad-format', function () {
			$(this).closest('.ap-ad-card').attr('data-format-touched', '1');
		})
		.on('click', '.ap-remove-ad', function (event) {
			event.preventDefault();

			var $card = $(this).closest('.ap-ad-card');
			var $editor = $card.closest('.ap-ads-editor');
			var hasContent = toInt($card.find('.ap-ad-image-id').val(), 0) > 0
				|| toInt($card.find('.ap-ad-video-id').val(), 0) > 0
				|| $card.find('.ap-ad-youtube').val()
				|| $card.find('.ap-ad-link').val();

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
			addCard($editor, nextPlacement($editor), $(this).attr('data-type'));
		})
		.on('click', '.ap-cell--product', function (event) {
			event.preventDefault();
			event.stopPropagation();

			var $cell = $(this);

			if (!$cell.prop('disabled')) {
				openAddMenu($cell.closest('.ap-ads-editor'), $cell);
			}
		})
		.on('click', '.ap-add-menu__item', function (event) {
			event.preventDefault();

			var $menu = $(this).closest('.ap-add-menu');
			var placement = $menu.data('placement');
			var $editor = $menu.closest('.ap-ads-editor');

			closeAddMenus();

			if (placement) {
				addCard($editor, placement, $(this).attr('data-type'));
			}
		})
		.on('click', function (event) {
			if (!$(event.target).closest('.ap-add-menu').length) {
				closeAddMenus();
			}
		})
		.on('keydown', function (event) {
			if (event.key === 'Escape' && $('.ap-add-menu:not([hidden])').length) {
				closeAddMenus();
			}
		})
		.on('click', '.ap-cell--ad', function (event) {
			event.preventDefault();

			var $editor = $(this).closest('.ap-ads-editor');
			var $card = $editor.find('.ap-ad-card').eq(toInt($(this).attr('data-ad-index'), 0));

			highlight($card, true);
			$card.find('.ap-ad-type:checked').trigger('focus');
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
		closeAddMenus();
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
