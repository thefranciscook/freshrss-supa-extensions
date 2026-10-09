<?php

declare(strict_types=1);

/**
 * How fast each voice usually speaks (characters per second of audio), to catch bad generations:
 * gpt-4o-mini-tts sometimes drops the last sentences (audio too short for its text) or adds long
 * silences or repeats (too long). Learns from the pieces that came out fine; until it knows a voice,
 * only absurd paces count as bad. Pure PHP (no FreshRSS dependency) so it can be tested on its own.
 */
final class Listen_Pace {

	/** Typical speech at 1x is ~15 characters per second */
	public const TYPICAL = 15.0;
	/** Before anything is learnt: faster than this means text is missing, slower means silence */
	private const COLD_MAX = 30.0;
	private const COLD_MIN = 5.0;
	/** Once a pace is known: this much faster = cut off, this much slower = silence or repeats */
	private const SHORT_FACTOR = 1.25;
	private const LONG_FACTOR = 1.8;
	private const KEEP = 25;
	private const MIN_SAMPLES = 3;

	public function __construct(private readonly string $file) {}

	/** Usual pace of this voice, or of all voices while it's new; null while nothing is known. */
	public function expected(string $voice): ?float {
		$data = $this->read();
		if (count($data[$voice] ?? []) >= self::MIN_SAMPLES) {
			return self::median($data[$voice]);
		}
		$all = array_merge(...array_values($data ?: [[]]));
		return count($all) >= self::MIN_SAMPLES ? self::median($all) : null;
	}

	/** @return 'ok'|'short'|'long' */
	public function verdict(float $pace, ?float $expected): string {
		if ($expected === null) {
			return $pace > self::COLD_MAX ? 'short' : ($pace < self::COLD_MIN ? 'long' : 'ok');
		}
		return $pace > $expected * self::SHORT_FACTOR ? 'short' : ($pace < $expected / self::LONG_FACTOR ? 'long' : 'ok');
	}

	/** How far off a pace is (0 = spot on), to keep the best of several tries. */
	public function distance(float $pace, ?float $expected): float {
		return $pace > 0 && is_finite($pace) ? abs(log($pace / ($expected ?? self::TYPICAL))) : INF;
	}

	public function record(string $voice, float $pace): void {
		$dir = dirname($this->file);
		if (!is_dir($dir)) {
			mkdir($dir, 0770, true);
		}
		$fh = fopen($this->file, 'c+');
		if ($fh === false) {
			return;
		}
		flock($fh, LOCK_EX);
		$data = self::parse((string)stream_get_contents($fh));
		$data[$voice] = array_slice([...($data[$voice] ?? []), round($pace, 2)], -self::KEEP);
		ftruncate($fh, 0);
		rewind($fh);
		fwrite($fh, json_encode($data) ?: '{}');
		flock($fh, LOCK_UN);
		fclose($fh);
	}

	/** @return array<string,list<float>> */
	private function read(): array {
		return self::parse((string)@file_get_contents($this->file));
	}

	/** @return array<string,list<float>> */
	private static function parse(string $json): array {
		$data = json_decode($json, true);
		$out = [];
		foreach (is_array($data) ? $data : [] as $voice => $paces) {
			if (is_string($voice) && is_array($paces)) {
				$out[$voice] = array_values(array_map('floatval', array_filter($paces, 'is_numeric')));
			}
		}
		return $out;
	}

	/** @param list<float> $values */
	private static function median(array $values): float {
		sort($values);
		$n = count($values);
		return $n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
	}
}
