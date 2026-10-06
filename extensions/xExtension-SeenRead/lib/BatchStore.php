<?php

declare(strict_types=1);

/**
 * The undo history behind the Rewind button: a stack of "these ids were auto-marked read at time T".
 * Pure PHP (no FreshRSS dependency) so it can be tested on its own; the extension persists toArray().
 *
 * @phpstan-type Batch array{at:int,kind:string,ids:list<string>}
 */
final class SeenRead_BatchStore {
	public const MAX_BATCHES = 10;
	public const MAX_IDS = 5000;

	/** @var list<Batch> oldest first */
	private array $batches = [];

	/** @param mixed $raw whatever was stored in the user configuration; anything malformed is dropped */
	public function __construct(mixed $raw = null) {
		if (!is_array($raw)) {
			return;
		}
		foreach ($raw as $b) {
			if (is_array($b) && is_int($b['at'] ?? null) && is_string($b['kind'] ?? null) && is_array($b['ids'] ?? null)) {
				$ids = self::cleanIds($b['ids']);
				if ($ids !== []) {
					$this->batches[] = ['at' => $b['at'], 'kind' => $b['kind'], 'ids' => $ids];
				}
			}
		}
		$this->batches = array_slice($this->batches, -self::MAX_BATCHES);
	}

	/**
	 * Entry ids are numeric strings (microsecond timestamps, too big for 32-bit ints).
	 * @param array<mixed> $ids
	 * @return list<string>
	 */
	public static function cleanIds(array $ids): array {
		$clean = [];
		foreach ($ids as $id) {
			if (is_int($id)) {
				$id = (string)$id;
			}
			if (is_string($id) && $id !== '' && ctype_digit($id)) {
				$clean[$id] = true;
			}
		}
		return array_slice(array_map('strval', array_keys($clean)), 0, self::MAX_IDS);
	}

	/** @param array<mixed> $ids */
	public function push(string $kind, array $ids, int $at): void {
		$ids = self::cleanIds($ids);
		if ($ids === []) {
			return;
		}
		$this->batches[] = ['at' => $at, 'kind' => $kind, 'ids' => $ids];
		$this->batches = array_slice($this->batches, -self::MAX_BATCHES);
	}

	/** @return Batch|null the newest batch, removed from the stack */
	public function pop(): ?array {
		return array_pop($this->batches);
	}

	/** @return array{at:int,kind:string,count:int}|null summary of the newest batch, for the button label */
	public function peek(): ?array {
		$last = end($this->batches);
		return $last === false ? null : ['at' => $last['at'], 'kind' => $last['kind'], 'count' => count($last['ids'])];
	}

	public function count(): int {
		return count($this->batches);
	}

	/** @return list<Batch> */
	public function toArray(): array {
		return $this->batches;
	}
}
