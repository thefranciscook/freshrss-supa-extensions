<?php

declare(strict_types=1);

/**
 * The read-only lookups FreshRSS_EntryDAO has no public method for.
 * Marking itself goes through FreshRSS_EntryDAO::markRead() so unread caches and hooks stay right.
 */
final class SeenRead_EntryQueries extends Minz_ModelPdo {

	/**
	 * Which of these ids are still unread. Only those go into a batch, so Rewind never
	 * resurrects an article the user had marked read by hand.
	 * @param list<string> $ids
	 * @return list<string>
	 */
	public function unreadAmong(array $ids): array {
		$unread = [];
		foreach (array_chunk($ids, 500) as $chunk) {
			$placeholders = str_repeat('?,', count($chunk) - 1) . '?';
			$stm = $this->pdo->prepare("SELECT id FROM `_entry` WHERE is_read=0 AND id IN ({$placeholders})");
			if ($stm !== false && $stm->execute($chunk)) {
				foreach ($stm->fetchAll(PDO::FETCH_COLUMN, 0) as $id) {
					$unread[] = (string)$id;
				}
			} else {
				Minz_Log::error('SQL error ' . __METHOD__ . json_encode($stm === false ? $this->pdo->errorInfo() : $stm->errorInfo()));
			}
		}
		return $unread;
	}

	/**
	 * Unread, unstarred entries received before $idMax (entry ids are the insertion time in microseconds).
	 * @param numeric-string $idMax
	 * @return list<string>
	 */
	public function unreadOlderThan(string $idMax, int $limit): array {
		$stm = $this->pdo->prepare('SELECT id FROM `_entry` WHERE is_read=0 AND is_favorite=0 AND id < ? ORDER BY id LIMIT ' . $limit);
		if ($stm !== false && $stm->execute([$idMax])) {
			return array_map('strval', $stm->fetchAll(PDO::FETCH_COLUMN, 0));
		}
		Minz_Log::error('SQL error ' . __METHOD__ . json_encode($stm === false ? $this->pdo->errorInfo() : $stm->errorInfo()));
		return [];
	}

	/**
	 * Which feed each entry belongs to, for the dice's one-per-feed rounds.
	 * @param list<string> $ids
	 * @return array<string,int>
	 */
	public function feedsOf(array $ids): array {
		$feedOf = [];
		foreach (array_chunk($ids, 500) as $chunk) {
			$placeholders = str_repeat('?,', count($chunk) - 1) . '?';
			$stm = $this->pdo->prepare("SELECT id, id_feed FROM `_entry` WHERE id IN ({$placeholders})");
			if ($stm !== false && $stm->execute($chunk)) {
				while (is_array($row = $stm->fetch(PDO::FETCH_NUM))) {
					$feedOf[(string)$row[0]] = (int)$row[1];
				}
			} else {
				Minz_Log::error('SQL error ' . __METHOD__ . json_encode($stm === false ? $this->pdo->errorInfo() : $stm->errorInfo()));
			}
		}
		return $feedOf;
	}
}
