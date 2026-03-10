<?php

namespace Dz0nika\AngieChatCraft\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use Dz0nika\AngieChatCraft\AngieChat;
use Dz0nika\AngieChatCraft\jobs\SyncElementJob;
use yii\web\Response;

/**
 * Sync Controller - Handles manual sync operations from the Control Panel.
 */
class SyncController extends Controller
{
    /**
     * Force sync all entries from enabled sections.
     *
     * Uses batch processing to avoid memory exhaustion on large sites.
     */
    public function actionSyncAll(): Response
    {
        $this->requireCpRequest();

        if (! AngieChat::$plugin) {
            Craft::$app->getSession()->setError('Angie Chat plugin is not properly initialized.');

            return $this->redirect('settings/plugins/angie-chat');
        }

        $settings = AngieChat::$plugin->getSettings();

        if (empty($settings->licenseKey)) {
            Craft::$app->getSession()->setError('Please configure your license key first.');

            return $this->redirect('settings/plugins/angie-chat');
        }

        $enabledSections = $settings->enabledSections ?? [];

        if (empty($enabledSections)) {
            Craft::$app->getSession()->setError('No sections are enabled for sync.');

            return $this->redirect('settings/plugins/angie-chat');
        }

        $queuedCount = 0;
        $batchSize = 50;

        try {
            foreach ($enabledSections as $sectionHandle) {
                // Use batch processing to avoid memory issues on large sites.
                // Pass only lightweight identifiers – payload building happens
                // inside SyncElementJob::execute() on the queue worker.
                $query = Entry::find()
                    ->section($sectionHandle)
                    ->status('live');

                $totalEntries = $query->count();
                $offset = 0;

                while ($offset < $totalEntries) {
                    $entries = $query
                        ->select(['elements.id', 'elements.uid', 'elements_sites.siteId'])
                        ->offset($offset)
                        ->limit($batchSize)
                        ->all();

                    foreach ($entries as $entry) {
                        try {
                            Craft::$app->getQueue()->push(new SyncElementJob([
                                'entryId'  => (int) $entry->id,
                                'entryUid' => (string) $entry->uid,
                                'siteId'   => (int) $entry->siteId,
                                'action'   => 'upsert',
                            ]));

                            $queuedCount++;
                        } catch (\Exception $e) {
                            Craft::warning("Angie Chat: Failed to queue entry #{$entry->id}: " . $e->getMessage(), __METHOD__);
                        }
                    }

                    // Release memory between batches
                    $offset += $batchSize;
                    gc_collect_cycles();
                }
            }

            Craft::$app->getSession()->setNotice(
                "Queued {$queuedCount} entries for sync. Check the queue utility to monitor progress."
            );
        } catch (\Exception $e) {
            Craft::error('Angie Chat: Sync all failed: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getSession()->setError('Failed to queue entries: ' . $e->getMessage());
        }

        return $this->redirect('settings/plugins/angie-chat');
    }

    /**
     * Get current sync status (AJAX endpoint).
     */
    public function actionStatus(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        if (! AngieChat::$plugin) {
            return $this->asJson([
                'connected' => false,
                'message' => 'Plugin not initialized',
            ]);
        }

        try {
            $status = AngieChat::$plugin->getSyncStatus();

            return $this->asJson($status);
        } catch (\Exception $e) {
            return $this->asJson([
                'connected' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }
}
