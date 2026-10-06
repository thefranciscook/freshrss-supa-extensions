<?php

declare(strict_types=1);

/**
 * Reorders one page of the stream for variety. Pure PHP, no FreshRSS dependency.
 *
 * Greedy, slot by slot: among a small set of candidates, place the one with the
 * lowest cost. The cost keeps the original order roughly intact (drift), pushes
 * apart items of the same feed and category, favours feeds and categories that are
 * overdue for their fair share of the page, and keeps image posts evenly spaced.
 */
final class ReadingRhythm_Scheduler {

	/** How many of the next unplaced items (in original order) are always candidates. */
	private const WINDOW = 8;
	/** How far back to look for same-feed / same-category neighbours. */
	private const FEED_MEMORY = 6;
	private const CATEGORY_MEMORY = 4;

	private const W_DRIFT = 0.1;
	private const W_FEED = 4.0;
	private const W_CATEGORY = 3.0;
	private const W_FEED_DUE = 1.0;
	private const W_CATEGORY_DUE = 2.0;
	private const W_IMAGE = 3.0;

	public function __construct(
		private readonly bool $mix = true,
		private readonly bool $images = true,
	) {}

	/**
	 * @param list<array{key:int|string,feed:int,category:int,hasImage:bool}> $items in original order
	 * @return list<int|string> keys in the new order
	 */
	public function order(array $items): array {
		if (!$this->mix && !$this->images) {
			return array_column($items, 'key');
		}

		$total = count($items);
		$imagesLeft = count(array_filter($items, static fn(array $i): bool => $i['hasImage']));
		$useImages = $this->images && $imagesLeft > 0 && $imagesLeft < $total;

		/** @var array<int,true> $unplaced original index => true, kept in original order */
		$unplaced = array_fill_keys(array_keys($items), true);
		/** @var list<int> $placed original indexes, in placement order */
		$placed = [];
		// Start half a gap in, so the first image comes early but not necessarily first
		$sinceImage = $useImages ? (int)floor($total / $imagesLeft / 2) : 0;

		/** @var array{feed:array<int,int>,category:array<int,int>} $left items not yet placed, per group */
		$left = ['feed' => [], 'category' => []];
		/** @var array{feed:array<int,int>,category:array<int,int>} $lastPos position of the last placed item, per group */
		$lastPos = ['feed' => [], 'category' => []];
		foreach ($items as $item) {
			foreach (['feed', 'category'] as $field) {
				$left[$field][$item[$field]] = ($left[$field][$item[$field]] ?? 0) + 1;
			}
		}

		for ($pos = 0; $pos < $total; $pos++) {
			$best = null;
			$bestCost = INF;
			foreach ($this->candidates($items, $unplaced, $useImages) as $i) {
				$item = $items[$i];
				$cost = self::W_DRIFT * max(0, $i - $pos);

				if ($this->mix) {
					$slotsLeft = $total - $pos;
					foreach ([
						['feed', self::FEED_MEMORY, self::W_FEED, self::W_FEED_DUE],
						['category', self::CATEGORY_MEMORY, self::W_CATEGORY, self::W_CATEGORY_DUE],
					] as [$field, $memory, $wRepeat, $wDue]) {
						$group = $item[$field];
						// Even spacing for this group: one in every $fairGap of the remaining slots
						$fairGap = $slotsLeft / $left[$field][$group];
						$cost += $wRepeat * self::repeatPenalty($items, $placed, $field, $group, $memory, $fairGap);
						$cost -= $wDue * max(0.0, ($pos - ($lastPos[$field][$group] ?? -1)) + 1 - $fairGap);
					}
				}

				if ($useImages && $imagesLeft > 0) {
					$idealGap = ($total - $pos) / $imagesLeft;
					$cost += self::W_IMAGE * ($item['hasImage']
						? max(0.0, $idealGap - 1 - $sinceImage)	// too early
						: max(0.0, $sinceImage + 1 - $idealGap));	// overdue
				}

				// Candidates come in original order, so `<` keeps the earlier one on ties
				if ($cost < $bestCost) {
					$bestCost = $cost;
					$best = $i;
				}
			}
			if ($best === null) {
				break;
			}

			unset($unplaced[$best]);
			$placed[] = $best;
			foreach (['feed', 'category'] as $field) {
				$left[$field][$items[$best][$field]]--;
				$lastPos[$field][$items[$best][$field]] = $pos;
			}
			if ($items[$best]['hasImage']) {
				$imagesLeft--;
				$sinceImage = 0;
			} else {
				$sinceImage++;
			}
		}

		return array_map(static fn(int $i): int|string => $items[$i]['key'], $placed);
	}

	/**
	 * The next WINDOW unplaced items, plus the next item of each category and the next image post,
	 * so a long run of one category or of text-only posts can always be broken.
	 * @param list<array{key:int|string,feed:int,category:int,hasImage:bool}> $items
	 * @param array<int,true> $unplaced
	 * @return list<int> original indexes, ascending
	 */
	private function candidates(array $items, array $unplaced, bool $useImages): array {
		$result = [];
		$seenCategories = [];
		$imageFound = !$useImages;
		$n = 0;
		foreach ($unplaced as $i => $_) {
			$take = $n < self::WINDOW;
			if ($this->mix && !isset($seenCategories[$items[$i]['category']])) {
				$seenCategories[$items[$i]['category']] = true;
				$take = true;
			}
			if (!$imageFound && $items[$i]['hasImage']) {
				$imageFound = true;
				$take = true;
			}
			if ($take) {
				$result[] = $i;
			}
			$n++;
			if ($n >= self::WINDOW && $imageFound && !$this->mix) {
				break;
			}
		}
		return $result;
	}

	/**
	 * Sum of 1/distance over the last $memory placed items sharing $field = $value, scaled down
	 * the closer the distance is to $fairGap: a group holding 1/3 of what's left must come back
	 * every 3 slots, so being 2 apart is barely a repeat, and 3 apart is none.
	 * @param list<array{key:int|string,feed:int,category:int,hasImage:bool}> $items
	 * @param list<int> $placed
	 * @param 'feed'|'category' $field
	 */
	private static function repeatPenalty(array $items, array $placed, string $field, int $value, int $memory, float $fairGap): float {
		$penalty = 0.0;
		$count = count($placed);
		for ($d = 1; $d <= $memory && $d <= $count && $d < $fairGap; $d++) {
			if ($items[$placed[$count - $d]][$field] === $value) {
				$penalty += (1 - $d / $fairGap) / $d;
			}
		}
		return $penalty;
	}
}
