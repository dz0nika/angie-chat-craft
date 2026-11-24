<?php

namespace Dz0nika\AngieChatCraft\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use Dz0nika\AngieChatCraft\AngieChat;
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

        if (empty($settings->enabledSections) && empty($settings->enabledProductTypes)) {
            $this->stderr("No enabledSections or enabledProductTypes in Angie Chat settings.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $queued = AngieChat::$plugin->queueFullSync();

        $this->stdout("Queued {$queued} sync jobs total.\n", Console::FG_CYAN);

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
