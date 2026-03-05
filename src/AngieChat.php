<?php

namespace nikolapopovic\angiechat;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use nikolapopovic\angiechat\jobs\AbandonedCartJob;
use nikolapopovic\angiechat\jobs\SyncElementJob;
use nikolapopovic\angiechat\models\Settings;
use nikolapopovic\angiechat\services\ApiService;
use nikolapopovic\angiechat\services\PayloadBuilder;
use nikolapopovic\angiechat\services\WidgetService;
use nikolapopovic\angiechat\variables\AngieChatVariable;
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
        return Craft::$app->getView()->renderTemplate('angie-chat/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'sections' => $this->getAvailableSections(),
            'syncStatus' => $this->getSyncStatus(),
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
        $this->registerCommerceEvents();
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

    private function registerCommerceEvents(): void
    {
        if (! $this->isCommerceInstalled()) {
            return;
        }

        /** @var Settings $settings */
        $settings = $this->getSettings();

        if (! $settings->enableAbandonedCart) {
            return;
        }

        $this->registerCommerceCartEvents();
    }

    private function registerCommerceCartEvents(): void
    {
        $commerceOrderClass = 'craft\\commerce\\elements\\Order';

        if (! class_exists($commerceOrderClass)) {
            return;
        }

        Event::on(
            $commerceOrderClass,
            'afterSave',
            function ($event) {
                $order = $event->sender;

                if (! method_exists($order, 'getIsCompleted') || $order->getIsCompleted()) {
                    return;
                }

                if (! method_exists($order, 'getEmail') || empty($order->getEmail())) {
                    return;
                }

                $this->checkForAbandonedCart($order);
            }
        );

        Craft::info('Angie Chat: Commerce abandoned cart events registered', __METHOD__);
    }

    private function checkForAbandonedCart($order): void
    {
        try {
            $abandonmentThreshold = 30 * 60;

            $lastUpdated = $order->dateUpdated ?? $order->dateCreated ?? null;
            if (! $lastUpdated || ! ($lastUpdated instanceof \DateTime || $lastUpdated instanceof \DateTimeInterface)) {
                return;
            }

            $timeSinceUpdate = time() - $lastUpdated->getTimestamp();

            if ($timeSinceUpdate < $abandonmentThreshold) {
                return;
            }

            $email = method_exists($order, 'getEmail') ? $order->getEmail() : null;
            if (empty($email) || ! isset($order->id)) {
                return;
            }

            Craft::$app->getQueue()->push(new AbandonedCartJob([
                'orderId' => (int) $order->id,
                'email' => (string) $email,
            ]));
        } catch (\Exception $e) {
            Craft::warning('Angie Chat: Error checking abandoned cart: ' . $e->getMessage(), __METHOD__);
        }
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
            /** @var PayloadBuilder $payloadBuilder */
            $payloadBuilder = $this->payload;

            $payload = $payloadBuilder->buildFromEntry($entry);
            $payload['action'] = $action;

            Craft::$app->getQueue()->push(new SyncElementJob([
                'payload' => $payload,
                'action' => $action,
            ]));

            Craft::info("Angie Chat: Queued {$action} for entry #{$entry->id}", __METHOD__);
        } catch (\Exception $e) {
            // Never let our plugin crash the user's save operation
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

    private function isCommerceInstalled(): bool
    {
        return Craft::$app->getPlugins()->isPluginInstalled('commerce')
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
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
}
