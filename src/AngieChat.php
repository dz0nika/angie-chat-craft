<?php

namespace Dz0nika\AngieChatCraft;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use Dz0nika\AngieChatCraft\jobs\LogJob;
use Dz0nika\AngieChatCraft\jobs\SyncElementJob;
use Dz0nika\AngieChatCraft\models\Settings;
use Dz0nika\AngieChatCraft\services\ApiService;
use Dz0nika\AngieChatCraft\services\PayloadBuilder;
use Dz0nika\AngieChatCraft\services\WidgetService;
use Dz0nika\AngieChatCraft\variables\AngieChatVariable;
use yii\base\Event;

/**
 * Angie Chat - AI Customer Service Plugin for Craft CMS 5
 *
 * This plugin serves as a lightweight data bridge between Craft CMS and the
 * Angie Chat SaaS platform. It extracts content from configured sections,
 * sends it to the Laravel backend for vector embedding, and injects the
 * chat widget script on the frontend.
 *
 * Philosophy: This plugin is intentionally "dumb" - it contains no AI logic,
 * no API keys, and makes no synchronous HTTP requests during page loads.
 * All processing is pushed to Craft's native queue system.
 */
class AngieChat extends Plugin
{
    public static ?AngieChat $plugin = null;

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = false;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // Register console controllers (abandoned cart cron command)
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'Dz0nika\\AngieChatCraft\\console\\controllers';
        }

        $this->registerServices();
        $this->registerCpRoutes();

        // Defer event registration to avoid issues during console commands
        // and ensure the application is fully initialized
        Craft::$app->onInit(function () {
            $this->registerEventListeners();
        });

        Craft::info('Angie Chat plugin loaded', __METHOD__);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        $syncStatus      = $this->getSyncStatus();
        $enabledFeatures = $syncStatus['enabled_features'] ?? [];

        return Craft::$app->getView()->renderTemplate('angie-chat/settings', [
            'plugin'          => $this,
            'settings'        => $this->getSettings(),
            'sections'        => $this->getAvailableSections(),
            'syncStatus'      => $syncStatus,
            'enabledFeatures' => $enabledFeatures,
        ]);
    }

    private function registerServices(): void
    {
        $this->setComponents([
            'api' => ApiService::class,
            'payload' => PayloadBuilder::class,
            'widget' => WidgetService::class,
        ]);

        // Register Twig variable
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('angieChat', AngieChatVariable::class);
            }
        );
    }

    private function registerEventListeners(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        if (empty($settings->licenseKey)) {
            Craft::warning('Angie Chat: No license key configured. Plugin is dormant.', __METHOD__);

            return;
        }

        $this->registerEntryEvents();
        $this->registerWidgetInjection();
        // Note: abandoned cart recovery is handled via the console command:
        //   php craft angie-chat/carts/check-abandoned
        // Schedule it with cron – see README for details.
    }

    private function registerEntryEvents(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();
        $enabledSections = $settings->enabledSections ?? [];

        if (empty($enabledSections)) {
            return;
        }

        Event::on(
            Entry::class,
            Element::EVENT_AFTER_SAVE,
            function (ModelEvent $event) use ($enabledSections) {
                /** @var Entry $entry */
                $entry = $event->sender;

                if (! $this->shouldSyncEntry($entry, $enabledSections)) {
                    return;
                }

                $this->dispatchSyncJob($entry, 'upsert');
            }
        );

        Event::on(
            Entry::class,
            Element::EVENT_AFTER_DELETE,
            function (Event $event) use ($enabledSections) {
                /** @var Entry $entry */
                $entry = $event->sender;

                if (! $this->shouldSyncEntry($entry, $enabledSections)) {
                    return;
                }

                $this->dispatchSyncJob($entry, 'delete');
            }
        );

        Craft::info('Angie Chat: Entry sync events registered for sections: '.implode(', ', $enabledSections), __METHOD__);
    }

    private function registerWidgetInjection(): void
    {
        // Skip widget injection for console requests or CP requests
        try {
            $request = Craft::$app->getRequest();
            if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
                return;
            }
        } catch (\Exception $e) {
            // Request not available, skip widget injection
            return;
        }

        Event::on(
            View::class,
            View::EVENT_END_BODY,
            function () {
                try {
                    /** @var WidgetService $widgetService */
                    $widgetService = $this->widget;
                    echo $widgetService->renderWidgetScript();
                } catch (\Exception $e) {
                    // Silently fail - never crash the client's frontend
                    Craft::warning('Angie Chat: Failed to render widget: ' . $e->getMessage(), __METHOD__);
                }
            }
        );
    }

    private function shouldSyncEntry(Entry $entry, array $enabledSections): bool
    {
        if ($entry->getIsDraft() || $entry->getIsRevision()) {
            return false;
        }

        $section = $entry->getSection();
        if (! $section) {
            return false;
        }

        return in_array($section->handle, $enabledSections, true);
    }

    private function dispatchSyncJob(Entry $entry, string $action): void
    {
        try {
            // Phase 2: pass only lightweight identifiers. All heavy payload
            // building (Matrix traversal, image lookup) happens inside
            // SyncElementJob::execute() – fully off the request thread.
            Craft::$app->getQueue()->push(new SyncElementJob([
                'entryId'  => (int) $entry->id,
                'entryUid' => (string) $entry->uid,
                'siteId'   => (int) $entry->siteId,
                'action'   => $action,
            ]));

            Craft::info("Angie Chat: Queued {$action} for entry #{$entry->id}", __METHOD__);
        } catch (\Exception $e) {
            Craft::error("Angie Chat: Failed to queue {$action} for entry #{$entry->id}: " . $e->getMessage(), __METHOD__);
        }
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['angie-chat/sync-all'] = 'angie-chat/sync/sync-all';
                $event->rules['angie-chat/status'] = 'angie-chat/sync/status';
            }
        );
    }

    public function getAvailableSections(): array
    {
        $sections = [];

        try {
            foreach (Craft::$app->getEntries()->getAllSections() as $section) {
                if ($section->type === 'single') {
                    continue;
                }

                $sections[] = [
                    'handle' => $section->handle ?? '',
                    'name' => $section->name ?? 'Unknown',
                    'type' => $section->type ?? 'channel',
                    'entryCount' => Entry::find()->section($section->handle)->status(null)->count(),
                ];
            }
        } catch (\Exception $e) {
            Craft::warning('Angie Chat: Failed to get sections: ' . $e->getMessage(), __METHOD__);
        }

        return $sections;
    }

    public function getSyncStatus(): array
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        if (empty($settings->licenseKey)) {
            return [
                'connected' => false,
                'message' => 'No license key configured',
            ];
        }

        try {
            /** @var ApiService $apiService */
            $apiService = $this->api;

            return $apiService->checkStatus();
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'message' => 'Failed to connect: '.$e->getMessage(),
            ];
        }
    }

    public function getApi(): ApiService
    {
        return $this->api;
    }

    public function getPayload(): PayloadBuilder
    {
        return $this->payload;
    }

    public function getWidget(): WidgetService
    {
        return $this->widget;
    }

    /**
     * Check whether a feature is active for this license key.
     * Returns false when the plugin is not initialised or there is no license key.
     * Uses a 5-minute cache so repeated calls within a request are free.
     */
    public static function hasFeature(string $slug): bool
    {
        if (! self::$plugin) {
            return false;
        }

        try {
            return self::$plugin->getApi()->hasFeature($slug);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Queue a log entry to be streamed to the Laravel backend.
     * Safe to call from anywhere – silently does nothing if the plugin
     * is not initialised or no license key is configured.
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        try {
            if (! self::$plugin) {
                return;
            }

            $settings = self::$plugin->getSettings();
            if (empty($settings->licenseKey)) {
                return;
            }

            Craft::$app->getQueue()->push(new LogJob([
                'level'   => $level,
                'message' => $message,
                'context' => $context,
            ]));
        } catch (\Throwable $e) {
            // Absolute last resort – write to Craft's own log only
            Craft::warning("Angie Chat: Could not queue log job: {$e->getMessage()}", __METHOD__);
        }
    }
}
