<?php

declare(strict_types=1);

/**
 * The dice: picks a fair random handful from the current view.
 * Pure PHP (no FreshRSS dependency) so it can be tested on its own.
 *
 * Plain ORDER BY RANDOM() lets a feed that posts 50 times a day fill the page. Here every feed gets
 * the same chance: each round visits the feeds in a fresh random order and takes one random article
 * from each, so a quiet blog shows up as often as a busy news site. The hand is then laid out so that
 * articles from the same feed are as far apart as they can be. Once the quiet
 * feeds run dry, no feed may fill more than a quarter of the hand (or its fair share, in a view with
 * only a few feeds): a shorter hand beats a page of one feed.
 */
final class SeenRead_Shuffler {

	/** @var callable(int,int):int */
	private $rand;

	/** @param (callable(int,int):int)|null $rand random_int-compatible; tests pass a seeded one */
	public function __construct(?callable $rand = null) {
		$this->rand = $rand ?? random_int(...);
	}

	/**
	 * @param array<string,int> $feedOf candidate entry id => feed id
	 * @param list<string> $exclude ids not to pick (already seen, or shown by an earlier roll)
	 * @return list<string> at most $n ids, in display order
	 */
	public function pick(array $feedOf, int $n, array $exclude = []): array {
		$skip = array_flip($exclude);
		$cap = max((int)ceil($n / 4), (int)ceil($n / max(1, count(array_unique($feedOf)))));
		$byFeed = [];
		foreach ($feedOf as $id => $feed) {
			$id = (string)$id;
			if (!isset($skip[$id])) {
				$byFeed[$feed][] = $id;
			}
		}
		foreach ($byFeed as $feed => $ids) {
			$byFeed[$feed] = $this->shuffle($ids);
		}

		$hand = [];   // feed => ids picked from it
		$count = 0;
		while ($count < $n && $byFeed !== []) {
			foreach ($this->shuffle(array_keys($byFeed)) as $feed) {
				$hand[$feed][] = array_pop($byFeed[$feed]);
				if ($byFeed[$feed] === [] || count($hand[$feed]) >= $cap) {
					unset($byFeed[$feed]);
				}
				if (++$count >= $n) {
					break;
				}
			}
		}
		return $this->arrange($hand);
	}

	/**
	 * Random order, but spread out: each next article comes from a different feed than the one before,
	 * chosen at random weighted by how many that feed still has, except that a feed holding more than
	 * all the others combined goes now, while it still can be split up.
	 * @param array<int,list<string>> $hand
	 * @return list<string>
	 */
	private function arrange(array $hand): array {
		$out = [];
		$last = null;
		$total = array_sum(array_map('count', $hand));
		while ($total > 0) {
			$next = null;
			$options = [];
			foreach ($hand as $feed => $ids) {
				if ($feed === $last) {
					continue;
				}
				if (count($ids) > $total - count($ids)) {
					$next = $feed;
				}
				$options[$feed] = count($ids);
			}
			if ($next === null && $options === []) {
				$next = $last;   // only one feed left: nothing to spread
			}
			if ($next === null) {
				$r = ($this->rand)(1, array_sum($options));
				foreach ($options as $feed => $weight) {
					if (($r -= $weight) <= 0) {
						$next = $feed;
						break;
					}
				}
			}
			$out[] = array_pop($hand[$next]);
			if ($hand[$next] === []) {
				unset($hand[$next]);
			}
			$last = $next;
			$total--;
		}
		/** @var list<string> $out */
		return $out;
	}

	/**
	 * Fisher–Yates.
	 * @template T
	 * @param list<T> $items
	 * @return list<T>
	 */
	private function shuffle(array $items): array {
		for ($i = count($items) - 1; $i > 0; $i--) {
			$j = ($this->rand)(0, $i);
			[$items[$i], $items[$j]] = [$items[$j], $items[$i]];
		}
		return $items;
	}
}
