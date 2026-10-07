<?php

declare(strict_types=1);

/**
 * An article as something to read aloud: its text split into blocks (paragraphs, headings, list items),
 * whether it is worth listening to, and the pieces sent to the speech API.
 * Pure PHP + DOM (no FreshRSS dependency) so it can be tested on its own.
 */
final class Listen_Article {

	/** Link texts of truncated feeds ("Read more", "Continue reading →", "[…]"), kept strict on purpose:
	 * a full post can well contain "View on GitHub" */
	private const READ_MORE = '/\b(read|continue|keep) (more|reading)\b|\bread the (full|whole|rest)\b|\bfull (story|article|post)\b|^\W*more\W*$|tovább|bővebben|weiterlesen|lire la suite|leer más|seguir leyendo|continua a leggere/iu';
	private const SKIP = ['script', 'style', 'noscript', 'template', 'figure', 'figcaption', 'pre', 'code', 'table', 'iframe',
		'video', 'audio', 'img', 'picture', 'svg', 'math', 'form', 'button', 'nav', 'aside', 'footer', 'sup', 'object', 'embed'];
	private const BLOCKS = ['p', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'dd', 'dt'];
	private const INLINE = ['a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'data', 'del', 'dfn', 'em', 'font', 'i', 'ins', 'kbd', 'mark',
		'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'time', 'u', 'var', 'wbr'];
	/** A paragraph needs this many words to count as a real one (not a caption, byline or "Share this") */
	public const PARAGRAPH_WORDS = 25;
	/** At most one image or embed per this many words, or the post is about the pictures */
	private const WORDS_PER_MEDIA = 150;

	/** @var list<array{text:string,heading:bool}> */
	private array $blocks = [];
	private int $media = 0;
	private bool $readMore = false;

	public static function fromHtml(string $html): self {
		$article = new self();
		if (trim($html) === '') {
			return $article;
		}
		$doc = new DOMDocument();
		$doc->loadHTML('<?xml encoding="UTF-8"><div id="listen-root">' . $html . '</div>',
			LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT);
		$root = $doc->getElementById('listen-root');
		if ($root === null) {
			return $article;
		}
		foreach (['img', 'iframe', 'video', 'audio'] as $tag) {
			$article->media += $root->getElementsByTagName($tag)->length;
		}
		foreach ($root->getElementsByTagName('a') as $a) {
			if (self::words($a->textContent) <= 8 && preg_match(self::READ_MORE, trim($a->textContent)) === 1) {
				$article->readMore = true;
			}
		}
		foreach (self::SKIP as $tag) {
			$nodes = iterator_to_array($root->getElementsByTagName($tag));
			foreach ($nodes as $node) {
				$node->parentNode?->removeChild($node);
			}
		}
		$article->walk($root);
		$article->blocks = array_values(array_filter($article->blocks, fn($b) => !self::isJunk($b['text'])));

		// WordPress excerpts end in "[…]"
		$last = end($article->blocks);
		if ($last !== false && preg_match('/(\[(…|\.\.\.)\]|\[&hellip;\])\s*$/u', $last['text']) === 1) {
			$article->readMore = true;
		}
		return $article;
	}

	/** Collects block texts in document order; loose text (e.g. paragraphs separated by <br><br>) counts too. */
	private function walk(DOMNode $node): void {
		$loose = '';
		foreach ($node->childNodes as $child) {
			if ($child instanceof DOMText) {
				$loose .= $child->textContent;
			} elseif ($child instanceof DOMElement) {
				$tag = strtolower($child->tagName);
				if ($tag === 'br') {
					$loose .= "\n";
				} elseif (in_array($tag, self::INLINE, true)) {
					$loose .= $child->textContent;
				} else {
					$this->flush($loose);
					$loose = '';
					if (in_array($tag, self::BLOCKS, true)) {
						$this->add($child->textContent, in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true));
					} else {
						$this->walk($child);
					}
				}
			}
		}
		$this->flush($loose);
	}

	private function flush(string $loose): void {
		foreach (preg_split('/\n\s*\n/u', $loose) ?: [] as $text) {
			$this->add($text, false);
		}
	}

	private function add(string $text, bool $heading): void {
		$text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
		if ($text !== '') {
			$this->blocks[] = ['text' => $text, 'heading' => $heading];
		}
	}

	private static function isJunk(string $text): bool {
		return preg_match('/^The post .+ appeared first on .+$/iu', $text) === 1   // WordPress feed footer
			|| preg_match('~^\S+://\S+$~u', $text) === 1                              // a bare URL
			|| (self::words($text) <= 6 && preg_match(self::READ_MORE, $text) === 1);
	}

	public static function words(string $text): int {
		return preg_match_all('/[\p{L}\p{N}]+/u', $text);
	}

	public function wordCount(): int {
		return array_sum(array_map(fn($b) => self::words($b['text']), $this->blocks));
	}

	public function paragraphCount(): int {
		return count(array_filter($this->blocks, fn($b) => !$b['heading'] && self::words($b['text']) >= self::PARAGRAPH_WORDS));
	}

	/** @return null|'read_more'|'too_short'|'pictures' null when the article is worth listening to, otherwise why not */
	public function rejection(int $minParagraphs, int $minWords): ?string {
		if ($this->readMore) {
			return 'read_more';
		}
		$words = $this->wordCount();
		if ($this->paragraphCount() < $minParagraphs || $words < $minWords) {
			return 'too_short';
		}
		if ($this->media * self::WORDS_PER_MEDIA > $words) {
			return 'pictures';
		}
		return null;
	}

	/**
	 * The text in pieces for the speech API: a short first one so playback starts fast, then pieces of
	 * up to $chars characters, cut between paragraphs (or sentences, for very long paragraphs).
	 * @return list<string>
	 */
	public function parts(string $title, string $feed, int $maxWords, string $continues, int $firstChars = 600, int $chars = 1500): array {
		$paragraphs = [self::sentence(trim($title)) . ($feed !== '' ? ' ' . self::sentence($feed) : '')];
		$budget = $maxWords;
		foreach ($this->blocks as $block) {
			$text = $block['heading'] ? self::sentence($block['text']) : $block['text'];
			$words = self::words($text);
			if ($words > $budget) {
				$paragraphs[] = $continues;
				break;
			}
			$paragraphs[] = $text;
			$budget -= $words;
		}

		$parts = [];
		$current = '';
		$limit = $firstChars;
		foreach ($paragraphs as $paragraph) {
			foreach (self::pieces($paragraph, $chars) as $piece) {
				if ($current !== '' && mb_strlen($current) + 2 + mb_strlen($piece) > $limit) {
					$parts[] = $current;
					$current = '';
					$limit = $chars;
				}
				$current = $current === '' ? $piece : $current . "\n\n" . $piece;
			}
		}
		if ($current !== '') {
			$parts[] = $current;
		}
		return $parts;
	}

	/** Headings and titles get a full stop so the voice pauses after them. */
	private static function sentence(string $text): string {
		return preg_match('/[.!?…:;]$/u', $text) === 1 ? $text : $text . '.';
	}

	/**
	 * A paragraph cut into pieces of at most $max characters, between sentences where possible.
	 * @return list<string>
	 */
	private static function pieces(string $paragraph, int $max): array {
		if (mb_strlen($paragraph) <= $max) {
			return [$paragraph];
		}
		$pieces = [];
		$current = '';
		foreach (preg_split('/(?<=[.!?…])\s+/u', $paragraph) ?: [] as $sentence) {
			while (mb_strlen($sentence) > $max) {   // one endless sentence: cut at a space
				if ($current !== '') {
					$pieces[] = $current;
					$current = '';
				}
				$cut = mb_strrpos(mb_substr($sentence, 0, $max), ' ') ?: $max;
				$pieces[] = trim(mb_substr($sentence, 0, $cut));
				$sentence = trim(mb_substr($sentence, $cut));
			}
			if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($sentence) > $max) {
				$pieces[] = $current;
				$current = '';
			}
			$current = $current === '' ? $sentence : $current . ' ' . $sentence;
		}
		if ($current !== '') {
			$pieces[] = $current;
		}
		return $pieces;
	}
}
