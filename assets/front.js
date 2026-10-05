/**
 * Anuncios entre productos: videos en la tienda.
 *
 * - Los videos subidos se reproducen sin sonido y en bucle solo mientras se
 *   ven en pantalla, y se pausan al salir para no gastar datos ni bateria.
 * - Los videos de YouTube muestran una miniatura; el reproductor se carga
 *   solo cuando el cliente la toca.
 */
(function () {
	'use strict';

	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var observer = null;

	function showControls(video) {
		video.controls = true;
		video.preload = 'metadata';
	}

	function play(video) {
		video.muted = true;
		var promise = video.play();

		// Si el navegador bloquea la reproduccion automatica (por ejemplo, en
		// modo ahorro de bateria) se muestran los controles para tocar "play".
		if (promise && typeof promise.catch === 'function') {
			promise.catch(function () {
				showControls(video);
			});
		}
	}

	if ('IntersectionObserver' in window) {
		observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				entry.target.setAttribute('data-ap-inview', entry.isIntersecting ? '1' : '0');

				if (entry.isIntersecting) {
					play(entry.target);
				} else {
					entry.target.pause();
				}
			});
		}, { rootMargin: '200px 0px' });
	}

	function setupVideos(root) {
		var videos = (root || document).querySelectorAll('video[data-ap-autoplay]:not([data-ap-ready])');

		Array.prototype.forEach.call(videos, function (video) {
			video.setAttribute('data-ap-ready', '1');
			video.muted = true;

			if (reduceMotion) {
				showControls(video);
			} else if (observer) {
				observer.observe(video);
			} else {
				play(video);
			}
		});
	}

	// Al volver a la pestana, el navegador puede haber pausado los videos:
	// se reanudan los que estan en pantalla.
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState !== 'visible' || reduceMotion) {
			return;
		}

		var videos = document.querySelectorAll('video[data-ap-autoplay][data-ap-inview="1"]');

		Array.prototype.forEach.call(videos, function (video) {
			if (video.paused && !video.controls) {
				play(video);
			}
		});
	});

	function loadYoutube(box) {
		var id = box.getAttribute('data-ap-youtube') || '';

		if (!/^[A-Za-z0-9_-]{11}$/.test(id)) {
			return false;
		}

		var iframe = document.createElement('iframe');
		iframe.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&playsinline=1&rel=0&loop=1&playlist=' + id;
		iframe.title = 'Video de YouTube';
		iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
		iframe.setAttribute('allowfullscreen', '');
		iframe.className = 'ap-ad-youtube__frame';

		box.innerHTML = '';
		box.appendChild(iframe);
		box.classList.add('is-playing');

		return true;
	}

	document.addEventListener('click', function (event) {
		var target = event.target;
		var trigger = target && target.closest ? target.closest('.ap-ad-youtube__play') : null;

		if (!trigger) {
			return;
		}

		var box = trigger.closest('[data-ap-youtube]');

		if (box && loadYoutube(box)) {
			event.preventDefault();
		}
	});

	function init() {
		setupVideos(document);

		// Flatsome puede agregar productos sin recargar (scroll infinito, filtros).
		if ('MutationObserver' in window && document.body) {
			var pending = null;

			new MutationObserver(function () {
				if (pending) {
					return;
				}

				pending = window.setTimeout(function () {
					pending = null;
					setupVideos(document);
				}, 250);
			}).observe(document.body, { childList: true, subtree: true });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
