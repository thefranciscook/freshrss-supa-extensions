<?php

declare(strict_types=1);

/**
 * Generated audio on disk, so replaying or going back costs nothing, plus today's spending so a daily cap
 * can stop runaway costs. Pure PHP (no FreshRSS dependency) so it can be tested on its own.
 */
final class Listen_AudioCache {

	public function __construct(private readonly string $dir) {}

	/** Cached MP3 for this exact text, voice, model and style, or null. */
	public function get(string $key): ?string {
		$path = $this->path($key);
		if (!is_file($path)) {
			return null;
		}
		touch($path);   // "last played", for prune() (access times are often not kept)
		return $path;
	}

	/** A file to stream into; becomes the cached copy with commit(), or is thrown away with discard(). */
	public function temp(): string {
		$this->ensureDir();
		return $this->dir . '/tmp-' . bin2hex(random_bytes(8)) . '.part';
	}

	public function commit(string $temp, string $key): void {
		if (is_file($temp) && filesize($temp) > 0) {
			rename($temp, $this->path($key));
		} else {
			$this->discard($temp);
		}
	}

	public function discard(string $temp): void {
		if (is_file($temp)) {
			unlink($temp);
		}
	}

	/** Deletes audio not played for $days days, and leftovers of interrupted downloads. */
	public function prune(int $days, int $now): int {
		$deleted = 0;
		foreach (['/*.mp3' => $days * 86400, '/tmp-*.part' => 3600] as $pattern => $maxAge) {
			foreach (glob($this->dir . $pattern) ?: [] as $file) {
				if ($now - (int)filemtime($file) > $maxAge && unlink($file)) {
					$deleted++;
				}
			}
		}
		return $deleted;
	}

	/** Characters sent to the speech API today ($today as Y-m-d). */
	public function spentChars(string $today): int {
		$data = json_decode((string)@file_get_contents($this->budgetFile()), true);
		return is_array($data) && ($data['day'] ?? null) === $today && is_int($data['chars'] ?? null) ? $data['chars'] : 0;
	}

	public function addChars(string $today, int $chars): void {
		$this->ensureDir();
		$fh = fopen($this->budgetFile(), 'c+');
		if ($fh === false) {
			return;
		}
		flock($fh, LOCK_EX);   // two pieces may finish at the same time
		$data = json_decode((string)stream_get_contents($fh), true);
		$spent = is_array($data) && ($data['day'] ?? null) === $today && is_int($data['chars'] ?? null) ? $data['chars'] : 0;
		ftruncate($fh, 0);
		rewind($fh);
		fwrite($fh, json_encode(['day' => $today, 'chars' => $spent + $chars]) ?: '');
		flock($fh, LOCK_UN);
		fclose($fh);
	}

	private function path(string $key): string {
		$this->ensureDir();
		return $this->dir . '/' . hash('sha256', $key) . '.mp3';
	}

	private function budgetFile(): string {
		return $this->dir . '/spent.json';
	}

	private function ensureDir(): void {
		if (!is_dir($this->dir)) {
			mkdir($this->dir, 0770, true);
		}
	}
}
