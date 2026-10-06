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
 * - The dice (toolbar) swaps the stream for a fair random mix of this view, minus what you've seen.
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

	// ---- dice ----

	let rolling = false;
	let shuffled = false;
	let dealt = [];   // ids of the hand on screen, so "Roll again" deals a different one

	function fmt(str, ...args) {
		return str.replace(/%(\d)\$d/g, (m, i) => String(args[i - 1]));
	}

	function notify(msg) {
		if (typeof window.openNotification === 'function') {
			window.openNotification(msg, 'bad');
		}
	}

	// get / state / search of the page we're on: the dice draws from exactly what this view shows
	function viewParams() {
		const current = new URLSearchParams(location.search);
		return ['get', 'state', 'search'].filter((k) => current.has(k)).map((k) => [k, current.get(k)]);
	}

	// The picked articles, rendered by FreshRSS itself: the same page, narrowed down to them
	function fetchEntries(ids) {
		const url = new URL(location.href);
		url.hash = '';
		['cid', 'offset', 'idMax'].forEach((k) => url.searchParams.delete(k));
		url.searchParams.set('search', 'e:' + ids.join(','));
		url.searchParams.set('nb', String(ids.length));
		url.searchParams.set('sort', 'id');
		url.searchParams.set('order', 'DESC');
		return fetch(url, { credentials: 'same-origin' })
			.then((resp) => (resp.ok ? resp.text() : Promise.reject(resp.status)))
			.then((html) => new DOMParser().parseFromString(html, 'text/html'));
	}

	function roll() {
		if (rolling) {
			return;
		}
		rolling = true;
		document.querySelectorAll('.sr-dice').forEach((d) => d.classList.add('sr-rolling'));
		// Articles already looked at this visit are skipped: they're about to be marked read anyway
		const seen = Object.keys(load(QUEUE_KEY, {}));
		post(cfg.urls.shuffle, [
			...viewParams(),
			...seen.map((id) => ['seen[]', id]),
			...dealt.map((id) => ['previous[]', id]),
		]).then((json) => {
			if (!json.ids || json.ids.length === 0) {
				notify(cfg.i18n.nothing_to_shuffle);
				return;
			}
			return fetchEntries(json.ids).then((doc) => deal(doc, json.ids));
		}).catch(() => {
			notify(cfg.i18n.shuffle_failed);
		}).finally(() => {
			rolling = false;
			// let the spin finish its turn
			setTimeout(() => document.querySelectorAll('.sr-dice').forEach((d) => d.classList.remove('sr-rolling')), 400);
		});
	}

	function deal(doc, ids) {
		const stream = document.getElementById('stream');
		const byId = new Map();
		doc.querySelectorAll('#stream .flux').forEach((flux) => byId.set(flux.dataset.entry, flux));
		const fluxes = ids.filter((id) => byId.has(id)).map((id) => document.adoptNode(byId.get(id)));
		if (fluxes.length === 0) {
			notify(cfg.i18n.nothing_to_shuffle);
			return;
		}
		dealt = fluxes.map((flux) => flux.dataset.entry);
		fluxes.forEach((flux) => {
			if (typeof window.enforce_referrer_allowlist === 'function') {
				window.enforce_referrer_allowlist(flux);
			}
		});

		if (!shuffled) {
			shuffled = true;
			stream.classList.add('sr-shuffled');
			document.querySelectorAll('.sr-dice').forEach((d) => d.classList.add('active'));
			// Turn off endless scrolling, or reaching the bottom would append the next page of the normal stream
			const loadMore = document.getElementById('load_more');
			if (loadMore) {
				loadMore.remove();
				if (typeof window.init_load_more === 'function') {
					window.init_load_more(stream);
				}
			}
		}

		// Same-day separators mean nothing in a shuffle
		stream.querySelectorAll('.flux, .transition, .sr-shuffle-bar, .sr-shuffle-footer').forEach((el) => el.remove());
		const feeds = new Set(fluxes.map((flux) => flux.dataset.feed)).size;
		stream.prepend(shuffleBar(fmt(cfg.i18n.shuffled, fluxes.length, feeds)));
		const footer = stream.querySelector('.stream-footer');
		fluxes.forEach((flux) => stream.insertBefore(flux, footer));
		stream.insertBefore(shuffleFooter(), footer);
		document.scrollingElement.scrollTop = 0;
	}

	function backLink() {
		const back = document.createElement('a');
		back.href = location.href.split('#')[0];   // the page as it was: a reload ends the shuffle
		back.textContent = cfg.i18n.back_to_newest;
		return back;
	}

	function shuffleBar(text) {
		const bar = document.createElement('div');
		bar.className = 'sr-shuffle-bar';
		const label = document.createElement('span');
		label.textContent = '🎲 ' + text;
		bar.append(label, backLink());
		return bar;
	}

	function shuffleFooter() {
		const box = document.createElement('div');
		box.className = 'sr-shuffle-footer';
		const again = document.createElement('button');
		again.type = 'button';
		again.className = 'btn sr-roll-again';
		again.textContent = '🎲 ' + cfg.i18n.roll_again;
		again.addEventListener('click', roll);
		box.append(again, backLink());
		return box;
	}

	function onDiceClick(ev) {
		const dice = ev.target.closest('.sr-dice');
		if (!dice || ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) {
			return;   // modified clicks keep the link: FreshRSS's own random order, e.g. in a new tab
		}
		ev.preventDefault();
		roll();
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
		if (!context.hide_posts || shuffled || document.getElementById('load_more')) {
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
		const tree = aside.querySelector('#sidebar');   // newer FreshRSS wraps it in the mark-read form
		if (tree) {
			tree.before(wrap);
		} else {
			aside.append(wrap);
		}
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

		document.addEventListener('click', onDiceClick);
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
