<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/BatchStore.php';

/**
 * Seen → read, Instagram style.
 *
 * static/seen.js notices which unread articles you actually looked at (on screen long enough,
 * not flung past) and queues them in localStorage. Nothing changes during the visit. On the next
 * visit (after a gap of inactivity) the queue is committed here and those articles become read.
 * Unread articles nobody ever scrolled to age out after N days. Every commit is kept as a batch
 * so the Rewind button can undo it, newest first.
 */
final class SeenReadExtension extends Minz_Extension {

	public const DEFAULTS = [
		'dwell_ms' => 1200,     // continuous time on screen before an article counts as seen
		'max_speed' => 1.5,     // viewport heights per second; faster scrolling doesn't count
		'gap_min' => 30,        // minutes of inactivity that end a visit
		'age_days' => 7,        // unseen unread articles older than this get marked read; 0 = off
	];
	private const AGE_OUT_EVERY = 3600;  // seconds between age-out runs from the maintenance hook

	#[\Override]
	public function init(): void {
		parent::init();
		$this->registerTranslates();

		// ?c=SeenRead&a=commit|rewind
		$this->registerController('SeenRead');

		$this->registerHook(Minz_HookType::JsVars, [$this, 'jsVars']);
		$this->registerHook(Minz_HookType::FreshrssUserMaintenance, [$this, 'maintenance']);

		Minz_View::appendStyle($this->getFileUrl('seen.css', 'css'));
		Minz_View::appendScript($this->getFileUrl('seen.js', 'js'));
	}

	public function setting(string $key): int|float {
		$v = $this->getUserConfigurationValue($key, self::DEFAULTS[$key]);
		return is_int($v) || is_float($v) ? $v : self::DEFAULTS[$key];
	}

	/**
	 * @param array<string,mixed> $vars
	 * @return array<string,mixed>
	 */
	public function jsVars(array $vars): array {
		$vars['seen_read'] = [
			'dwell_ms' => $this->setting('dwell_ms'),
			'max_speed' => $this->setting('max_speed'),
			'gap_min' => $this->setting('gap_min'),
			'last_batch' => $this->batches()->peek(),
			'urls' => [
				'commit' => Minz_Url::display(['c' => 'SeenRead', 'a' => 'commit'], 'php'),
				'rewind' => Minz_Url::display(['c' => 'SeenRead', 'a' => 'rewind'], 'php'),
				'recent' => Minz_Url::display(['c' => 'index', 'a' => 'index', 'params' => [
					'state' => FreshRSS_Entry::STATE_READ, 'sort' => 'lastUserModified', 'order' => 'DESC',
				]], 'php'),
			],
			'i18n' => [
				'rewind' => _t('ext.seen_read.rewind'),
				'nothing_to_rewind' => _t('ext.seen_read.nothing_to_rewind'),
				'caught_up' => _t('ext.seen_read.caught_up'),
				'recently_read' => _t('ext.seen_read.recently_read'),
			],
		];
		return $vars;
	}

	public function batches(): SeenRead_BatchStore {
		return new SeenRead_BatchStore($this->getUserConfigurationValue('batches'));
	}

	public function saveBatches(SeenRead_BatchStore $store): void {
		$this->setUserConfigurationValue('batches', $store->toArray());
	}

	/**
	 * Marks the given (seen) ids read plus anything that aged out, recording each as a batch.
	 * @param list<string> $seenIds
	 * @return array{seen:list<string>,aged:list<string>}
	 */
	public function commit(array $seenIds): array {
		require_once __DIR__ . '/lib/EntryQueries.php';
		$queries = new SeenRead_EntryQueries();
		$entryDAO = FreshRSS_Factory::createEntryDao();
		$store = $this->batches();

		$seen = $seenIds === [] ? [] : $queries->unreadAmong($seenIds);
		if ($seen !== [] && $entryDAO->markRead($seen, true) !== false) {
			$store->push('seen', $seen, time());
		}

		$aged = [];
		$days = (int)$this->setting('age_days');
		if ($days > 0) {
			$idMax = (string)((time() - $days * 86400) * 1000000);
			// Articles brought back with Rewind are left alone, or they would age out again within the hour
			$exempt = $this->exemptIds();
			$aged = $queries->unreadOlderThan($idMax, SeenRead_BatchStore::MAX_IDS + count($exempt));
			$aged = array_slice(array_values(array_diff($aged, $exempt)), 0, SeenRead_BatchStore::MAX_IDS);
			if ($aged !== [] && $entryDAO->markRead($aged, true) !== false) {
				$store->push('aged', $aged, time());
			}
		}

		if ($seen !== [] || $aged !== []) {
			$this->saveBatches($store);
		}
		$this->setUserConfigurationValue('last_age_out', time());
		return ['seen' => $seen, 'aged' => $aged];
	}

	/** @return array{restored:int,next:array{at:int,kind:string,count:int}|null} */
	public function rewind(): array {
		$store = $this->batches();
		$batch = $store->pop();
		$restored = 0;
		if ($batch !== null) {
			$restored = (int)FreshRSS_Factory::createEntryDao()->markRead($batch['ids'], false);
			$this->saveBatches($store);
			$exempt = array_merge($this->exemptIds(), $batch['ids']);
			$this->setUserConfigurationValue('age_exempt', array_slice($exempt, -SeenRead_BatchStore::MAX_IDS));
		}
		return ['restored' => $restored, 'next' => $store->peek()];
	}

	/** @return list<string> */
	private function exemptIds(): array {
		$raw = $this->getUserConfigurationValue('age_exempt', []);
		return SeenRead_BatchStore::cleanIds(is_array($raw) ? $raw : []);
	}

	/** Runs on every feed refresh (web and CLI); age-out at most once an hour. */
	public function maintenance(): void {
		$last = $this->getUserConfigurationValue('last_age_out', 0);
		if ((int)$this->setting('age_days') > 0 && time() - (is_int($last) ? $last : 0) >= self::AGE_OUT_EVERY) {
			$this->commit([]);
		}
	}

	#[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			$this->setUserConfigurationValue('dwell_ms', max(200, Minz_Request::paramInt('dwell_ms')));
			$this->setUserConfigurationValue('max_speed', max(0.1, (float)Minz_Request::paramString('max_speed')));
			$this->setUserConfigurationValue('gap_min', max(1, Minz_Request::paramInt('gap_min')));
			$this->setUserConfigurationValue('age_days', max(0, Minz_Request::paramInt('age_days')));
		}
	}
}
