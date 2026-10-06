<?php

declare(strict_types=1);

/**
 * JSON endpoints for static/seen.js. POST only; FreshRSS itself rejects POSTs without a valid _csrf.
 */
final class FreshExtension_SeenRead_Controller extends FreshRSS_ActionController {

	private function extension(): SeenReadExtension {
		$ext = Minz_ExtensionManager::findExtension('Seen Read');
		if (!($ext instanceof SeenReadExtension) || !FreshRSS_Auth::hasAccess() || !Minz_Request::isPost()) {
			header('HTTP/1.1 403 Forbidden');
			exit();
		}
		return $ext;
	}

	/** @param array<string,mixed> $data */
	private function json(array $data): never {
		header('Content-Type: application/json; charset=UTF-8');
		exit(json_encode($data));
	}

	/** ids[]=… → every id that just became read (the seen ones that were still unread, plus aged-out ones) */
	public function commitAction(): void {
		$ext = $this->extension();
		$ids = SeenRead_BatchStore::cleanIds(Minz_Request::paramArray('ids', plaintext: true));
		$result = $ext->commit($ids);
		$this->json([
			'committed' => array_merge($result['seen'], $result['aged']),
			'last_batch' => $ext->batches()->peek(),
		]);
	}

	/**
	 * get, state, search (the current view, as in its URL) + seen[]=… + previous[]=… → ids of a fair random
	 * handful, in display order. The client then loads them with search=e:… and swaps them into the stream.
	 */
	public function shuffleAction(): void {
		$ext = $this->extension();
		try {
			FreshRSS_Context::updateUsingRequest(false);
		} catch (FreshRSS_Context_Exception) {
			header('HTTP/1.1 404 Not Found');
			exit();
		}
		$this->json(['ids' => $ext->shuffle(
			SeenRead_BatchStore::cleanIds(Minz_Request::paramArray('seen', plaintext: true)),
			SeenRead_BatchStore::cleanIds(Minz_Request::paramArray('previous', plaintext: true)),
		)]);
	}

	public function rewindAction(): void {
		$result = $this->extension()->rewind();
		$this->json(['restored' => $result['restored'], 'last_batch' => $result['next']]);
	}
}
