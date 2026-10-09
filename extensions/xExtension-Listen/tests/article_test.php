<?php

declare(strict_types=1);

/**
 * Plain-PHP checks for Listen_Article. Run inside the container:
 *   docker compose exec freshrss php extensions/xExtension-Listen/tests/article_test.php
 */

require __DIR__ . '/../lib/Article.php';

$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void {
	global $failures;
	echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
	if (!$ok) {
		$failures++;
	}
}

function para(int $words, string $word = 'word'): string {
	return implode(' ', array_fill(0, $words, $word)) . '.';
}

$full = '<p>' . para(60) . '</p><h2>A heading</h2><p>' . para(80) . '</p><p>' . para(70) . '</p>';

$a = Listen_Article::fromHtml($full);
check('full post qualifies', $a->rejection(3, 150) === null, (string)$a->rejection(3, 150));
check('counts paragraphs, not headings', $a->paragraphCount() === 3);

$a = Listen_Article::fromHtml('<p>' . para(60) . '</p><p>' . para(60) . '</p><p>' . para(60) . ' <a href="x">Read more</a></p>');
check('read more link rejects', $a->rejection(3, 150) === 'read_more');
$a = Listen_Article::fromHtml($full . '<p><a href="x">Continue reading <span>A title</span> →</a></p>');
check('continue reading rejects', $a->rejection(3, 150) === 'read_more');
$a = Listen_Article::fromHtml('<p>' . para(60) . '</p><p>' . para(60) . '</p><p>' . para(60) . ' […]</p>');
check('WordPress […] excerpt rejects', $a->rejection(3, 150) === 'read_more');
$a = Listen_Article::fromHtml($full . '<p>Code is <a href="x">on GitHub</a>, see also <a href="y">View on GitHub</a>.</p>');
check('ordinary links are fine', $a->rejection(3, 150) === null, (string)$a->rejection(3, 150));
$a = Listen_Article::fromHtml($full . '<p>The post <a href="x">My post</a> appeared first on <a href="y">My blog</a>.</p>');
check('WordPress footer is fine and not read aloud', $a->rejection(3, 150) === null && !str_contains(implode(' ', $a->parts('T', '', 5000, '')), 'appeared first'));

$a = Listen_Article::fromHtml('<p>' . para(60) . '</p><p>' . para(60) . '</p>');
check('two paragraphs is too short', $a->rejection(3, 100) === 'too_short');
$a = Listen_Article::fromHtml('<p>' . para(30) . '</p><p>' . para(30) . '</p><p>' . para(30) . '</p>');
check('too few words is too short', $a->rejection(3, 200) === 'too_short');
$a = Listen_Article::fromHtml('<p>Photo by X</p><p>Share this</p><p>' . para(60) . '</p><p>' . para(60) . '</p>');
check('captions and bylines are not paragraphs', $a->rejection(3, 100) === 'too_short');
$a = Listen_Article::fromHtml($full . str_repeat('<img src="a.jpg">', 3));
check('a gallery with a few words is about pictures', $a->rejection(3, 150) === 'pictures');
$a = Listen_Article::fromHtml($full . '<img src="a.jpg">');
check('one image is fine', $a->rejection(3, 150) === null);

$a = Listen_Article::fromHtml(para(60) . '<br><br>' . para(60) . '<br/><br/>' . para(60));
check('<br><br> paragraphs count', $a->paragraphCount() === 3);
$a = Listen_Article::fromHtml('<div><div><p>' . para(60) . '</p></div><div>' . para(60) . '</div></div><ul><li>' . para(60) . '</li></ul>');
check('nested divs and lists count', $a->paragraphCount() === 3);

// What gets read
$a = Listen_Article::fromHtml('<p>First para with <a href="x">a link</a> and <em>emphasis</em>.</p><figure><img src="a"><figcaption>Caption</figcaption></figure>'
	. '<pre><code>$x = 1;</code></pre><h3>Section</h3><p>Second para<sup>1</sup>.</p><script>alert(1)</script>');
$text = implode("\n\n", $a->parts('My title', 'My blog', 5000, ''));
check('reads title, feed, text; skips captions, code, footnotes, scripts',
	$text === "My title. My blog.\n\nFirst para with a link and emphasis.\n\nSection.\n\nSecond para.", json_encode($text) ?: '');

// Pieces
$long = '';
for ($i = 0; $i < 12; $i++) {
	$long .= '<p>' . para(50, 'lorem') . '</p>';
}
$parts = Listen_Article::fromHtml($long)->parts('Title', 'Feed', 5000, '');
$lengths = array_map('mb_strlen', $parts);
check('first piece is short for a fast start', $lengths[0] <= 300, json_encode($lengths) ?: '');
check('other pieces stay under ~45 s of speech', max($lengths) <= 700, json_encode($lengths) ?: '');
check('nothing lost', array_sum(array_map(fn($p) => Listen_Article::words($p), $parts)) === 600 + 2);

$endless = Listen_Article::fromHtml('<p>' . implode(' ', array_fill(0, 800, 'word')) . '</p>');
$lengths = array_map('mb_strlen', $endless->parts('T', '', 5000, ''));
check('a sentence without full stops is cut at spaces', max($lengths) <= 700, json_encode($lengths) ?: '');

$parts = Listen_Article::fromHtml($long)->parts('Title', 'Feed', 200, 'The rest is on the website.');
check('long articles stop at max words', str_ends_with(end($parts) ?: '', 'The rest is on the website.')
	&& array_sum(array_map(fn($p) => Listen_Article::words($p), $parts)) <= 200 + 2 + 6);

$a = Listen_Article::fromHtml('<p>Árvíztűrő tükörfúrógép, ' . para(40, 'szó') . '</p>');
check('counts accented words', Listen_Article::words('Árvíztűrő tükörfúrógép') === 2 && $a->wordCount() === 42);

check('empty content', Listen_Article::fromHtml('')->rejection(3, 100) === 'too_short');

// Played on request (the 🎧 on any article): excerpts are read without their "Read more" and say where the rest is
$excerpt = Listen_Article::fromHtml('<p>The first lines of a post, cut short by the feed. <a href="x">Read more</a></p>');
$text = implode("\n\n", $excerpt->parts('Title', 'Blog', 3000, 'The rest is on the website.'));
check('an excerpt is playable on request', $excerpt->hasText() && $excerpt->rejection(3, 100) === 'read_more');
check('…without reading "Read more", and says where the rest is',
	$text === "Title. Blog.\n\nThe first lines of a post, cut short by the feed.\n\nThe rest is on the website.", json_encode($text) ?: '');
$text = implode("\n\n", Listen_Article::fromHtml('<p>Opening words of a WordPress post […]</p>')->parts('T', '', 3000, 'More online.'));
check('a WordPress […] excerpt ends with … instead of "bracket"', $text === "T.\n\nOpening words of a WordPress post…\n\nMore online.", json_encode($text) ?: '');
$text = implode("\n\n", Listen_Article::fromHtml('<p>' . para(30) . '</p>')->parts('T', '', 3000, 'More online.'));
check('a short but complete post is read as is', !str_contains($text, 'More online.'));
check('pictures only: nothing to read', !Listen_Article::fromHtml('<p><img src="a.jpg"></p><figure><img src="b.jpg"><figcaption>A caption</figcaption></figure>')->hasText());

echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
