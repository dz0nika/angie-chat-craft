<?php

namespace Dz0nika\AngieChatCraft\jobs;

use Craft;
use craft\commerce\elements\Product;
use craft\elements\Entry;
use craft\queue\BaseJob;
use yii\queue\RetryableJobInterface;
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
class SyncElementJob extends BaseJob implements RetryableJobInterface
{
    /** Attempts for a rate-limited (429), server-side (5xx) or network failure. */
    private const MAX_ATTEMPTS = 5;

    /** Primary key of the Craft entry. */
    public int $entryId;

    /** Craft UID – used as the vector record identifier on delete. */
    public string $entryUid;

    /** Craft site ID. */
    public int $siteId;

    /** 'upsert' or 'delete' */
    public string $action = 'upsert';

    /** 'entry' or 'product' (Craft Commerce). Defaults keep already-queued jobs valid. */
    public string $elementType = 'entry';

    public function execute($queue): void
    {
        if (! AngieChat::$plugin) {
            Craft::warning('Angie Chat: Plugin not initialised, skipping sync job', __METHOD__);
            return;
        }

        // Skip the API round-trip entirely when the website is over its
        // monthly quota. The backend would 429 the upsert too, but draining
        // the queue locally lets the customer's workers free up faster.
        if (AngieChat::$plugin->getUsage()->isBlocked()) {
            Craft::info(
                "Angie Chat: Skipping sync of entry #{$this->entryId} — usage limit reached",
                __METHOD__
            );
            return;
        }

        try {
            $apiService = AngieChat::$plugin->getApi();

            // Re-load inside the job so payload building is fully async
            $elementClass = $this->elementType === 'product' ? Product::class : Entry::class;
            $entry = $this->action === 'delete' ? null : $elementClass::find()
                ->id($this->entryId)
                ->siteId($this->siteId)
                ->status(null)
                ->one();

            // Disabled, expired or scheduled entries and products must not reach
            // the AI (a Black Friday promo prepared ahead would leak early).
            // Status is per site, and each site version is its own job.
            $notLive = $entry !== null && $entry->getStatus() !== 'live';

            if ($this->action === 'delete' || $notLive) {
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

            if (! $entry) {
                // Entry deleted between event and job execution – silently skip
                Craft::info(
                    "Angie Chat: Entry #{$this->entryId} no longer exists, skipping upsert",
                    __METHOD__
                );
                return;
            }

            $payload = $entry instanceof Entry
                ? AngieChat::$plugin->getPayload()->buildFromEntry($entry)
                : AngieChat::$plugin->getPayload()->buildFromProduct($entry);
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

            if ($this->isRetryable($e)) {
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

    /**
     * Also the pause between attempts: Craft retries a failed job once its
     * reservation (this TTR) expires.
     */
    public function getTtr(): int
    {
        return 120;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < self::MAX_ATTEMPTS && $error instanceof \Throwable && $this->isRetryable($error);
    }

    /**
     * 429 (busy), 5xx and network errors (code 0) heal on their own; a bad
     * key (401), forbidden (403) or invalid payload (422) never will.
     */
    private function isRetryable(\Throwable $e): bool
    {
        $code = (int) $e->getCode();

        return $code === 0 || $code === 429 || $code >= 500;
    }
}
