'use strict';

/*
 * Seen Read: notices which unread articles you actually looked at and marks them read on your next visit.
 *
 * - An unread .flux counts as seen after `dwell_ms` of being in view (half of it visible, or filling half
 *   the screen) while it moves slower than `max_speed` screen heights per second. Flinging past doesn't count.
 * - Seen ids are queued in localStorage. Nothing changes during the visit.
 * - A visit ends after `gap_min` minutes without activity. The next activity (page load, tab coming back,
 *   scroll, tap) sends the queue to ?c=SeenRead&a=commit, which marks them read (plus aged-out ones).
 * - Rewind (sidebar, and at the end of the stream) undoes the newest batch.
 * - Exception: following a "Read more" link inside an article marks it read right away.
 */
(function () {
	const QUEUE_KEY = 'seenRead.queue';         // { entryId: seenAtMs }
	const ACTIVE_KEY = 'seenRead.lastActive';   // ms of the last activity, shared by all tabs
	const TICK_MS = 250;

	let cfg;
	let io;
	const intersecting = new Map();   // .flux -> { dwell, top, t }
	let lastTouch = 0;
	let committing = false;

	function load(key, fallback) {
		try {
			const v = JSON.parse(localStorage.getItem(key));
			return v === null || v === undefined ? fallback : v;
		} catch (e) {
			return fallback;
		}
	}

	function save(key, value) {
		try {
			localStorage.setItem(key, JSON.stringify(value));
		} catch (e) {
			// private window or blocked storage: seen tracking just doesn't persist
		}
	}

	function post(url, params) {
		const body = new URLSearchParams(params);
		body.append('_csrf', context.csrf);
		return fetch(url, { method: 'POST', body, credentials: 'same-origin' })
			.then((resp) => (resp.ok ? resp.json() : Promise.reject(resp.status)));
	}

	// ---- seen detection ----

	function isCandidate(flux) {
		return flux.classList.contains('not_read') && !flux.classList.contains('keep_unread') &&
			flux.dataset.entry && !flux.classList.contains('sr-seen');
	}

	function track(flux) {
		if (!flux.dataset.entry || flux.dataset.srTracked) {
			return;
		}
		flux.dataset.srTracked = '1';
		if (load(QUEUE_KEY, {})[flux.dataset.entry]) {
			flux.classList.add('sr-seen', 'sr-seen-before');   // seen earlier this visit, e.g. before a reload
		} else if (isCandidate(flux)) {
			io.observe(flux);
		}
	}

	function markSeen(flux) {
		const queue = load(QUEUE_KEY, {});
		queue[flux.dataset.entry] = Date.now();
		save(QUEUE_KEY, queue);
		flux.classList.add('sr-seen');
		io.unobserve(flux);
		intersecting.delete(flux);
	}

	function tick() {
		if (document.hidden || intersecting.size === 0) {
			return;
		}
		const now = performance.now();
		const vh = window.innerHeight;
		for (const [flux, st] of intersecting) {
			if (!flux.isConnected || !isCandidate(flux)) {
				io.unobserve(flux);
				intersecting.delete(flux);
				continue;
			}
			const rect = flux.getBoundingClientRect();
			const shown = Math.min(rect.bottom, vh) - Math.max(rect.top, 0);
			const inView = rect.height > 0 && (shown >= rect.height / 2 || shown >= vh / 2);
			const speed = st.t ? Math.abs(rect.top - st.top) / vh / ((now - st.t) / 1000) : Infinity;
			st.dwell = inView && speed <= cfg.max_speed ? st.dwell + (now - st.t) : 0;
			st.top = rect.top;
			st.t = now;
			if (st.dwell >= cfg.dwell_ms) {
				markSeen(flux);
			}
		}
	}

	// ---- read more ----

	// Link texts feeds use to send you to the full article
	const READ_MORE = /\b(read|continue|keep|view|see) (more|reading|on|the (full|whole|rest|original))\b|\bfull (story|article|post)\b|^\W*more\W*$|tovább|bővebben|weiterlesen|lire la suite|leer más/i;

	function sameUrl(a, b) {
		try {
			const norm = (u) => {
				const url = new URL(u, location.href);
				return (url.host.replace(/^www\./, '') + url.pathname.replace(/\/+$/, '') + url.search).toLowerCase();
			};
			return norm(a) === norm(b);
		} catch (e) {
			return false;
		}
	}

	// FreshRSS ignores links inside the article body, so following a feed's "Read more" link
	// (or any body link to the article itself) would leave the article unread. That click is as
	// clear a signal as it gets, so it's marked read right away instead of waiting for the next visit.
	function onLinkClick(ev) {
		if (ev.type === 'auxclick' && ev.button !== 1) {
			return;
		}
		const a = ev.target.closest('.flux .content .text a[href]');
		const flux = a && a.closest('.flux');
		if (!flux || !flux.classList.contains('not_read') || typeof window.mark_read !== 'function') {
			return;
		}
		if (READ_MORE.test(a.textContent) || (flux.dataset.link && sameUrl(a.href, flux.dataset.link))) {
			window.mark_read(flux, true, false);   // FreshRSS's own: updates the entry, its buttons and the counters
		}
	}

	// ---- visits and commits ----

	function touch() {
		const now = Date.now();
		if (now - lastTouch < 1000) {
			return;
		}
		lastTouch = now;
		const last = load(ACTIVE_KEY, 0);
		if (last && now - last > cfg.gap_min * 60000) {
			commit();
		}
		save(ACTIVE_KEY, now);
	}

	function commit() {
		const ids = Object.keys(load(QUEUE_KEY, {}));
		if (committing) {
			return;
		}
		committing = true;
		// Sent even when empty: the server also ages out old unseen articles at the start of a visit
		post(cfg.urls.commit, ids.map((id) => ['ids[]', id])).then((json) => {
			const queue = load(QUEUE_KEY, {});
			ids.forEach((id) => delete queue[id]);   // keep anything seen while the request was in flight
			save(QUEUE_KEY, queue);
			applyCommitted(json.committed || []);
			cfg.last_batch = json.last_batch;
			renderRewind();
		}).catch(() => {
			// keep the queue; the next visit tries again
		}).finally(() => {
			committing = false;
		});
	}

	function applyCommitted(ids) {
		let changed = false;
		ids.forEach((id) => {
			const flux = document.getElementById('flux_' + id);
			if (!flux) {
				return;
			}
			changed = true;
			if (context.hide_posts) {
				flux.remove();
			} else {
				flux.classList.remove('not_read', 'sr-seen', 'sr-seen-before');
			}
		});
		if (ids.length > 0 && typeof window.refreshUnreads === 'function') {
			window.refreshUnreads();   // FreshRSS's own counter refresh (sidebar, title, favicon)
		}
		if (changed) {
			renderCaughtUp();
		}
	}

	// ---- rewind + caught up ----

	function ago(ts) {
		const s = Math.max(0, Date.now() / 1000 - ts);
		if (s < 3600) return Math.max(1, Math.round(s / 60)) + 'm';
		if (s < 86400) return Math.round(s / 3600) + 'h';
		return Math.round(s / 86400) + 'd';
	}

	function rewindButton() {
		const btn = document.createElement('button');
		btn.type = 'button';   // the stream footer is a <form>
		btn.className = 'sr-rewind btn';
		btn.addEventListener('click', () => {
			btn.disabled = true;
			post(cfg.urls.rewind, {}).then(() => location.reload()).catch(() => {
				btn.disabled = false;
			});
		});
		return btn;
	}

	function renderRewind() {
		const b = cfg.last_batch;
		document.querySelectorAll('.sr-rewind').forEach((btn) => {
			btn.disabled = !b;
			btn.textContent = '↶ ' + (b ? cfg.i18n.rewind + ' · ' + b.count + ' · ' + ago(b.at) : cfg.i18n.nothing_to_rewind);
			btn.title = b ? cfg.i18n.rewind + ': ' + b.count + ' (' + b.kind + ', ' + new Date(b.at * 1000).toLocaleString() + ')' : '';
		});
	}

	function renderCaughtUp() {
		if (!context.hide_posts || document.getElementById('load_more')) {
			return;   // only at the real end of an unread-only stream
		}
		const host = document.querySelector('#stream .stream-footer-inner') || document.getElementById('noArticlesToShow');
		if (!host || host.querySelector('.sr-caught-up')) {
			return;
		}
		const box = document.createElement('div');
		box.className = 'sr-caught-up';
		const title = document.createElement('p');
		title.className = 'sr-caught-up-title';
		title.textContent = '✓ ' + cfg.i18n.caught_up;
		const recent = document.createElement('a');
		recent.href = cfg.urls.recent;
		recent.textContent = cfg.i18n.recently_read;
		box.append(title, rewindButton(), recent);
		host.prepend(box);
		renderRewind();
	}

	function renderSidebar() {
		const aside = document.getElementById('aside_feed');
		if (!aside || aside.querySelector('.sr-rewind-wrap')) {
			return;
		}
		const wrap = document.createElement('div');
		wrap.className = 'sr-rewind-wrap';
		wrap.append(rewindButton());
		aside.insertBefore(wrap, aside.querySelector('#sidebar'));
	}

	// ---- wiring ----

	function init() {
		if (cfg || !window.context || !context.extensions || !context.extensions.seen_read || context.anonymous) {
			return;
		}
		cfg = context.extensions.seen_read;
		const stream = document.getElementById('stream');
		renderSidebar();
		renderRewind();
		if (!stream) {
			return;
		}

		io = new IntersectionObserver((entries) => {
			entries.forEach((e) => {
				if (e.isIntersecting) {
					intersecting.set(e.target, { dwell: 0, top: 0, t: 0 });
				} else {
					intersecting.delete(e.target);
				}
			});
		});
		stream.querySelectorAll('.flux').forEach(track);
		// "load more" appends articles and swaps the footer
		new MutationObserver((mutations) => {
			mutations.forEach((m) => m.addedNodes.forEach((node) => {
				if (node.nodeType !== 1) return;
				if (node.matches('.flux')) track(node);
				node.querySelectorAll('.flux').forEach(track);
			}));
			renderCaughtUp();
		}).observe(stream, { childList: true, subtree: true });
		renderCaughtUp();

		document.addEventListener('click', onLinkClick);
		document.addEventListener('auxclick', onLinkClick);   // middle click

		setInterval(tick, TICK_MS);
		touch();
		['scroll', 'pointerdown', 'keydown', 'touchstart'].forEach((type) =>
			document.addEventListener(type, touch, { capture: true, passive: true }));
		document.addEventListener('visibilitychange', () => {
			if (!document.hidden) touch();
		});
	}

	if (window.context) {
		init();
	} else {
		document.addEventListener('freshrss:globalContextLoaded', init);
	}
})();
