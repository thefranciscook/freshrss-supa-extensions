<?php

declare(strict_types=1);

/**
 * Serves the bundled Fira Sans files from ../fonts (same origin, so CSP allows them).
 */
final class FreshExtension_MinimalUI_Controller extends FreshRSS_ActionController {

	public function fontAction(): void {
		$name = Minz_Request::paramString('f');
		$path = dirname(__DIR__) . '/fonts/' . $name;
		if (preg_match('/^[a-z0-9-]+\.woff2$/', $name) !== 1 || !is_file($path)) {
			header('HTTP/1.1 404 Not Found');
			exit('Not Found');
		}

		header('Content-Type: font/woff2');
		// One year, private: the URL carries a version param for cache-busting.
		if (!httpConditional((int)filemtime($path), cacheSeconds: 31536000, cachePrivacy: 2)) {
			header('Content-Length: ' . filesize($path));
			readfile($path);
		}
		exit();
	}
}
