<?php

declare(strict_types=1);

/**
 * Minimal UI: a stripped-down reading layout on top of the active theme.
 *
 * Most of the work is CSS (static/minimal.css). static/minimal.js only does
 * what CSS cannot: relocating the header/toolbar into the sidebar, folding the
 * state filters into one dropdown and rendering "x ago" dates.
 */
final class MinimalUIExtension extends Minz_Extension {

	#[\Override]
	public function init(): void {
		parent::init();

		// FreshRSS sends `Content-Security-Policy: default-src 'self'`, which rules out
		// Google Fonts and data: URIs, and ext.php refuses to serve .woff2. So the fonts
		// are streamed by our own controller: ?c=MinimalUI&a=font&f=<file>.woff2
		$this->registerController('MinimalUI');

		$this->registerHook(Minz_HookType::JsVars, [$this, 'jsVars']);

		Minz_View::appendStyle($this->getFileUrl('fonts.css', 'css'));
		Minz_View::appendStyle($this->getFileUrl('minimal.css', 'css'));
		Minz_View::appendScript($this->getFileUrl('minimal.js', 'js'));
	}

	/**
	 * @param array<string,mixed> $vars
	 * @return array<string,mixed>
	 */
	public function jsVars(array $vars): array {
		$vars['minimal_ui'] = [
			'filter' => _t('gen.action.filter'),
		];
		return $vars;
	}
}
