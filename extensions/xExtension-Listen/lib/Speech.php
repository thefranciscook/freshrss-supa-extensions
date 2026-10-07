<?php

declare(strict_types=1);

/**
 * OpenAI's speech endpoint (POST /audio/speech), streamed: audio chunks are handed over as they arrive,
 * so playback can start before the whole piece is generated. Any OpenAI-compatible server works too
 * (the base URL is configurable).
 */
final class Listen_Speech {

	public function __construct(
		private readonly string $apiKey,
		private readonly string $baseUrl,
		private readonly string $model,
	) {}

	/**
	 * @param callable(string):void $onAudio called with each chunk of MP3 data, only for a successful response
	 * @return array{status:int,error:string} HTTP status (0 when the request failed) and OpenAI's error message
	 */
	public function stream(string $text, string $voice, string $instructions, callable $onAudio): array {
		$body = ['model' => $this->model, 'input' => $text, 'voice' => $voice, 'response_format' => 'mp3'];
		if ($instructions !== '' && str_starts_with($this->model, 'gpt-')) {
			$body['instructions'] = $instructions;   // only the gpt-*-tts models take a speaking style
		}
		$status = 0;
		$error = '';
		$ch = curl_init(rtrim($this->baseUrl, '/') . '/audio/speech');
		if ($ch === false) {
			return ['status' => 0, 'error' => 'curl_init failed'];
		}
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE) ?: '{}',
			CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json'],
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 180,
			CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$status): int {
				if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m) === 1) {
					$status = (int)$m[1];
				}
				return strlen($header);
			},
			CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$status, &$error, $onAudio): int {
				if ($status === 200) {
					$onAudio($data);
				} elseif (strlen($error) < 4096) {
					$error .= $data;
				}
				return strlen($data);
			},
		]);
		if (curl_exec($ch) === false) {
			$error = curl_error($ch);
			$status = $status === 200 ? 0 : $status;   // cut off mid-stream
		}
		curl_close($ch);
		if ($status !== 200) {
			$json = json_decode($error, true);
			if (is_array($json) && is_array($json['error'] ?? null) && is_string($json['error']['message'] ?? null)) {
				$error = $json['error']['message'];
			}
		}
		return ['status' => $status, 'error' => trim($error)];
	}
}
