<?php

namespace nikolapopovic\angiechat\jobs;

use Craft;
use craft\queue\BaseJob;
use nikolapopovic\angiechat\AngieChat;

/**
 * Sync Element Job - Sends content to the Laravel backend.
 *
 * This job runs in the background via Craft's queue system.
 * It sends entry data to the Angie Chat API for vector embedding.
 */
class SyncElementJob extends BaseJob
{
    /**
     * The payload data to send.
     */
    public array $payload = [];

    /**
     * The action type: 'upsert' or 'delete'
     */
    public string $action = 'upsert';

    public function execute($queue): void
    {
        // Validate plugin is available
        if (! AngieChat::$plugin) {
            Craft::warning('Angie Chat: Plugin not initialized, skipping sync job', __METHOD__);

            return;
        }

        $entryId = $this->payload['entry_id'] ?? 'unknown';

        try {
            $apiService = AngieChat::$plugin->getApi();

            if ($this->action === 'delete') {
                $response = $apiService->delete([
                    'entry_id' => $this->payload['entry_id'] ?? 0,
                    'entry_uid' => $this->payload['entry_uid'] ?? '',
                    'site_id' => $this->payload['site_id'] ?? 1,
                ]);
            } else {
                $response = $apiService->upsert($this->payload);
            }

            Craft::info(
                "Angie Chat: Successfully synced entry #{$entryId} ({$this->action})",
                __METHOD__
            );
        } catch (\Exception $e) {
            Craft::error(
                "Angie Chat: Failed to sync entry #{$entryId}: ".$e->getMessage(),
                __METHOD__
            );

            if ($this->isRetryableError($e)) {
                throw $e;
            }
            // Non-retryable errors are logged but don't re-throw
        }
    }

    protected function defaultDescription(): ?string
    {
        $entryId = $this->payload['entry_id'] ?? 'unknown';
        $title = $this->payload['title'] ?? '';

        if ($this->action === 'delete') {
            return "Angie Chat: Remove entry #{$entryId} from AI";
        }

        $titlePart = $title ? " \"{$title}\"" : '';

        return "Angie Chat: Sync entry #{$entryId}{$titlePart} to AI";
    }

    private function isRetryableError(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());

        $nonRetryable = [
            'invalid license',
            'expired license',
            'unauthorized',
            '401',
        ];

        foreach ($nonRetryable as $keyword) {
            if (str_contains($message, $keyword)) {
                return false;
            }
        }

        return true;
    }
}
