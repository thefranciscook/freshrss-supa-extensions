/*
 * Minimal UI: DOM changes that CSS cannot do.
 *
 * Elements are moved, never cloned, so FreshRSS's own handlers and ids keep working
 * (e.g. the shift+r shortcut looks up `.nav_menu .read_all`, which is why the whole
 * nav.nav_menu goes into the sidebar instead of being taken apart).
 */
(function () {
	'use strict';

	const NARROW = window.matchMedia('(max-width: 840px)');

	// Picks the day-of-week tint of the top glow (see minimal.css). Set right away, not on
	// DOMContentLoaded: this script is async, so it usually runs before the first paint.
	document.documentElement.setAttribute('data-mui-day', String(new Date().getDay()));

	function i18n(key, fallback) {
		const ext = window.context && window.context.extensions;
		return (ext && ext.minimal_ui && ext.minimal_ui[key]) || fallback;
	}

	/* ---------- Layout ---------- */

	function buildLayout() {
		// Settings pages reuse id="aside_feed"; only the reading sidebar has the class too
		const aside = document.querySelector('#aside_feed.aside_feed');
		const nav = document.querySelector('#global > nav.nav_menu');
		const toggle = nav && nav.querySelector('#nav_menu_toggle_aside');
		if (!aside || !nav || !toggle || document.querySelector('.mui-bar')) {
			return;
		}

		// 1. Top bar takes the toolbar's place: [☰] ... [extension buttons][filter][sort]
		const bar = document.createElement('div');
		bar.className = 'mui-bar';
		nav.before(bar);
		bar.append(toggle);

		const tools = document.createElement('div');
		tools.id = 'mui-tools';
		tools.className = 'group';
		const queries = nav.querySelector('#nav_menu_queries');
		const sort = nav.querySelector('#nav_menu_sort');
		if (queries) {
			buildFilterMenu(nav, queries);
			tools.append(queries);
		}
		if (sort) {
			tools.append(sort);
		}

		// 2. Sidebar: [search][refresh][settings] / [mark read][views] / [subscriptions] / [tree]
		const anchor = aside.querySelector('.configure-feeds') || aside.querySelector('form[name="mark_read_aside"]');
		const top = document.createElement('div');
		top.className = 'mui-search';
		const searchForm = document.querySelector('.header .item.search form');
		if (searchForm) {
			top.append(searchForm);
		}
		const refresh = nav.querySelector('#nav_menu_actualize');
		if (refresh) {
			top.append(refresh);
		}
		const settings = document.querySelector('.header nav.item.configure');
		if (settings) {
			settings.classList.add('group');
			top.append(settings);
		}
		aside.insertBefore(top, anchor);
		aside.insertBefore(nav, anchor);

		// Buttons other extensions add to the toolbar (NavMenu hook, e.g. Seen Read's dice) stay in the
		// top bar at every width: there's room for them even on phones
		const hooks = nav.querySelector('#nav_menu_hooks');
		if (hooks) {
			bar.append(hooks);
		}

		// 3. Filter + sort sit in the top bar on desktop, in the sidebar on mobile
		function placeTools() {
			if (NARROW.matches) {
				nav.append(tools);
			} else {
				bar.append(tools);
			}
		}
		placeTools();
		NARROW.addEventListener('change', placeTools);

		// Same bookkeeping FreshRSS does for clicks inside nav.nav_menu
		tools.addEventListener('click', (e) => {
			const sidebar = document.getElementById('sidebar');
			if (sidebar && e.target.closest('.mui-filter-item a, .query a')) {
				sessionStorage.setItem('FreshRSS_sidebar_scrollTop', sidebar.scrollTop);
			}
		});
	}

	/*
	 * The user-queries dropdown becomes the filter menu: the four read/unread/starred/
	 * non-starred toggles go on top, queries stay below. Reusing that dropdown keeps the
	 * user-query keyboard shortcut (it opens #dropdown-query) working.
	 */
	function buildFilterMenu(nav, queries) {
		const menu = queries.querySelector('.dropdown-menu');
		const button = queries.querySelector('#toggle-userqueries');
		if (!menu || !button) {
			return;
		}
		const label = i18n('filter', 'Filter');
		button.title = label;
		button.setAttribute('aria-label', label);

		const first = menu.firstElementChild;
		const toggles = nav.querySelectorAll('#nav_menu_actions > a[role="checkbox"]');
		toggles.forEach((a, i) => {
			const li = document.createElement('li');
			li.className = 'item mui-filter-item';
			const text = document.createElement('span');
			text.textContent = a.title;
			a.append(text);
			a.classList.remove('btn');
			li.append(a);
			menu.insertBefore(li, first);
			if (i === toggles.length - 1 && first) {
				first.classList.add('separator');
			}
		});
	}

	/* ---------- Reddit-style line above the title: [icon] category · 5 hours ago ---------- */

	function categoryOf(flux) {
		// The sidebar already knows every category's name and link; articles only carry its id
		const link = document.querySelector(`#c_${flux.dataset.category} > a.tree-folder-title`);
		const name = link && link.querySelector('.title');
		return name ? { name: name.textContent.trim(), href: link.href } : null;
	}

	function addKickers(root) {
		root.querySelectorAll('.flux .flux_content .content > header').forEach((header) => {
			if (header.querySelector('.mui-kicker')) {
				return;
			}
			const flux = header.closest('.flux');
			const kicker = document.createElement('div');
			kicker.className = 'mui-kicker';

			const favicon = header.querySelector('.website img.favicon');
			if (favicon) {
				const icon = document.createElement('img');
				icon.className = 'mui-kicker-icon';
				icon.src = favicon.src;
				icon.alt = '';
				icon.loading = 'lazy';
				kicker.append(icon);
			}
			const category = categoryOf(flux);
			if (category) {
				const a = document.createElement('a');
				a.className = 'mui-kicker-category';
				a.href = category.href;
				a.textContent = category.name;
				kicker.append(a);
			}
			// Move (not copy) the date so the relative-date updater keeps finding it
			const time = header.querySelector('.subtitle .item.date time');
			if (time) {
				if (kicker.childElementCount) {
					const dot = document.createElement('span');
					dot.className = 'mui-kicker-dot';
					dot.textContent = '•';
					dot.setAttribute('aria-hidden', 'true');
					kicker.append(dot);
				}
				kicker.append(time);
			}
			header.insertBefore(kicker, header.querySelector('.title'));
		});
	}

	/* ---------- Relative dates ---------- */

	const UNITS = [
		['year', 365 * 24 * 3600],
		['month', 30 * 24 * 3600],
		['week', 7 * 24 * 3600],
		['day', 24 * 3600],
		['hour', 3600],
		['minute', 60],
	];
	let rtf;
	try {
		rtf = new Intl.RelativeTimeFormat(document.documentElement.lang || undefined, { numeric: 'auto' });
	} catch (e) {
		rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
	}

	function relative(date) {
		const seconds = (date.getTime() - Date.now()) / 1000;
		const abs = Math.abs(seconds);
		for (const [unit, size] of UNITS) {
			if (abs >= size) {
				return rtf.format(Math.round(seconds / size), unit);
			}
		}
		return rtf.format(0, 'second');	// "now"
	}

	function updateDates(root) {
		root.querySelectorAll('.flux time[datetime]').forEach((time) => {
			const date = new Date(time.getAttribute('datetime'));
			if (isNaN(date)) {
				return;
			}
			if (!time.hasAttribute('data-mui-full')) {
				const full = time.textContent.trim();
				time.setAttribute('data-mui-full', full);
				time.title = full;
			}
			time.textContent = relative(date);
		});
	}

	function decorate(root) {
		addKickers(root);
		updateDates(root);
	}

	function watchStream() {
		const stream = document.getElementById('stream');
		if (!stream) {
			return;
		}
		decorate(stream);
		// New articles arrive via "load more" / auto-load
		new MutationObserver((mutations) => {
			for (const m of mutations) {
				m.addedNodes.forEach((node) => {
					if (node.nodeType === Node.ELEMENT_NODE) {
						decorate(node);
					}
				});
			}
		}).observe(stream, { childList: true, subtree: true });
		setInterval(() => updateDates(stream), 60 * 1000);
	}

	function init() {
		buildLayout();
		watchStream();
		if (!window.context) {
			// main.js not parsed yet: fix up the translated label once it is
			document.addEventListener('freshrss:globalContextLoaded', () => {
				const button = document.querySelector('#mui-tools #toggle-userqueries');
				if (button) {
					button.title = i18n('filter', button.title);
					button.setAttribute('aria-label', button.title);
				}
			}, { once: true });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init, { once: true });
	} else {
		init();
	}
}());
