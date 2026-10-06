<?php

declare(strict_types=1);

/**
 * Reading Rhythm: reorders each page of the stream for variety.
 *
 * FreshRSS has no hook over the list of entries, only per entry. So on the normal and
 * reader views we run the action ourselves from the ActionExecute hook, then wrap the
 * view's callbacks: callbackBeforeEntries buffers the page, hides posts over the daily
 * cap and reorders the rest (lib/Scheduler.php); callbackBeforePagination keeps paging
 * intact when hidden posts made the page shorter.
 */
final class ReadingRhythmExtension extends Minz_Extension {

	private const DEFAULT_CAP = 3;
	/** How many more batches to load to refill a page that the cap left short */
	private const MAX_EXTRA_FETCHES = 5;

	/** @var array<string,array<string,true>|null> "feedId@dayStart" => ids within the cap, null if unknown */
	private array $capCache = [];

	#[\Override]
	public function init(): void {
		parent::init();
		require_once __DIR__ . '/lib/Scheduler.php';

		$this->registerTranslates();
		$this->registerHook(Minz_HookType::ActionExecute, [$this, 'actionExecute']);
	}

	/**
	 * @return bool false when we already ran the action, so the dispatcher skips it
	 */
	public function actionExecute(Minz_ActionController $controller): bool {
		$action = Minz_Request::actionName();
		if (!($controller instanceof FreshRSS_index_Controller) || !in_array($action, ['normal', 'reader'], true)
			|| ($this->capPerDay() === 0 && !$this->mix() && !$this->images())) {
			return true;
		}

		$controller->{$action . 'Action'}();

		$view = $controller->view();
		if (!($view instanceof FreshRSS_View) || !is_callable($view->callbackBeforeEntries)
			// Opening a single feed shows all of it, in order. Other sorts (title, feed name…) are left alone.
			|| FreshRSS_Context::isFeed() || !in_array(FreshRSS_Context::$sort, ['id', 'date'], true)) {
			return false;
		}

		$this->wrapCallbacks($view);
		// The "Published today — …" dividers make no sense once the page is not chronological
		Minz_View::appendStyle($this->getFileUrl('rhythm.css', 'css'));
		return false;
	}

	private function wrapCallbacks(FreshRSS_View $view): void {
		$beforeEntries = $view->callbackBeforeEntries;
		$beforePagination = $view->callbackBeforePagination;
		$tailId = null;

		$view->callbackBeforeEntries = function (FreshRSS_View $view) use ($beforeEntries, &$tailId): void {
			$beforeEntries($view);
			if (!isset($view->entries) || !($view->entries instanceof Traversable)) {
				return;
			}

			// The core loads one entry more than a page: it is rendered last, then discarded and used
			// as the starting point of the next page. Keep it out of the reordering, and keep it last.
			[$entries, $tail] = $this->fillPage(iterator_to_array($view->entries, false));
			$tailId = $tail?->id();

			$entries = $this->rearrange($entries);
			if ($tail !== null) {
				$entries[] = $tail;
			}
			$view->entries = new ArrayIterator($entries);
		};

		$view->callbackBeforePagination = static function (?FreshRSS_View $view, int $nbEntries, FreshRSS_Entry $lastEntry)
			use ($beforePagination, &$tailId): void {
			// Hidden posts make the page shorter: still tell the core there is a next page
			if ($tailId !== null && $lastEntry->id() === $tailId) {
				$nbEntries = max($nbEntries, FreshRSS_Context::$number + 1);
			}
			$beforePagination($view, $nbEntries, $lastEntry);
		};
	}

	/**
	 * Drops posts over the daily cap and, when that leaves the page short, loads the following
	 * posts until the page is full again. Otherwise a burst of capped posts (one feed fetching
	 * 20 posts at once) leaves a page with only a few posts from that one feed, and nothing to
	 * mix them with.
	 * @param list<FreshRSS_Entry> $fetched what the core loaded: one page plus the next page's first entry
	 * @return array{0:list<FreshRSS_Entry>,1:FreshRSS_Entry|null} visible posts in database order, and the
	 *  entry the next page starts from (null on the last page)
	 */
	private function fillPage(array $fetched): array {
		$number = FreshRSS_Context::$number;
		$cap = $this->capPerDay();
		$page = [];
		for ($fetches = 0; ; $fetches++) {
			$tail = count($fetched) > $number ? array_pop($fetched) : null;
			foreach ($fetched as $entry) {
				if (count($page) >= $number) {
					// Full: the next page starts here
					return [$page, $entry];
				}
				if ($cap === 0 || $this->isWithinCap($entry, $cap)) {
					$page[] = $entry;
				}
			}
			if ($tail === null || count($page) >= $number || $fetches >= self::MAX_EXTRA_FETCHES || FreshRSS_Context::$offset !== 0) {
				return [$page, $tail];
			}
			// Paging is inclusive: the next batch starts with $tail itself
			FreshRSS_Context::$continuation_id = $tail->id();
			$fetched = iterator_to_array(FreshRSS_index_Controller::listEntriesByContext($number + 1), false);
		}
	}

	/**
	 * @param list<FreshRSS_Entry> $entries one page, in database order
	 * @return list<FreshRSS_Entry>
	 */
	private function rearrange(array $entries): array {
		$items = [];
		foreach ($entries as $key => $entry) {
			// We most likely already have the feed object in cache
			$feed = FreshRSS_Category::findFeed(FreshRSS_Context::categories(), $entry->feedId()) ?? $entry->feed();
			if ($feed !== null) {
				$entry->_feed($feed);
			}
			$items[] = [
				'key' => $key,
				'feed' => $entry->feedId(),
				'category' => $feed?->categoryId() ?? 0,
				'hasImage' => self::hasImage($entry),
			];
		}
		$scheduler = new ReadingRhythm_Scheduler(mix: $this->mix(), images: $this->images());
		return array_map(static fn(int|string $key): FreshRSS_Entry => $entries[$key], $scheduler->order($items));
	}

	/**
	 * A post is shown if it is among the first $cap posts its feed published that (local) day,
	 * read or not. So the same posts always make the cut, and later arrivals never replace
	 * ones already seen. One query per (feed, day), cached for the request.
	 */
	private function isWithinCap(FreshRSS_Entry $entry, int $cap): bool {
		$day = (new DateTimeImmutable('@' . $entry->date(raw: true)))
			->setTimezone(new DateTimeZone(date_default_timezone_get()))
			->setTime(0, 0);
		$key = $entry->feedId() . '@' . $day->getTimestamp();

		if (!array_key_exists($key, $this->capCache)) {
			$ids = FreshRSS_Factory::createEntryDao()->fetchColumn(
				'SELECT id FROM `_entry` WHERE id_feed = :feed AND date >= :start AND date < :end ORDER BY date, id LIMIT ' . $cap, 0, [
					':feed' => $entry->feedId(),
					':start' => $day->getTimestamp(),
					':end' => $day->modify('+1 day')->getTimestamp(),
				]);
			// On SQL error (already logged), don't hide anything from this feed and day
			$this->capCache[$key] = $ids === null ? null : array_fill_keys(array_map('strval', $ids), true);
		}
		return $this->capCache[$key] === null || isset($this->capCache[$key][$entry->id()]);
	}

	private static function hasImage(FreshRSS_Entry $entry): bool {
		return $entry->thumbnail() !== null
			|| preg_match('/<(?:img|video|picture)\b|youtube(?:-nocookie)?\.com\/embed\//i', $entry->content(withEnclosures: false)) === 1;
	}

	public function capPerDay(): int {
		$cap = $this->getUserConfigurationValue('cap_per_day', self::DEFAULT_CAP);
		return is_numeric($cap) ? max(0, (int)$cap) : self::DEFAULT_CAP;
	}

	public function mix(): bool {
		return (bool)$this->getUserConfigurationValue('mix', true);
	}

	public function images(): bool {
		return (bool)$this->getUserConfigurationValue('images', true);
	}

	#[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			$this->setUserConfiguration([
				'cap_per_day' => max(0, Minz_Request::paramInt('cap_per_day')),
				'mix' => Minz_Request::paramBoolean('mix'),
				'images' => Minz_Request::paramBoolean('images'),
			]);
		}
	}
}
