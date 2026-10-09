<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Article.php';
require_once __DIR__ . '/lib/AudioCache.php';
require_once __DIR__ . '/lib/Pace.php';

/**
 * Listen: press play and FreshRSS reads the current view aloud, like a podcast queue.
 *
 * static/listen.js walks the articles in feed order and plays the ones worth listening to (full text,
 * no "Read more", at least a few real paragraphs; see lib/Article.php), each in a different voice.
 * The audio comes from OpenAI's speech API through ?c=Listen&a=audio, piece by piece (each about 45 s at most,
 * generated and checked for cut-offs before it's played), so only what you listen to (plus the next piece) is paid for.
 * The API key stays on the server: the OPENAI_API_KEY environment variable, or the settings as a fallback.
 */
final class ListenExtension extends Minz_Extension {

	/** OpenAI's built-in voices */
	public const VOICES = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer', 'verse'];
	public const DEFAULTS = [
		'model' => 'gpt-4o-mini-tts',
		'instructions' => 'Read this blog post aloud like a calm, warm podcast host: natural pace, clear pronunciation, no exaggerated emotion.',
		'min_paragraphs' => 3,
		'min_words' => 250,
		'max_words' => 3000,   // longer articles stop there ("the rest is on the website")
		'daily_cap' => 2.0,    // US dollars per day, estimated
		'keep_days' => 7,      // generated audio is deleted after this many days without being played
		'mark_read' => true,   // mark an article read when its episode finishes
	];
	/** Rough price of gpt-4o-mini-tts (about $0.015 per minute of audio), in dollars per million characters */
	public const PRICE_PER_M_CHARS = 15.0;

	#[\Override]
	public function init(): void {
		parent::init();
		$this->registerTranslates();

		// ?c=Listen&a=plan|audio|status|silence
		$this->registerController('Listen');

		$this->registerHook(Minz_HookType::JsVars, [$this, 'jsVars']);
		$this->registerHook(Minz_HookType::NavMenu, [$this, 'navMenu']);
		$this->registerHook(Minz_HookType::FreshrssUserMaintenance, [$this, 'maintenance']);

		Minz_View::appendStyle($this->getFileUrl('listen.css', 'css'));
		Minz_View::appendScript($this->getFileUrl('listen.js', 'js'));
	}

	public function setting(string $key): string|int|float|bool {
		$v = $this->getUserConfigurationValue($key, self::DEFAULTS[$key]);
		return gettype($v) === gettype(self::DEFAULTS[$key]) ? $v : self::DEFAULTS[$key];
	}

	public function apiKey(): string {
		$env = getenv('OPENAI_API_KEY');
		if (is_string($env) && trim($env) !== '') {
			return trim($env);
		}
		$key = $this->getUserConfigurationValue('api_key', '');
		return is_string($key) ? $key : '';
	}

	public function apiKeyFromEnv(): bool {
		$env = getenv('OPENAI_API_KEY');
		return is_string($env) && trim($env) !== '';
	}

	/** Another OpenAI-compatible server (e.g. a self-hosted one) can stand in via LISTEN_OPENAI_BASE_URL. */
	public function baseUrl(): string {
		$env = getenv('LISTEN_OPENAI_BASE_URL');
		return is_string($env) && $env !== '' ? $env : 'https://api.openai.com/v1';
	}

	public function cache(): Listen_AudioCache {
		return new Listen_AudioCache(CACHE_PATH . '/listen/' . (Minz_User::name() ?? '_'));
	}

	public function pace(): Listen_Pace {
		return new Listen_Pace($this->cache()->paceFile());
	}

	public function today(): string {
		return date('Y-m-d');
	}

	/** Today's spending so far, in dollars (estimated from the characters sent). */
	public function spentToday(): float {
		return $this->cache()->spentChars($this->today()) * self::PRICE_PER_M_CHARS / 1e6;
	}

	public function article(FreshRSS_Entry $entry): Listen_Article {
		return Listen_Article::fromHtml($entry->content(false));
	}

	/** @return null|'read_more'|'too_short'|'pictures' */
	public function rejection(Listen_Article $article): ?string {
		return $article->rejection((int)$this->setting('min_paragraphs'), (int)$this->setting('min_words'));
	}

	/** @return list<string> */
	public function parts(FreshRSS_Entry $entry, Listen_Article $article): array {
		$feed = $entry->feed();
		return $article->parts(
			html_entity_decode(strip_tags($entry->title()), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			$feed === null ? '' : html_entity_decode($feed->name(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			(int)$this->setting('max_words'),
			_t('ext.listen.continues'),
		);
	}

	/**
	 * @param array<string,mixed> $vars
	 * @return array<string,mixed>
	 */
	public function jsVars(array $vars): array {
		$vars['listen'] = [
			'ready' => $this->apiKey() !== '',
			'voices' => self::VOICES,
			'mark_read' => (bool)$this->setting('mark_read'),
			'urls' => [
				'plan' => Minz_Url::display(['c' => 'Listen', 'a' => 'plan'], 'php'),
				'audio' => Minz_Url::display(['c' => 'Listen', 'a' => 'audio'], 'php'),
				'status' => Minz_Url::display(['c' => 'Listen', 'a' => 'status'], 'php'),
				'silence' => Minz_Url::display(['c' => 'Listen', 'a' => 'silence'], 'php'),
				'configure' => Minz_Url::display(['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()]], 'php'),
			],
			'i18n' => [
				'listen' => _t('ext.listen.listen'),
				'play' => _t('ext.listen.play'),
				'pause' => _t('ext.listen.pause'),
				'next' => _t('ext.listen.next'),
				'previous' => _t('ext.listen.previous'),
				'close' => _t('ext.listen.close'),
				'speed' => _t('ext.listen.speed'),
				'ai_voice' => _t('ext.listen.ai_voice'),
				'play_this' => _t('ext.listen.play_this'),
				'nothing' => _t('ext.listen.nothing'),
				'finished' => _t('ext.listen.finished'),
				'no_key' => _t('ext.listen.no_key'),
				'cap_reached' => _t('ext.listen.cap_reached'),
				'failed' => _t('ext.listen.failed'),
			],
		];
		return $vars;
	}

	/** The play button, next to the sort menu (Minimal UI keeps it in its top bar). */
	public function navMenu(): string {
		if (!FreshRSS_Auth::hasAccess() || !in_array(Minz_Request::actionName(), ['index', 'normal', 'reader'], true)) {
			return '';
		}
		$title = _t('ext.listen.listen');
		return '<button type="button" class="btn listen-play" title="' . $title . '" aria-label="' . $title . '">'
			. '<svg class="icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">'
			. '<path d="M2.5 9.5V8a5.5 5.5 0 0 1 11 0v1.5" fill="none" stroke="currentColor" stroke-width="1.5"/>'
			. '<rect x="1.5" y="9" width="3" height="5" rx="1" fill="currentColor"/>'
			. '<rect x="11.5" y="9" width="3" height="5" rx="1" fill="currentColor"/>'
			. '</svg></button>';
	}

	/** Runs on every feed refresh: drops audio nobody played for a while. */
	public function maintenance(): void {
		$this->cache()->prune((int)$this->setting('keep_days'), time());
	}

	#[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			$this->setUserConfigurationValue('instructions', trim(Minz_Request::paramString('instructions', plaintext: true)));
			$this->setUserConfigurationValue('min_paragraphs', max(1, Minz_Request::paramInt('min_paragraphs')));
			$this->setUserConfigurationValue('min_words', max(0, Minz_Request::paramInt('min_words')));
			$this->setUserConfigurationValue('max_words', max(100, Minz_Request::paramInt('max_words')));
			$this->setUserConfigurationValue('daily_cap', max(0.0, (float)Minz_Request::paramString('daily_cap', plaintext: true)));
			$this->setUserConfigurationValue('keep_days', max(1, Minz_Request::paramInt('keep_days')));
			$this->setUserConfigurationValue('mark_read', Minz_Request::paramBoolean('mark_read'));
			$key = trim(Minz_Request::paramString('api_key', plaintext: true));
			if ($key !== '') {
				$this->setUserConfigurationValue('api_key', $key);
			} elseif (Minz_Request::paramBoolean('forget_key')) {
				$this->setUserConfigurationValue('api_key', null);
			}
		}
	}
}
