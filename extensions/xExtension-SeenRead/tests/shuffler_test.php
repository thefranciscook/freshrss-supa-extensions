<?php

declare(strict_types=1);

/**
 * Plain-PHP checks for SeenRead_Shuffler. Run inside the container:
 *   docker compose exec freshrss php extensions/xExtension-SeenRead/tests/shuffler_test.php
 */

require __DIR__ . '/../lib/Shuffler.php';

$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void {
	global $failures;
	echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
	if (!$ok) {
		$failures++;
	}
}

/** @return array<string,int> $count ids per feed, ids are "<feed>00<n>" */
function pool(array $counts): array {
	$pool = [];
	foreach ($counts as $feed => $count) {
		for ($i = 1; $i <= $count; $i++) {
			$pool[$feed . '00' . $i] = $feed;
		}
	}
	return $pool;
}

function adjacentSameFeed(array $ids, array $feedOf): int {
	$n = 0;
	for ($i = 1; $i < count($ids); $i++) {
		$n += $feedOf[$ids[$i]] === $feedOf[$ids[$i - 1]] ? 1 : 0;
	}
	return $n;
}

mt_srand(42);
$shuffler = new SeenRead_Shuffler(mt_rand(...));

// A busy feed (200 articles) next to four quiet ones (3 each)
$feedOf = pool([1 => 200, 2 => 3, 3 => 3, 4 => 3, 5 => 3]);
$ids = $shuffler->pick($feedOf, 10);
$perFeed = array_count_values(array_map(fn($id) => $feedOf[$id], $ids));
check('returns n ids', count($ids) === 10);
check('no duplicates', count(array_unique($ids)) === 10);
check('busy feed does not crowd out quiet ones', count($perFeed) === 5 && max($perFeed) === 2, json_encode($perFeed) ?: '');
check('no two from the same feed in a row', adjacentSameFeed($ids, $feedOf) === 0, implode(',', $ids));

// Once the quiet feeds run dry, the busy one may not take over: a quarter of the hand at most
$ids = $shuffler->pick($feedOf, 30);
$perFeed = array_count_values(array_map(fn($id) => $feedOf[$id], $ids));
check('busy feed capped at a quarter', count($ids) === 20 && $perFeed[1] === 8, json_encode($perFeed) ?: '');

$bad = 0;
for ($i = 0; $i < 100; $i++) {
	$skewed = pool([1 => 20, 2 => 1, 3 => 1, 4 => 1]);
	$ids = $shuffler->pick($skewed, 20);   // 5 from feed 1 (its cap) and the 3 singles: B x B x B x B B at best
	$bad += adjacentSameFeed($ids, $skewed);
}
check('a feed with the most articles is spread as thinly as possible', $bad === 100, "$bad adjacent pairs in 100 hands (expected exactly 1 each)");

// ...but a view of just one or two feeds is still a full hand
$one = pool([9 => 50]);
check('single-feed view fills the hand', count($shuffler->pick($one, 20)) === 20);
$two = pool([7 => 30, 8 => 2]);
$perFeed = array_count_values(array_map(fn($id) => $two[$id], $shuffler->pick($two, 20)));
check('two-feed view: fair share is half', $perFeed === [7 => 10, 8 => 2] || $perFeed === [8 => 2, 7 => 10], json_encode($perFeed) ?: '');

// Many rolls: no adjacency even across round boundaries, with only two feeds
$bad = 0;
for ($i = 0; $i < 200; $i++) {
	$two = pool([7 => 10, 8 => 10]);
	$bad += adjacentSameFeed($shuffler->pick($two, 20), $two);
}
check('alternates two feeds across rounds', $bad === 0, "$bad adjacent pairs");

// Exclusions
$small = pool([1 => 3, 2 => 3]);
$ids = $shuffler->pick($small, 10, ['1001', '2002']);
check('skips excluded ids', count($ids) === 4 && !in_array('1001', $ids, true) && !in_array('2002', $ids, true), implode(',', $ids));
check('empty pool gives nothing', $shuffler->pick([], 10) === []);
check('everything excluded gives nothing', $shuffler->pick($small, 10, array_map('strval', array_keys($small))) === []);

// Actually random: different rolls differ, and every article can come up
$seen = [];
for ($i = 0; $i < 300; $i++) {
	foreach ($shuffler->pick(pool([1 => 20, 2 => 20]), 4) as $id) {
		$seen[$id] = true;
	}
}
check('every article can come up', count($seen) === 40, count($seen) . ' of 40');

// Integer-looking keys (PHP turns numeric string keys into ints) come back as strings
$ids = (new SeenRead_Shuffler())->pick(['1728000000000001' => 1, '1728000000000002' => 2], 2);
check('ids are strings', $ids !== [] && array_filter($ids, 'is_string') === $ids, var_export($ids, true));

echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
