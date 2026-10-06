<?php

declare(strict_types=1);

/**
 * Plain-PHP checks for ReadingRhythm_Scheduler. Run inside the container:
 *   docker compose exec freshrss php extensions/xExtension-ReadingRhythm/tests/scheduler_test.php
 */

require __DIR__ . '/../lib/Scheduler.php';

$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void {
	global $failures;
	echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
	if (!$ok) {
		$failures++;
	}
}

/**
 * @param list<array{0:int,1:int,2:bool}> $spec [feed, category, hasImage] per item
 * @return list<array{key:int,feed:int,category:int,hasImage:bool}>
 */
function items(array $spec): array {
	$items = [];
	foreach ($spec as $key => [$feed, $category, $hasImage]) {
		$items[] = ['key' => $key, 'feed' => $feed, 'category' => $category, 'hasImage' => $hasImage];
	}
	return $items;
}

/**
 * @param list<array{key:int,feed:int,category:int,hasImage:bool}> $items
 * @param list<int|string> $order
 * @return list<array{key:int,feed:int,category:int,hasImage:bool}>
 */
function reorder(array $items, array $order): array {
	return array_map(static fn($k) => $items[$k], $order);
}

/** @param list<array{key:int,feed:int,category:int,hasImage:bool}> $seq */
function longestRun(array $seq, string $field): int {
	$best = $run = 0;
	$prev = null;
	foreach ($seq as $item) {
		$run = $item[$field] === $prev ? $run + 1 : 1;
		$prev = $item[$field];
		$best = max($best, $run);
	}
	return $best;
}

/**
 * Largest number of consecutive non-image items, including before the first and after the last image.
 * @param list<array{key:int,feed:int,category:int,hasImage:bool}> $seq
 */
function longestTextStretch(array $seq): int {
	$best = $run = 0;
	foreach ($seq as $item) {
		$run = $item['hasImage'] ? 0 : $run + 1;
		$best = max($best, $run);
	}
	return $best;
}

/** @param list<array{key:int,feed:int,category:int,hasImage:bool}> $seq */
function show(array $seq): string {
	return implode(' ', array_map(static fn($i) => 'f' . $i['feed'] . 'c' . $i['category'] . ($i['hasImage'] ? '*' : ''), $seq));
}

/** @param list<array{key:int,feed:int,category:int,hasImage:bool}> $items */
function isPermutation(array $items, array $order): bool {
	$keys = array_column($items, 'key');
	sort($keys);
	sort($order);
	return $keys === $order;
}

$scheduler = new ReadingRhythm_Scheduler();

// 1. A bursty feed: 10 consecutive posts from feed 1 among 30
$spec = [];
for ($i = 0; $i < 30; $i++) {
	$spec[] = $i >= 5 && $i < 15 ? [1, 1, false] : [2 + $i % 5, 2 + $i % 3, $i % 4 === 0];
}
$items = items($spec);
$order = $scheduler->order($items);
$seq = reorder($items, $order);
check('burst: permutation', isPermutation($items, $order));
check('burst: no two adjacent posts from the bursty feed', longestRun($seq, 'feed') === 1, show($seq));

// 2. Three categories in blocks of 10, each category has 3 feeds
$spec = [];
for ($i = 0; $i < 30; $i++) {
	$cat = intdiv($i, 10);
	$spec[] = [$cat * 10 + $i % 3, $cat, false];
}
$items = items($spec);
$order = $scheduler->order($items);
$seq = reorder($items, $order);
check('categories: permutation', isPermutation($items, $order));
check('categories: no run longer than 2', longestRun($seq, 'category') <= 2, show($seq));

// 3. Six image posts, all bunched at the start
$spec = [];
for ($i = 0; $i < 30; $i++) {
	$spec[] = [$i % 7, $i % 4, $i < 6];
}
$items = items($spec);
$order = $scheduler->order($items);
$seq = reorder($items, $order);
check('images: permutation', isPermutation($items, $order));
$limit = (int)ceil(30 / 6) + 1;
check("images: no text-only stretch longer than $limit", longestTextStretch($seq) <= $limit, show($seq));

// 4. Six image posts, all bunched at the end
$spec = [];
for ($i = 0; $i < 30; $i++) {
	$spec[] = [$i % 7, $i % 4, $i >= 24];
}
$items = items($spec);
$seq = reorder($items, $scheduler->order($items));
check("images at end: no text-only stretch longer than $limit", longestTextStretch($seq) <= $limit, show($seq));

// 5. Everything off: original order
$items = items($spec);
$off = new ReadingRhythm_Scheduler(mix: false, images: false);
check('off: original order', $off->order($items) === array_column($items, 'key'));

// 6. Images only: categories are not touched beyond what images require
$imagesOnly = new ReadingRhythm_Scheduler(mix: false, images: true);
$order = $imagesOnly->order($items);
check('images only: permutation', isPermutation($items, $order));
check("images only: no text-only stretch longer than $limit", longestTextStretch(reorder($items, $order)) <= $limit);

// 7. Drift: an already varied page stays close to its original order
$spec = [];
for ($i = 0; $i < 30; $i++) {
	$spec[] = [$i % 10, $i % 5, $i % 5 === 2];
}
$items = items($spec);
$order = $scheduler->order($items);
$maxDrift = 0;
foreach ($order as $pos => $key) {
	$maxDrift = max($maxDrift, abs($key - $pos));
}
check('drift: varied page moves items by at most 3', $maxDrift <= 3, implode(',', $order));

// 8. Random realistic pages: 20–60 items, 3–6 categories of uneven size, ~25% images, sometimes a burst.
// Measured as excess over the best possible arrangement of each page, since a page that is 70% one
// category cannot avoid runs.

/**
 * Shortest possible longest run: the largest group split by everything else.
 * @param list<array{key:int,feed:int,category:int,hasImage:bool}> $items
 */
function bestRun(array $items, string $field): int {
	$max = max(array_count_values(array_column($items, $field)));
	return (int)ceil($max / (count($items) - $max + 1));
}

/** @param list<int> $values */
function percentile95(array $values): int {
	sort($values);
	return $values[(int)floor(0.95 * (count($values) - 1))];
}

mt_srand(42);
$excess = ['category' => [], 'feed' => [], 'text' => []];
$permutations = true;
for ($run = 0; $run < 300; $run++) {
	$n = mt_rand(20, 60);
	$nCat = mt_rand(3, 6);
	$spec = [];
	$burstAt = mt_rand(0, 1) === 1 ? mt_rand(0, $n - 8) : -1;
	for ($i = 0; $i < $n; $i++) {
		$inBurst = $burstAt >= 0 && $i >= $burstAt && $i < $burstAt + 6;
		// Skewed category sizes: category 0 is the most common, the last one the rarest
		$cat = $inBurst ? 99 : min(mt_rand(0, $nCat - 1), mt_rand(0, $nCat - 1));
		$spec[] = [$inBurst ? 999 : $cat * 10 + mt_rand(0, 4), $cat, mt_rand(1, 4) === 1];
	}
	$items = items($spec);
	$order = $scheduler->order($items);
	$seq = reorder($items, $order);
	$permutations = $permutations && isPermutation($items, $order);
	$excess['category'][] = longestRun($seq, 'category') - bestRun($items, 'category');
	$excess['feed'][] = longestRun($seq, 'feed') - bestRun($items, 'feed');
	$nImg = count(array_filter($spec, static fn($s) => $s[2]));
	if ($nImg > 0) {
		$excess['text'][] = longestTextStretch($seq) - (int)ceil(($n - $nImg) / ($nImg + 1));
	}
}
check('random: always a permutation', $permutations);
check('random: category runs within 2 of the best possible (p95)', percentile95($excess['category']) <= 2,
	'p95 +' . percentile95($excess['category']));
check('random: feed runs within 1 of the best possible (always)', max($excess['feed']) <= 1, 'worst +' . max($excess['feed']));
check('random: text-only stretches within 3 of the even gap (p95)', percentile95($excess['text']) <= 3,
	'p95 +' . percentile95($excess['text']));

// 9. Edge cases
check('empty page', $scheduler->order([]) === []);
check('single item', $scheduler->order(items([[1, 1, true]])) === [0]);

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
