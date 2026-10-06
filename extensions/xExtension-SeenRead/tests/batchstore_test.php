<?php

declare(strict_types=1);

/**
 * Plain-PHP checks for SeenRead_BatchStore. Run inside the container:
 *   docker compose exec freshrss php extensions/xExtension-SeenRead/tests/batchstore_test.php
 */

require __DIR__ . '/../lib/BatchStore.php';

$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void {
	global $failures;
	echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
	if (!$ok) {
		$failures++;
	}
}

// Empty / malformed input
$s = new SeenRead_BatchStore(null);
check('null config gives empty store', $s->count() === 0 && $s->peek() === null && $s->pop() === null);
$s = new SeenRead_BatchStore(['junk', ['at' => 'x', 'kind' => 'seen', 'ids' => ['1']], ['at' => 1, 'kind' => 'seen', 'ids' => ['abc']]]);
check('malformed batches are dropped', $s->count() === 0, json_encode($s->toArray()) ?: '');

// Id cleaning: big numeric strings survive as strings, duplicates and junk go
$ids = SeenRead_BatchStore::cleanIds(['1728000000000001', 1728000000000002, '1728000000000001', '-5', '1e3', '', null, '12a']);
check('cleanIds keeps numeric, dedupes, drops junk', $ids === ['1728000000000001', '1728000000000002'], json_encode($ids) ?: '');

// Push / peek / pop order
$s = new SeenRead_BatchStore();
$s->push('seen', ['10', '11'], 100);
$s->push('aged', ['5'], 200);
$s->push('seen', [], 300);
check('empty push is ignored', $s->count() === 2);
check('peek summarises newest', $s->peek() === ['at' => 200, 'kind' => 'aged', 'count' => 1], json_encode($s->peek()) ?: '');
$b = $s->pop();
check('pop returns newest first', $b !== null && $b['kind'] === 'aged' && $b['ids'] === ['5']);
$b = $s->pop();
check('then the older one', $b !== null && $b['ids'] === ['10', '11']);
check('then nothing', $s->pop() === null);

// Caps
$s = new SeenRead_BatchStore();
for ($i = 1; $i <= SeenRead_BatchStore::MAX_BATCHES + 3; $i++) {
	$s->push('seen', [(string)$i], $i);
}
check('keeps only the newest MAX_BATCHES', $s->count() === SeenRead_BatchStore::MAX_BATCHES && $s->toArray()[0]['at'] === 4);
$s->push('aged', range(1, SeenRead_BatchStore::MAX_IDS + 50), 999);
check('caps ids per batch', $s->peek()['count'] === SeenRead_BatchStore::MAX_IDS);

// Round trip through the stored form
$s2 = new SeenRead_BatchStore($s->toArray());
check('round trip keeps everything', $s2->toArray() === $s->toArray());

echo $failures === 0 ? "\nall passed\n" : "\n$failures failed\n";
exit($failures === 0 ? 0 : 1);
