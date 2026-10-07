<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Speech.php';

/**
 * Endpoints for static/listen.js.
 *   plan   (POST)  ids[]=… → which of these articles get read aloud, and in how many pieces
 *   audio  (GET)   id, part, voice, _csrf → one piece as MP3: from the cache, or streamed from OpenAI
 *   status (GET)   → whether an API key is set and how much of today's cap is left
 */
final class FreshExtension_Listen_Controller extends FreshRSS_ActionController {

	private const MAX_PLAN = 100;

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
			$feed = $entry->feed();
			$items[] = [
				'id' => $entry->id(),
				'ok' => $reason === null,
				'reason' => $reason,
				'parts' => $reason === null ? count($ext->parts($entry, $article)) : 0,
				'words' => $article->wordCount(),
				'title' => html_entity_decode(strip_tags($entry->title()), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
				'feed' => $feed === null ? '' : html_entity_decode($feed->name(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			];
		}
		$this->json(['items' => $items, 'ready' => $ext->apiKey() !== '']);
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
		Minz_Session::unlock();   // don't hold up the rest of FreshRSS while a piece streams

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

		$apiKey = $ext->apiKey();
		if ($apiKey === '') {
			$this->fail(503, 'No OpenAI API key');
		}
		$cap = (float)$ext->setting('daily_cap');
		if ($cap > 0 && $ext->spentToday() >= $cap) {
			$this->fail(429, 'Daily cap reached');
		}

		ignore_user_abort(true);   // finish (and cache) a piece we're paying for even if the listener skips
		@ini_set('zlib.output_compression', '0');
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		$temp = $cache->temp();
		$fh = fopen($temp, 'wb');
		$started = false;
		$result = (new Listen_Speech($apiKey, $ext->baseUrl(), $model))->stream($text, $voice, $instructions,
			static function (string $chunk) use (&$started, $fh): void {
				if (!$started) {
					$started = true;
					http_response_code(200);
					header('Content-Type: audio/mpeg');
					header('Cache-Control: no-store');
					header('X-Accel-Buffering: no');   // nginx: pass the stream through as it comes
				}
				if ($fh !== false) {
					fwrite($fh, $chunk);
				}
				if (connection_status() === CONNECTION_NORMAL) {
					echo $chunk;
					flush();
				}
			});
		if ($fh !== false) {
			fclose($fh);
		}

		if ($result['status'] === 200) {
			$cache->commit($temp, $key);
			$cache->addChars($ext->today(), mb_strlen($text));
			exit();
		}
		$cache->discard($temp);
		Minz_Log::warning('Listen: OpenAI speech request failed (' . $result['status'] . '): ' . $result['error']);
		if ($started) {
			exit();   // cut off mid-stream; the player moves on
		}
		$this->fail(502, $result['error'] !== '' ? $result['error'] : 'Speech request failed');
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
