<?php

declare(strict_types=1);

/**
 * Length of an MP3, by walking its frames (exact for constant and variable bitrates alike).
 * Pure PHP so it can be tested on its own.
 */
final class Listen_Mp3 {

	/** kbps by [MPEG-1?][layer 1..3][bitrate index 1..14] */
	private const BITRATES = [
		true => [
			1 => [32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448],
			2 => [32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384],
			3 => [32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
		],
		false => [
			1 => [32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256],
			2 => [8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
			3 => [8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
		],
	];
	/** Hz by [version bits][sample rate index]: 0 = MPEG-2.5, 2 = MPEG-2, 3 = MPEG-1 */
	private const SAMPLE_RATES = [0 => [11025, 12000, 8000], 2 => [22050, 24000, 16000], 3 => [44100, 48000, 32000]];

	public static function duration(string $data): float {
		$length = strlen($data);
		$pos = 0;
		if ($length >= 10 && str_starts_with($data, 'ID3')) {   // ID3v2 tag: its size is 4 x 7 bits
			$pos = 10 + ((ord($data[6]) & 0x7f) << 21 | (ord($data[7]) & 0x7f) << 14 | (ord($data[8]) & 0x7f) << 7 | (ord($data[9]) & 0x7f));
		}
		$seconds = 0.0;
		while ($pos + 4 <= $length) {
			if (ord($data[$pos]) !== 0xff || (ord($data[$pos + 1]) & 0xe0) !== 0xe0) {
				$pos++;
				continue;
			}
			$header = (ord($data[$pos + 1]) << 16) | (ord($data[$pos + 2]) << 8) | ord($data[$pos + 3]);
			$version = ($header >> 19) & 3;
			$layer = 4 - (($header >> 17) & 3);   // bits 01 = layer III ... 11 = layer I
			$bitrateIndex = ($header >> 12) & 15;
			$rateIndex = ($header >> 10) & 3;
			if ($version === 1 || $layer === 4 || $bitrateIndex === 0 || $bitrateIndex === 15 || $rateIndex === 3) {
				$pos++;   // not a frame header after all
				continue;
			}
			$mpeg1 = $version === 3;
			$bitrate = self::BITRATES[$mpeg1][$layer][$bitrateIndex - 1] * 1000;
			$sampleRate = self::SAMPLE_RATES[$version][$rateIndex];
			$padding = ($header >> 9) & 1;
			$samples = $layer === 1 ? 384 : ($layer === 3 && !$mpeg1 ? 576 : 1152);
			$frameLength = $layer === 1
				? (intdiv(12 * $bitrate, $sampleRate) + $padding) * 4
				: intdiv($samples / 8 * $bitrate, $sampleRate) + $padding;
			if ($frameLength <= 0) {
				break;
			}
			$seconds += $samples / $sampleRate;
			$pos += $frameLength;
		}
		return $seconds;
	}
}
