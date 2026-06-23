<?php

namespace Dz0nika\AngieChatCraft\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use Dz0nika\AngieChatCraft\AngieChat;
use Dz0nika\AngieChatCraft\jobs\SyncElementJob;
use yii\console\ExitCode;

/**
 * CLI sync helpers for local demo / CI.
 *
 *   php craft angie-chat/sync/all
 *   php craft angie-chat/sync/all --run-queue
 */
class SyncController extends Controller
{
    /** @var bool Process the queue immediately after enqueueing. */
    public bool $runQueue = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['runQueue']);
    }

    public function actionAll(): int
    {
        if (! AngieChat::$plugin) {
            $this->stderr("Angie Chat plugin is not installed.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $settings = AngieChat::$plugin->getSettings();
        $sections = $settings->enabledSections ?? [];

        if ($sections === []) {
            $this->stderr("No enabledSections in Angie Chat settings.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $queued = 0;

        foreach ($sections as $handle) {
            $entries = Entry::find()->section($handle)->status('live')->all();

            foreach ($entries as $entry) {
                Craft::$app->getQueue()->push(new SyncElementJob([
                    'entryId'  => (int) $entry->id,
                    'entryUid' => (string) $entry->uid,
                    'siteId'   => (int) $entry->siteId,
                    'action'   => 'upsert',
                ]));
                $queued++;
            }

            $this->stdout("  [{$handle}] queued " . count($entries) . " entries\n", Console::FG_GREEN);
        }

        $this->stdout("\nQueued {$queued} sync jobs total.\n", Console::FG_CYAN);

        if ($this->runQueue) {
            $this->stdout("Running queue...\n", Console::FG_CYAN);
            Craft::$app->getQueue()->run(false);
            $this->stdout("Queue finished.\n", Console::FG_GREEN);
        } else {
            $this->stdout("Run: php craft queue/run\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }
}
