<?php

declare(strict_types=1);

/**
 * Minimal UI: a stripped-down reading layout on top of the active theme.
 *
 * Most of the work is CSS (static/minimal.css). static/minimal.js only does
 * what CSS cannot: relocating the header/toolbar into the sidebar, folding the
 * state filters into one dropdown and rendering "x ago" dates.
 *
 * The layout is built around the reader view, so it becomes the default view once
 * (see useReaderView()); the user can still pick another one in Settings → Reading.
 */
final class MinimalUIExtension extends Minz_Extension {

	#[\Override]
	public function init(): void {
		parent::init();
		$this->useReaderView();

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
	 * Makes the reader view the default view (FreshRSS's Settings → Reading → Default view), once:
	 * choosing another view there afterwards sticks. The previous choice is kept for uninstall().
	 */
	private function useReaderView(): void {
		if (!FreshRSS_Auth::hasAccess() || $this->getUserConfigurationValue('reader_default') !== null) {
			return;
		}
		$conf = FreshRSS_Context::userConf();
		$previous = $conf->view_mode;
		$conf->view_mode = 'reader';
		$this->setUserConfigurationValue('reader_default', $previous);   // also saves the view mode
	}

	/** Disabling the extension gives back the view it replaced, unless the user has changed it since. */
	#[\Override]
	public function uninstall(): string|bool {
		$previous = $this->getUserConfigurationValue('reader_default');
		if (FreshRSS_Auth::hasAccess() && is_string($previous)) {
			$conf = FreshRSS_Context::userConf();
			if ($conf->view_mode === 'reader') {
				$conf->view_mode = $previous;
			}
			$this->setUserConfigurationValue('reader_default', null);   // re-enabling applies it again
		}
		return true;
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
