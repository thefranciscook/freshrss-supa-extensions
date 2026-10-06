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

	public function rewindAction(): void {
		$result = $this->extension()->rewind();
		$this->json(['restored' => $result['restored'], 'last_batch' => $result['next']]);
	}
}
