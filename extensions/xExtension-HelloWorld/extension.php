<?php

declare(strict_types=1);

/**
 * Minimal FreshRSS extension used to verify the local dev setup.
 *
 * Folder must be named xExtension-<Entrypoint>, class must be <Entrypoint>Extension.
 */
final class HelloWorldExtension extends Minz_Extension {

	#[\Override]
	public function init(): void {
		parent::init();

		// i18n/<lang>/ext.php -> _t('ext.helloworld.greeting')
		$this->registerTranslates();

		// Prepend a banner to every article body before it is rendered.
		$this->registerHook(Minz_HookType::EntryBeforeDisplay, [$this, 'addBanner']);

		// Ship a stylesheet from ./static
		Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
	}

	public function addBanner(FreshRSS_Entry $entry): FreshRSS_Entry {
		$greeting = $this->getUserConfigurationValue('greeting', 'Hello from your extension!');
		$banner = '<div class="helloworld-banner">' . htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') . '</div>';
		$entry->_content($banner . $entry->content());
		return $entry;
	}

	/** Called when the user submits configure.phtml */
	#[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			$this->setUserConfigurationValue('greeting', Minz_Request::paramString('greeting'));
		}
	}
}
