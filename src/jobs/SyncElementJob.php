<?php

namespace Dz0nika\AngieChatCraft\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use Dz0nika\AngieChatCraft\AngieChat;

/**
 * Sync Element Job – builds the payload and sends it to the Laravel backend.
 *
 * Phase 2 fix: payload extraction (Matrix traversal, image lookup, etc.) now
 * happens here, inside the queue worker, NOT inside the EVENT_AFTER_SAVE
 * listener. The event handler stores only lightweight identifiers so the
 * Control Panel save feels instant regardless of content complexity.
 *
 * For "upsert" actions the entry is re-loaded by ID so the latest persisted
 * state is used (handles edge cases where a second save fires before the
 * first job runs).
 *
 * For "delete" actions the entry is already gone; we just forward the IDs
 * we captured at event time.
 */
class SyncElementJob extends BaseJob
{
    /** Primary key of the Craft entry. */
    public int $entryId;

    /** Craft UID – used as the vector record identifier on delete. */
    public string $entryUid;

    /** Craft site ID. */
    public int $siteId;

    /** 'upsert' or 'delete' */
    public string $action = 'upsert';

    public function execute($queue): void
    {
        if (! AngieChat::$plugin) {
            Craft::warning('Angie Chat: Plugin not initialised, skipping sync job', __METHOD__);
            return;
        }

        try {
            $apiService = AngieChat::$plugin->getApi();

            if ($this->action === 'delete') {
                $apiService->delete([
                    'entry_id'  => $this->entryId,
                    'entry_uid' => $this->entryUid,
                    'site_id'   => $this->siteId,
                ]);

                AngieChat::log('info', "Entry #{$this->entryId} removed from AI index", [
                    'entry_id' => $this->entryId,
                    'action'   => 'delete',
                ]);

                return;
            }

            // Re-load entry inside the job so payload building is fully async
            $entry = Entry::find()
                ->id($this->entryId)
                ->siteId($this->siteId)
                ->status(null)
                ->one();

            if (! $entry) {
                // Entry deleted between event and job execution – silently skip
                Craft::info(
                    "Angie Chat: Entry #{$this->entryId} no longer exists, skipping upsert",
                    __METHOD__
                );
                return;
            }

            $payload           = AngieChat::$plugin->getPayload()->buildFromEntry($entry);
            $payload['action'] = 'upsert';

            $apiService->upsert($payload);

            AngieChat::log('info', "Entry #{$this->entryId} synced to AI index", [
                'entry_id' => $this->entryId,
                'title'    => $payload['title'] ?? '',
                'section'  => $payload['section'] ?? '',
                'action'   => 'upsert',
            ]);

        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();

            AngieChat::log('error', "Failed to sync entry #{$this->entryId}: {$errorMsg}", [
                'entry_id' => $this->entryId,
                'action'   => $this->action,
                'error'    => $errorMsg,
            ]);

            Craft::error(
                "Angie Chat: Failed to sync entry #{$this->entryId}: {$errorMsg}",
                __METHOD__
            );

            if ($this->isRetryable($errorMsg)) {
                throw $e;
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        if ($this->action === 'delete') {
            return "Angie Chat: Remove entry #{$this->entryId} from AI";
        }

        return "Angie Chat: Sync entry #{$this->entryId} to AI";
    }

    private function isRetryable(string $message): bool
    {
        $nonRetryable = ['invalid license', 'expired license', 'unauthorized', '401'];

        foreach ($nonRetryable as $keyword) {
            if (str_contains(strtolower($message), $keyword)) {
                return false;
            }
        }

        return true;
    }
}
