<?php

declare(strict_types=1);

/**
 * Plain-PHP checks for Listen_Mp3 and Listen_Pace. Run inside the container:
 *   docker compose exec freshrss php extensions/xExtension-Listen/tests/quality_test.php
 */

require __DIR__ . '/../lib/Mp3.php';
require __DIR__ . '/../lib/Pace.php';

$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void {
	global $failures;
	echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
	if (!$ok) {
		$failures++;
	}
}

// Synthetic MP3s: MPEG-2 layer III, 24 kHz, 64 kbps, mono (like OpenAI's) = 192-byte frames of 24 ms
$frame24k = "\xFF\xF3\x84\xC4" . str_repeat("\0", 188);
// MPEG-1 layer III, 44.1 kHz, 128 kbps: 417 or 418 bytes (padding) of 26.1 ms
$frame44k = "\xFF\xFB\x90\x44" . str_repeat("\0", 413);
$id3 = 'ID3' . "\x04\x00\x00" . "\x00\x00\x01\x00" . str_repeat("\xFF", 128);   // 128-byte tag full of fake sync bytes

$d = Listen_Mp3::duration(str_repeat($frame24k, 125));
check('MPEG-2 24 kHz frames: 125 x 24 ms = 3 s', abs($d - 3.0) < 0.001, (string)$d);
$d = Listen_Mp3::duration($id3 . str_repeat($frame24k, 125));
check('an ID3 tag is skipped, even with sync-like bytes in it', abs($d - 3.0) < 0.001, (string)$d);
$d = Listen_Mp3::duration(str_repeat($frame44k, 100));
check('MPEG-1 44.1 kHz frames', abs($d - 100 * 1152 / 44100) < 0.001, (string)$d);
$d = Listen_Mp3::duration(str_repeat($frame24k, 50) . substr($frame24k, 0, 100));
check('a truncated last frame still counts what is there', abs($d - 51 * 0.024) < 0.001, (string)$d);
check('garbage is 0 s', Listen_Mp3::duration(random_bytes(64) . 'not an mp3') < 0.1);
check('empty is 0 s', Listen_Mp3::duration('') === 0.0);

// Pace
$file = sys_get_temp_dir() . '/listen-pace-' . bin2hex(random_bytes(4)) . '/pace.json';
$pace = new Listen_Pace($file);
check('nothing known yet', $pace->expected('alloy') === null);
check('cold: normal speech is fine', $pace->verdict(15.0, null) === 'ok' && $pace->verdict(22.0, null) === 'ok');
check('cold: impossible speed means text is missing', $pace->verdict(45.0, null) === 'short');
check('cold: crawling means silence', $pace->verdict(3.0, null) === 'long');

foreach ([14.0, 15.0, 16.0] as $p) {
	$pace->record('alloy', $p);
}
check('learns the median pace', $pace->expected('alloy') === 15.0, (string)$pace->expected('alloy'));
check('a new voice borrows the others\' pace', $pace->expected('nova') === 15.0);
$expected = $pace->expected('alloy');
check('the last sentence missing (25% short) is caught', $pace->verdict(15.0 / 0.75, $expected) === 'short');
check('ordinary variation (10%) is fine', $pace->verdict(16.5, $expected) === 'ok' && $pace->verdict(13.5, $expected) === 'ok');
check('long silences or repeats are caught', $pace->verdict(7.0, $expected) === 'long');
check('distance prefers the closest try', $pace->distance(15.5, $expected) < $pace->distance(19.0, $expected)
	&& $pace->distance(0.0, $expected) === INF);

for ($i = 0; $i < 40; $i++) {
	$pace->record('onyx', 12.0);
}
$data = json_decode((string)file_get_contents($file), true);
check('keeps a bounded history', count($data['onyx']) === 25 && $pace->expected('onyx') === 12.0);
check('each voice has its own pace', $pace->expected('alloy') === 15.0);
unlink($file);
rmdir(dirname($file));

echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
