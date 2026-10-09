'use strict';

/*
 * Listen: the player.
 *
 * - The headphones button (top bar) plays the current view from the first article on screen (or the open one),
 *   in feed order. Articles the server says aren't worth listening to are skipped; worthwhile ones get a 🎧 badge
 *   that plays from that article.
 * - Each article (an "episode") is played piece by piece from ?c=Listen&a=audio, always through the same single
 *   <audio> element (so two voices can never overlap). The server answers with complete, checked pieces only;
 *   while one piece plays, the next is already requested so it's ready when needed (at most one piece paid ahead).
 * - A watchdog restarts a piece that stalls, then gives up on the article rather than going quiet.
 * - At the end of the loaded articles it asks FreshRSS to load more, and carries on.
 * - Each episode gets a voice of its own (never the same as the one before); finished episodes are marked read.
 * - Read-along: where the article's text is on screen (reader view, or an article opened in the list), the
 *   paragraphs being read are highlighted and scrolled to, as long as you're following along (not scrolled away).
 */
(function () {
	const SPEED_KEY = 'listen.speed';
	const SPEEDS = [0.8, 1, 1.15, 1.3, 1.5, 1.75, 2];
	const PLAN_WINDOW = 20;   // articles planned at a time when looking for the next episode

	let cfg;
	const plans = new Map();      // entry id -> { id, ok, reason, parts, title, feed }
	const voiceOf = new Map();    // entry id -> voice it was given, so going back replays the same (cached) audio
	const STALL_CHECK_MS = 2000;
	const STALL_LIMIT = 5;        // checks without progress (10 s) before a stalled piece is retried

	const audio = new Audio();
	const ready = new Set();      // pieces the server has (so playing them starts at once)
	const pending = new Map();    // piece -> request getting it ready
	let queued = null;            // { ep, part } coming up next, already being prepared
	let ep = null;                // the episode playing: { id, parts, title, feed, voice, index }
	let part = 0;
	let active = false;
	let loading = false;          // waiting for the server to prepare the piece
	let playToken = 0;            // bumped on every move, so late answers for an old piece are ignored
	let stall = { time: -1, count: 0, retried: false };
	let bar;

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
			// private window: the speed just isn't remembered
		}
	}

	function notify(msg, status) {
		if (typeof window.openNotification === 'function') {
			window.openNotification(msg, status || 'bad');
		}
	}

	function post(url, pairs) {
		const body = new URLSearchParams(pairs);
		body.append('_csrf', context.csrf);
		return fetch(url, { method: 'POST', body, credentials: 'same-origin' })
			.then((resp) => (resp.ok ? resp.json() : Promise.reject(resp.status)));
	}

	// ---- which articles get read ----

	function fluxes() {
		return [...document.querySelectorAll('#stream .flux[data-entry]')];
	}

	function fluxOf(id) {
		return document.getElementById('flux_' + id);
	}

	async function ensurePlans(list) {
		const ids = list.map((flux) => flux.dataset.entry).filter((id) => !plans.has(id));
		for (let i = 0; i < ids.length; i += 100) {
			const chunk = ids.slice(i, i + 100);
			const json = await post(cfg.urls.plan, chunk.map((id) => ['ids[]', id]));
			(json.items || []).forEach((item) => plans.set(String(item.id), item));
			chunk.forEach((id) => plans.has(id) || plans.set(id, { id, ok: false, reason: 'missing' }));
		}
		list.forEach(decorate);
	}

	function decorate(flux) {
		const plan = plans.get(flux.dataset.entry);
		if (!plan || !plan.ok || flux.querySelector('.listen-badge')) {
			return;
		}
		flux.classList.add('listen-ok');
		const badge = document.createElement('button');
		badge.type = 'button';
		badge.className = 'listen-badge';
		badge.title = cfg.i18n.play_this + ' (' + Math.max(1, Math.round(plan.words / 150)) + ' min)';
		badge.setAttribute('aria-label', badge.title);
		badge.textContent = '🎧';
		const row = flux.querySelector('.flux_header .titleAuthorSummaryDate');
		const heading = flux.querySelector('.content > header .title');
		if (row) {
			// List view: a column of its own in front of the title
			const item = document.createElement('li');
			item.className = 'item listen-badge-item';
			item.append(badge);
			row.before(item);
		} else if (heading) {
			heading.prepend(badge);   // reader view
		}
	}

	function voiceFor(id, previous) {
		if (voiceOf.has(id) && voiceOf.get(id) !== previous) {
			return voiceOf.get(id);
		}
		let h = 0;
		for (const c of id) {
			h = (h * 31 + c.charCodeAt(0)) >>> 0;
		}
		const n = cfg.voices.length;
		let voice = cfg.voices[h % n];
		if (voice === previous) {
			voice = cfg.voices[(h + 1) % n];
		}
		voiceOf.set(id, voice);
		return voice;
	}

	function episode(flux, previousVoice) {
		const plan = plans.get(flux.dataset.entry);
		return {
			id: plan.id, parts: plan.parts, starts: plan.starts || [], title: plan.title, feed: plan.feed,
			voice: voiceFor(plan.id, previousVoice), index: fluxes().indexOf(flux),
		};
	}

	// FreshRSS's own "load more"; resolves true once new articles are in
	function loadMore() {
		if (!document.getElementById('load_more') || typeof window.load_more_posts !== 'function') {
			return Promise.resolve(false);
		}
		return new Promise((resolve) => {
			const timer = setTimeout(() => finish(false), 20000);
			function finish(ok) {
				clearTimeout(timer);
				document.body.removeEventListener('freshrss:load-more', onLoaded);
				resolve(ok);
			}
			function onLoaded() {
				finish(true);
			}
			document.body.addEventListener('freshrss:load-more', onLoaded);
			window.load_more_posts();
		});
	}

	// The first worthwhile article at or after list position `index`
	async function findFrom(index, previousVoice) {
		for (;;) {
			const list = fluxes();
			for (let i = index; i < list.length; i += PLAN_WINDOW) {
				const slice = list.slice(i, i + PLAN_WINDOW);
				await ensurePlans(slice);
				const hit = slice.find((flux) => plans.get(flux.dataset.entry).ok);
				if (hit) {
					return episode(hit, previousVoice);
				}
			}
			index = Math.max(index, list.length);
			if (!(await loadMore())) {
				return null;
			}
		}
	}

	function nextAfter(e) {
		const i = fluxes().findIndex((flux) => flux.dataset.entry === e.id);
		return findFrom((i >= 0 ? i : e.index - 1) + 1, e.voice);
	}

	function previousBefore(e) {
		const list = fluxes();
		const i = list.findIndex((flux) => flux.dataset.entry === e.id);
		for (let j = (i >= 0 ? i : e.index) - 1; j >= 0; j--) {
			const plan = plans.get(list[j].dataset.entry);
			if (plan && plan.ok) {
				return episode(list[j], null);
			}
		}
		return null;
	}

	function startIndex(list) {
		const open = list.findIndex((flux) => flux.classList.contains('current'));
		if (open >= 0) {
			return open;
		}
		const top = document.querySelector('.mui-bar, #global > .nav_menu');
		const covered = top ? Math.max(0, top.getBoundingClientRect().bottom) : 0;
		const visible = list.findIndex((flux) => flux.getBoundingClientRect().bottom > covered + 40);
		return visible < 0 ? 0 : visible;
	}

	// ---- playback ----

	function audioUrl(e, p) {
		return cfg.urls.audio + '&id=' + encodeURIComponent(e.id) + '&part=' + p + '&voice=' + encodeURIComponent(e.voice) +
			'&_csrf=' + encodeURIComponent(context.csrf);
	}

	function pieceKey(e, p) {
		return e.id + '|' + p + '|' + e.voice;
	}

	function speed() {
		const s = Number(load(SPEED_KEY, 1));
		return SPEEDS.includes(s) ? s : 1;
	}

	// Asks the server for a piece and waits until it's generated and checked. The answer lands in the browser
	// cache too, so the <audio> element gets it at once. Retries once, unless retrying can't help.
	function preparePiece(e, p) {
		const key = pieceKey(e, p);
		if (ready.has(key)) {
			return Promise.resolve();
		}
		if (!pending.has(key)) {
			const request = (async () => {
				for (let attempt = 0; ; attempt++) {
					let status = 0;
					let message = '';
					try {
						const resp = await fetch(audioUrl(e, p), { credentials: 'same-origin' });
						status = resp.status;
						if (resp.ok) {
							await resp.arrayBuffer();
							ready.add(key);
							return;
						}
						message = await resp.json().then((j) => j.error || '').catch(() => '');
					} catch (err) {
						// network: worth another try
					}
					if (attempt >= 1 || [400, 403, 404, 429, 503].includes(status)) {
						throw Object.assign(new Error(message || 'audio'), { status, message });
					}
					await new Promise((resolve) => setTimeout(resolve, 1500));
				}
			})();
			pending.set(key, request);
			request.catch(() => {}).finally(() => pending.delete(key));
		}
		return pending.get(key);
	}

	// A moment of silence played inside the click: phones only let a page start audio from a tap, and after that
	// the same element may keep playing whatever it's given (also with the screen locked)
	function unlockAudio() {
		audio.src = cfg.urls.silence;
		audio.dataset.piece = '';
		audio.play().catch(() => {});
	}

	function start(fromFlux) {
		if (!cfg.ready) {
			notify(cfg.i18n.no_key);
			return;
		}
		active = true;
		loading = true;
		unlockAudio();
		showBar();
		render();
		const list = fluxes();
		findFrom(fromFlux ? Math.max(0, list.indexOf(fromFlux)) : startIndex(list), ep ? ep.voice : null).then((e) => {
			if (!active) {
				return;
			}
			if (e) {
				playEpisode(e, 0);
			} else {
				notify(cfg.i18n.nothing);
				stop();
			}
		}, () => {
			notify(cfg.i18n.failed);
			stop();
		});
	}

	function playEpisode(e, p) {
		const token = ++playToken;
		const isNew = !ep || ep.id !== e.id;
		const follow = following();
		ep = e;
		part = p;
		queued = null;
		stall = { time: -1, count: 0, retried: false };
		audio.pause();
		if (isNew) {
			episodeStarted(follow);
		}
		readAlong(follow && !isNew);
		loading = !ready.has(pieceKey(e, p));
		render();
		preparePiece(e, p).then(() => {
			if (token !== playToken || !active) {
				return;   // moved on meanwhile
			}
			loading = false;
			audio.src = audioUrl(e, p);
			audio.dataset.piece = pieceKey(e, p);
			audio.defaultPlaybackRate = speed();   // loading a new source resets playbackRate to this
			audio.playbackRate = speed();
			audio.play().catch(() => render());
			render();
		}, (err) => {
			if (token === playToken && active) {
				pieceFailed(err);
			}
		});
		prefetch(token);
	}

	async function prefetch(token) {
		const playing = ep;
		let next;
		if (part + 1 < playing.parts) {
			next = { ep: playing, part: part + 1 };
		} else {
			const e = await nextAfter(playing).catch(() => null);
			if (!e) {
				return;
			}
			next = { ep: e, part: 0 };
		}
		if (token !== playToken || !active) {
			return;   // skipped or stopped meanwhile
		}
		queued = next;
		preparePiece(next.ep, next.part).catch(() => {});   // its problems are dealt with when it's its turn
	}

	function playing() {
		return ep && audio.dataset.piece === pieceKey(ep, part);
	}

	function onEnded() {
		if (!active || loading || !playing()) {
			return;   // the unlocking silence, or a piece we've already moved on from
		}
		if (part + 1 < ep.parts) {
			playEpisode(ep, part + 1);
			return;
		}
		finished(ep);
		if (queued && queued.part === 0) {
			playEpisode(queued.ep, 0);
		} else {
			advance(ep);
		}
	}

	async function advance(from) {
		const token = playToken;
		const e = await nextAfter(from).catch(() => null);
		if (!active || token !== playToken) {
			return;
		}
		if (e) {
			playEpisode(e, 0);
		} else {
			notify(cfg.i18n.finished, 'good');
			stop();
		}
	}

	function finished(e) {
		const flux = fluxOf(e.id);
		if (cfg.mark_read && flux && flux.classList.contains('not_read') && typeof window.mark_read === 'function') {
			window.mark_read(flux, true, false);
		}
	}

	function onError() {
		if (active && !loading && playing()) {
			pieceFailed(null);
		}
	}

	// A piece couldn't be had or played: stop if it's the key or the cap, otherwise skip to the next article
	async function pieceFailed(err) {
		const token = ++playToken;
		const failed = ep;
		let status = err ? err.status : 0;
		let message = err ? err.message : '';
		if (!status) {
			const s = await fetch(cfg.urls.status, { credentials: 'same-origin' }).then((r) => r.json()).catch(() => null);
			status = s && !s.ready ? 503 : (s && s.capped ? 429 : 0);
			message = '';
			if (!active || token !== playToken) {
				return;
			}
		}
		if (status === 429) {
			notify(cfg.i18n.cap_reached);
			stop();
		} else if (status === 503) {
			notify(message && message !== 'No OpenAI API key' ? 'Listen: ' + message : cfg.i18n.no_key);
			stop();
		} else {
			notify(cfg.i18n.failed);
			advance(failed);
		}
	}

	// Playing but not getting anywhere: try the piece again from where it got stuck, then give up on the article
	function watchdog() {
		if (!active || loading || audio.paused || audio.ended || !playing()) {
			stall.count = 0;
			return;
		}
		if (audio.currentTime !== stall.time) {
			stall.time = audio.currentTime;
			stall.count = 0;
			return;
		}
		if (++stall.count < STALL_LIMIT) {
			return;
		}
		stall.count = 0;
		if (stall.retried) {
			pieceFailed(null);
			return;
		}
		stall.retried = true;
		const at = audio.currentTime;
		audio.addEventListener('loadedmetadata', () => { audio.currentTime = at; }, { once: true });
		audio.load();
		audio.play().catch(() => {});
	}

	function toggle() {
		if (!active) {
			start(null);
		} else if (audio.paused) {
			audio.play().catch(() => {});
		} else {
			audio.pause();
		}
	}

	async function skip() {
		if (!ep) {
			return;
		}
		const token = playToken;
		const e = queued && queued.part === 0 ? queued.ep : await nextAfter(ep).catch(() => null);
		if (token !== playToken) {
			return;
		}
		if (e) {
			playEpisode(e, 0);
		} else {
			notify(cfg.i18n.finished, 'good');
		}
	}

	function back() {
		if (!ep) {
			return;
		}
		if (part > 0 || audio.currentTime > 3) {
			playEpisode(ep, 0);   // from the top; the audio is cached by now
			return;
		}
		const e = previousBefore(ep);
		playEpisode(e || ep, 0);
	}

	function seek(seconds) {
		audio.currentTime = Math.max(0, audio.currentTime + seconds);
	}

	function stop() {
		active = false;
		loading = false;
		playToken++;
		queued = null;
		audio.pause();
		audio.removeAttribute('src');
		audio.dataset.piece = '';
		audio.load();
		document.querySelectorAll('.listen-current').forEach((flux) => flux.classList.remove('listen-current'));
		document.querySelectorAll('.listen-reading').forEach((el) => el.classList.remove('listen-reading'));
		ep = null;
		if (bar) {
			bar.hidden = true;
		}
		document.documentElement.classList.remove('listen-active');
		if ('mediaSession' in navigator) {
			navigator.mediaSession.metadata = null;
		}
		render();
	}

	function episodeStarted(follow) {
		document.querySelectorAll('.listen-current').forEach((flux) => flux.classList.remove('listen-current'));
		const flux = fluxOf(ep.id);
		if (flux) {
			flux.classList.add('listen-current');
			if (follow) {
				flux.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		}
		if ('mediaSession' in navigator && typeof MediaMetadata === 'function') {
			const icon = flux && flux.querySelector('img.favicon');
			navigator.mediaSession.metadata = new MediaMetadata({
				title: ep.title,
				artist: ep.feed,
				album: 'FreshRSS · ' + cfg.i18n.ai_voice + ' (' + ep.voice + ')',
				artwork: icon ? [{ src: new URL(icon.getAttribute('src'), location.href).href }] : [],
			});
		}
	}

	// ---- read-along ----

	function inView(el) {
		const r = el.getBoundingClientRect();
		return r.height > 0 && r.bottom > 0 && r.top < window.innerHeight;
	}

	// Following along = what's being read is still on screen (or nothing is playing yet); scrolled away = leave the page alone
	function following() {
		const reading = document.querySelector('.listen-reading');
		if (reading && reading.offsetParent !== null) {
			return inView(reading);
		}
		const flux = ep && fluxOf(ep.id);
		return !flux || inView(flux);
	}

	function words(text) {
		return text.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
	}

	function blocksOf(flux) {
		const text = flux.querySelector('.text');
		return text ? [...text.querySelectorAll('p, li, h1, h2, h3, h4, h5, h6, dd, dt, blockquote:not(:has(p))')] : [];
	}

	// The paragraph where each piece starts, found by the piece's first words (searching forward, so repeats don't confuse it)
	function pieceStarts(e, flux) {
		if (e.blocks && e.blocks[0] && e.blocks[0].isConnected) {
			return e.pieceStart;
		}
		e.blocks = blocksOf(flux);
		const texts = e.blocks.map((b) => words(b.textContent));
		e.pieceStart = [0];
		let from = 0;
		for (let k = 1; k < e.parts; k++) {
			const snippet = words(e.starts[k] || '');
			const found = snippet ? texts.findIndex((t, i) => i >= from && t.includes(snippet)) : -1;
			e.pieceStart.push(found);
			if (found >= 0) {
				from = found;
			}
		}
		return e.pieceStart;
	}

	function readAlong(scroll) {
		document.querySelectorAll('.listen-reading').forEach((el) => el.classList.remove('listen-reading'));
		const flux = ep && fluxOf(ep.id);
		if (!flux) {
			return;
		}
		const starts = pieceStarts(ep, flux);
		const start = starts[part];
		if (start === undefined || start < 0 || ep.blocks.length === 0) {
			return;
		}
		const next = starts.slice(part + 1).find((i) => i >= 0);
		const end = next === undefined ? ep.blocks.length : Math.max(next, start + 1);
		const span = ep.blocks.slice(start, end).filter((el) => el.offsetParent !== null);   // hidden in a closed article: nothing to show
		span.forEach((el) => el.classList.add('listen-reading'));
		if (scroll && span.length > 0) {
			span[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	}

	// ---- the bar ----

	const ICONS = {
		play: '<path d="M4 2.5v11l9-5.5z" fill="currentColor"/>',
		pause: '<rect x="3.5" y="2.5" width="3" height="11" rx="1" fill="currentColor"/><rect x="9.5" y="2.5" width="3" height="11" rx="1" fill="currentColor"/>',
		next: '<path d="M2.5 2.5v11l7.5-5.5z" fill="currentColor"/><rect x="11" y="2.5" width="2.5" height="11" rx="1" fill="currentColor"/>',
		previous: '<path d="M13.5 2.5v11L6 8z" fill="currentColor"/><rect x="2.5" y="2.5" width="2.5" height="11" rx="1" fill="currentColor"/>',
		close: '<path d="M3.5 3.5l9 9m0-9l-9 9" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>',
	};

	function iconButton(cls, icon, label, onClick) {
		const btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'listen-btn ' + cls;
		btn.title = label;
		btn.setAttribute('aria-label', label);
		btn.innerHTML = '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">' + ICONS[icon] + '</svg>';
		btn.addEventListener('click', onClick);
		return btn;
	}

	function showBar() {
		document.documentElement.classList.add('listen-active');
		if (bar) {
			bar.hidden = false;
			return;
		}
		bar = document.createElement('div');
		bar.className = 'listen-bar';
		bar.setAttribute('role', 'region');
		bar.setAttribute('aria-label', cfg.i18n.listen);

		const progress = document.createElement('div');
		progress.className = 'listen-progress';
		progress.append(document.createElement('span'));

		const info = document.createElement('div');
		info.className = 'listen-info';
		const title = document.createElement('a');
		title.className = 'listen-title';
		title.href = '#';
		title.addEventListener('click', (e) => {
			e.preventDefault();
			const flux = ep && fluxOf(ep.id);
			if (flux) {
				flux.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		});
		const meta = document.createElement('span');
		meta.className = 'listen-meta';
		info.append(title, meta);

		const rate = document.createElement('select');
		rate.className = 'listen-speed';
		rate.title = cfg.i18n.speed;
		rate.setAttribute('aria-label', cfg.i18n.speed);
		SPEEDS.forEach((s) => rate.append(new Option(s + '×', String(s), false, s === speed())));
		rate.addEventListener('change', () => {
			save(SPEED_KEY, Number(rate.value));
			audio.defaultPlaybackRate = speed();
			audio.playbackRate = speed();
		});

		bar.append(
			progress,
			iconButton('listen-prev', 'previous', cfg.i18n.previous, back),
			iconButton('listen-toggle', 'pause', cfg.i18n.pause, toggle),
			iconButton('listen-next', 'next', cfg.i18n.next, skip),
			info, rate,
			iconButton('listen-close', 'close', cfg.i18n.close, stop),
		);
		document.body.append(bar);
	}

	function render() {
		const isPlaying = active && !loading && !audio.paused;
		document.querySelectorAll('.listen-play').forEach((btn) => btn.classList.toggle('active', active));
		if (!bar) {
			return;
		}
		const toggleBtn = bar.querySelector('.listen-toggle');
		toggleBtn.innerHTML = '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">' + ICONS[isPlaying || (active && loading) ? 'pause' : 'play'] + '</svg>';
		toggleBtn.title = isPlaying ? cfg.i18n.pause : cfg.i18n.play;
		toggleBtn.setAttribute('aria-label', toggleBtn.title);
		bar.querySelector('.listen-title').textContent = ep ? ep.title : '…';
		bar.querySelector('.listen-meta').textContent = ep ? [ep.feed, ep.voice + ' · ' + cfg.i18n.ai_voice].filter(Boolean).join(' · ') : '';
		bar.classList.toggle('listen-loading', active && (loading || audio.readyState < 3));
		renderProgress();
	}

	function renderProgress() {
		if (!bar || !ep) {
			return;
		}
		const d = audio.duration;
		const within = playing() && Number.isFinite(d) && d > 0 ? Math.min(1, audio.currentTime / d) : 0;
		bar.querySelector('.listen-progress span').style.width = (100 * (part + within) / Math.max(1, ep.parts)).toFixed(1) + '%';
	}

	// ---- wiring ----

	function onClick(ev) {
		const play = ev.target.closest('.listen-play');
		if (play) {
			ev.preventDefault();
			toggle();
			return;
		}
		const badge = ev.target.closest('.listen-badge');
		if (badge) {
			ev.preventDefault();
			ev.stopPropagation();   // don't let FreshRSS open/close the article
			const flux = badge.closest('.flux');
			if (active && ep && flux.dataset.entry === ep.id) {
				toggle();
			} else {
				start(flux);
			}
		}
	}

	function init() {
		if (cfg || !window.context || !context.extensions || !context.extensions.listen || context.anonymous) {
			return;
		}
		cfg = context.extensions.listen;
		document.addEventListener('click', onClick, true);
		audio.preload = 'auto';
		audio.addEventListener('ended', onEnded);
		audio.addEventListener('error', onError);
		audio.addEventListener('timeupdate', renderProgress);
		['play', 'pause', 'playing', 'waiting', 'canplay'].forEach((type) => audio.addEventListener(type, render));
		setInterval(watchdog, STALL_CHECK_MS);
		if ('mediaSession' in navigator) {
			const handlers = {
				play: () => audio.play(), pause: () => audio.pause(), nexttrack: skip, previoustrack: back,
				seekbackward: () => seek(-15), seekforward: () => seek(15),
			};
			Object.entries(handlers).forEach(([action, fn]) => {
				try {
					navigator.mediaSession.setActionHandler(action, fn);
				} catch (e) {
					// not supported by this browser
				}
			});
		}

		const stream = document.getElementById('stream');
		if (!stream) {
			return;
		}
		// 🎧 badges on the articles worth listening to, including ones loaded later
		ensurePlans(fluxes()).catch(() => {});
		let pending = 0;
		new MutationObserver(() => {
			clearTimeout(pending);
			pending = setTimeout(() => ensurePlans(fluxes().filter((f) => !plans.has(f.dataset.entry))).catch(() => {}), 300);
		}).observe(stream, { childList: true });
	}

	if (window.context) {
		init();
	} else {
		document.addEventListener('freshrss:globalContextLoaded', init);
	}
})();
