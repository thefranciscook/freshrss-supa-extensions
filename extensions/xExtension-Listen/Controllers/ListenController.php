<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Speech.php';
require_once dirname(__DIR__) . '/lib/Mp3.php';

/**
 * Endpoints for static/listen.js.
 *   plan   (POST)  ids[]=… → which of these articles get read aloud, and in how many pieces
 *   audio  (GET)   id, part, voice, _csrf → one piece as a complete MP3: from the cache, or generated (and checked) first
 *   status (GET)   → whether an API key is set and how much of today's cap is left
 *   silence (GET)  → a quarter second of silence, played on the first tap so phones let the page play audio
 */
final class FreshExtension_Listen_Controller extends FreshRSS_ActionController {

	private const MAX_PLAN = 100;
	/** Tries per piece when the audio comes out cut off or padded with silence */
	private const ATTEMPTS = 3;
	private const MIN_JUDGED_CHARS = 150;

	private function extension(): ListenExtension {
		$ext = Minz_ExtensionManager::findExtension('Listen');
		if (!($ext instanceof ListenExtension) || !FreshRSS_Auth::hasAccess()) {
			$this->fail(403, 'Forbidden');
		}
		return $ext;
	}

	/** @param array<string,mixed> $data */
	private function json(array $data, int $status = 200): never {
		http_response_code($status);
		header('Content-Type: application/json; charset=UTF-8');
		header('Cache-Control: no-store');
		exit(json_encode($data));
	}

	private function fail(int $status, string $error): never {
		$this->json(['error' => $error], $status);
	}

	public function planAction(): void {
		$ext = $this->extension();
		if (!Minz_Request::isPost()) {   // FreshRSS checks the CSRF token of every POST
			$this->fail(405, 'POST only');
		}
		$ids = array_slice(array_values(array_filter(Minz_Request::paramArrayString('ids', plaintext: true), 'ctype_digit')), 0, self::MAX_PLAN);
		$items = [];
		foreach (FreshRSS_Factory::createEntryDao()->listByIds($ids) as $entry) {
			$article = $ext->article($entry);
			$reason = $ext->rejection($article);
			$parts = $reason === null ? $ext->parts($entry, $article) : [];
			$feed = $entry->feed();
			$items[] = [
				'id' => $entry->id(),
				'ok' => $reason === null,
				'reason' => $reason,
				'parts' => count($parts),
				// The first words of each piece, so the player can highlight what's being read (piece 0 starts with the title)
				'starts' => array_map(static fn(string $part, int $i) => $i === 0 ? '' :
					implode(' ', array_slice(preg_split('/\s+/u', strtok($part, "\n")) ?: [], 0, 8)), $parts, array_keys($parts)),
				'words' => $article->wordCount(),
				'title' => html_entity_decode(strip_tags($entry->title()), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
				'feed' => $feed === null ? '' : html_entity_decode($feed->name(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			];
		}
		$this->json(['items' => $items, 'ready' => $ext->apiKey() !== '']);
	}

	public function silenceAction(): void {
		$this->extension();
		$this->sendFile(dirname(__DIR__) . '/static/silence.mp3');
	}

	public function statusAction(): void {
		$ext = $this->extension();
		$cap = (float)$ext->setting('daily_cap');
		$this->json([
			'ready' => $ext->apiKey() !== '',
			'spent' => round($ext->spentToday(), 4),
			'cap' => $cap,
			'capped' => $cap > 0 && $ext->spentToday() >= $cap,
		]);
	}

	/**
	 * A GET, because <audio src> can only GET; the CSRF token in the URL stops other sites from making
	 * this FreshRSS spend money on your behalf.
	 */
	public function audioAction(): void {
		$ext = $this->extension();
		if (!hash_equals(FreshRSS_Auth::csrfToken(), Minz_Request::paramString('_csrf', plaintext: true))) {
			$this->fail(403, 'Bad token');
		}
		Minz_Session::unlock();   // don't hold up the rest of FreshRSS while a piece is generated

		$id = Minz_Request::paramString('id', plaintext: true);
		$part = Minz_Request::paramInt('part');
		$voice = Minz_Request::paramString('voice', plaintext: true);
		if (!ctype_digit($id) || !in_array($voice, ListenExtension::VOICES, true)) {
			$this->fail(400, 'Bad request');
		}
		$entry = FreshRSS_Factory::createEntryDao()->searchById($id);
		if ($entry === null) {
			$this->fail(404, 'No such article');
		}
		$article = $ext->article($entry);
		$parts = $ext->parts($entry, $article);
		if ($ext->rejection($article) !== null || !isset($parts[$part])) {
			$this->fail(404, 'Nothing to read');
		}
		$text = $parts[$part];
		$model = (string)$ext->setting('model');
		$instructions = (string)$ext->setting('instructions');
		$key = implode("\n", [$model, $voice, $instructions, $text]);

		$cache = $ext->cache();
		$cached = $cache->get($key);
		if ($cached !== null) {
			$this->sendFile($cached);
		}
		if ($ext->apiKey() === '') {
			$this->fail(503, 'No OpenAI API key');
		}

		@set_time_limit(240);
		ignore_user_abort(true);   // finish (and cache) a piece we're paying for even if the listener skips
		// The player's own requests (X-Listen-Prepare) never wait for another request generating the same piece:
		// they're told to come back in a second, so a waiting request doesn't tie up a PHP worker.
		// (An <audio> element can't be told that; it only asks once the player has the piece ready, so it rarely waits.)
		$prepare = ($_SERVER['HTTP_X_LISTEN_PREPARE'] ?? '') === '1';
		$lock = $cache->lock($key, wait: !$prepare);
		if ($lock === false) {
			header('Retry-After: 1');
			$this->json(['status' => 'generating'], 202);
		}
		$cached = $cache->get($key);   // generated by another request while we waited for the lock
		if ($cached === null) {
			$error = $this->generate($ext, $cache, $key, $text, $voice, $model, $instructions);
			$cached = $cache->get($key);
			if ($error !== null || $cached === null) {
				$cache->unlock($lock);
				$this->fail($error[0] ?? 502, $error[1] ?? 'Speech request failed');
			}
		}
		$cache->unlock($lock);
		$this->sendFile($cached);
	}

	/**
	 * Generates a whole piece and checks it before anyone hears it: gpt-4o-mini-tts sometimes drops the last
	 * sentences or adds long silences, so audio much shorter or longer than the text calls for is made again
	 * (up to ATTEMPTS times; the closest try is kept).
	 * @return array{0:int,1:string}|null HTTP status and message for the player, or null when the piece is cached
	 */
	private function generate(ListenExtension $ext, Listen_AudioCache $cache, string $key, string $text, string $voice,
			string $model, string $instructions): ?array {
		$speech = new Listen_Speech($ext->apiKey(), $ext->baseUrl(), $model);
		$pace = $ext->pace();
		$expected = $pace->expected($voice);
		$chars = mb_strlen($text);
		$cap = (float)$ext->setting('daily_cap');
		$best = null;
		$bestDistance = INF;
		$error = [502, 'Speech request failed'];

		for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
			if ($cap > 0 && $ext->spentToday() >= $cap) {
				$error = [429, 'Daily cap reached'];
				break;   // a retry would go over the cap: keep what we have
			}
			$temp = $cache->temp();
			$fh = fopen($temp, 'wb');
			$result = $speech->stream($text, $voice, $instructions, static function (string $chunk) use ($fh): void {
				if ($fh !== false) {
					fwrite($fh, $chunk);
				}
			});
			if ($fh !== false) {
				fclose($fh);
			}
			if ($result['status'] !== 200) {
				$cache->discard($temp);
				Minz_Log::warning('Listen: OpenAI speech request failed (' . $result['status'] . '): ' . $result['error']);
				// A bad key or an empty account won't get better by asking again
				$fatal = in_array($result['status'], [401, 403], true) || str_contains($result['error'], 'quota');
				$error = [$fatal ? 503 : 502, $result['error'] !== '' ? $result['error'] : 'Speech request failed'];
				if ($fatal) {
					break;
				}
				continue;
			}
			$cache->addChars($ext->today(), $chars);

			$seconds = Listen_Mp3::duration((string)file_get_contents($temp));
			$rate = $seconds > 0 ? $chars / $seconds : INF;
			// Very short pieces (a title) are mostly the pauses around them: their pace says nothing
			$verdict = $chars < self::MIN_JUDGED_CHARS && $seconds > 0 ? 'ok' : $pace->verdict($rate, $expected);
			$distance = $pace->distance($rate, $expected);
			if ($best === null || $distance < $bestDistance) {
				if ($best !== null) {
					$cache->discard($best);
				}
				$best = $temp;
				$bestDistance = $distance;
			} else {
				$cache->discard($temp);
			}
			if ($verdict === 'ok') {
				if ($chars >= self::MIN_JUDGED_CHARS) {
					$pace->record($voice, $rate);
				}
				break;
			}
			Minz_Log::warning(sprintf('Listen: %s audio for a %d-character piece (%.1f s, %.1f characters/s, usual %s), attempt %d of %d',
				$verdict === 'short' ? 'cut-off' : 'overlong', $chars, $seconds, $rate,
				$expected === null ? 'unknown' : sprintf('%.1f', $expected), $attempt, self::ATTEMPTS));
		}

		if ($best === null) {
			return $error;
		}
		$cache->commit($best, $key);
		return null;
	}

	/** Serves a cached piece, with byte ranges so the player can seek. */
	private function sendFile(string $path): never {
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		$size = (int)filesize($path);
		$start = 0;
		$end = $size - 1;
		header('Content-Type: audio/mpeg');
		header('Accept-Ranges: bytes');
		header('Cache-Control: private, max-age=86400');
		if (preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $m) === 1 && ($m[1] !== '' || $m[2] !== '')) {
			if ($m[1] === '') {
				$start = max(0, $size - (int)$m[2]);   // the last N bytes
			} else {
				$start = (int)$m[1];
				$end = $m[2] !== '' ? min((int)$m[2], $end) : $end;
			}
			if ($start > $end) {
				http_response_code(416);
				header('Content-Range: bytes */' . $size);
				exit();
			}
			http_response_code(206);
			header("Content-Range: bytes {$start}-{$end}/{$size}");
		}
		header('Content-Length: ' . ($end - $start + 1));
		$fh = fopen($path, 'rb');
		if ($fh !== false) {
			fseek($fh, $start);
			echo fread($fh, $end - $start + 1);
			fclose($fh);
		}
		exit();
	}
}
