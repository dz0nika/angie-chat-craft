<?php

namespace Dz0nika\AngieChatCraft\controllers;

use Craft;
use craft\web\Controller;
use Dz0nika\AngieChatCraft\AngieChat;
use yii\web\Response;

/**
 * Sync Controller - Handles manual sync operations from the Control Panel.
 */
class SyncController extends Controller
{
    /**
     * Force sync all entries from enabled sections and products from enabled product types.
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

        if (empty($settings->enabledSections) && empty($settings->enabledProductTypes)) {
            Craft::$app->getSession()->setError('No sections or product types are enabled for sync.');

            return $this->redirect('settings/plugins/angie-chat');
        }

        try {
            $queuedCount = AngieChat::$plugin->queueFullSync();

            Craft::$app->getSession()->setNotice(
                "Queued {$queuedCount} items for sync. Check the queue utility to monitor progress."
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
