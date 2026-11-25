<?php

namespace Dz0nika\AngieChatCraft;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\console\Application as ConsoleApplication;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use Dz0nika\AngieChatCraft\jobs\LogJob;
use Dz0nika\AngieChatCraft\jobs\OrderPlacedJob;
use Dz0nika\AngieChatCraft\jobs\SyncElementJob;
use Dz0nika\AngieChatCraft\models\Settings;
use Dz0nika\AngieChatCraft\services\ApiService;
use Dz0nika\AngieChatCraft\services\CartSnapshotService;
use Dz0nika\AngieChatCraft\services\PayloadBuilder;
use Dz0nika\AngieChatCraft\services\UsageService;
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
            'productTypes'    => $this->getAvailableProductTypes(),
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
            'usage' => UsageService::class,
            'cartSnapshot' => CartSnapshotService::class,
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

        if (empty($settings->getLicenseKey())) {
            Craft::warning('Angie Chat: No license key configured. Plugin is dormant.', __METHOD__);

            return;
        }

        $this->registerEntryEvents();
        $this->registerProductEvents();
        $this->registerWidgetInjection();
        $this->registerCommerceEvents();
        // Note: abandoned cart detection is handled via the console command:
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

                $this->dispatchForAllSites($entry, 'delete');
            }
        );

        // Entries go live or expire on a clock, not on a save (same approach
        // as products: piggyback on normal traffic, no cron).
        if (! Craft::$app->getRequest()->getIsConsoleRequest()) {
            Craft::$app->on(\yii\base\Application::EVENT_AFTER_REQUEST, function () use ($enabledSections) {
                $this->syncScheduledEntryChanges($enabledSections);
            });
        }

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
                    $script = $widgetService->renderWidgetScript();
                    if ($script !== '') {
                        // Cart JSON first so it is in the DOM before the
                        // widget script (async) can possibly execute.
                        echo $this->cartSnapshot->render();
                    }
                    echo $script;
                } catch (\Exception $e) {
                    // Silently fail - never crash the client's frontend
                    Craft::warning('Angie Chat: Failed to render widget: ' . $e->getMessage(), __METHOD__);
                }
            }
        );
    }

    private function registerProductEvents(): void
    {
        $types = $this->getSettings()->enabledProductTypes ?? [];

        if ($types === [] || ! $this->commerceEnabled()) {
            return;
        }

        Event::on(Product::class, Element::EVENT_AFTER_SAVE, function (ModelEvent $event) use ($types) {
            $product = $event->sender;

            // A save propagates to every site and fires once per site; each
            // language version is indexed on its own (backend keys by site).
            if ($this->shouldSyncProduct($product, $types)) {
                $this->dispatchSyncJob($product, 'upsert');
            }
        });

        Event::on(Product::class, Element::EVENT_AFTER_DELETE, function (Event $event) use ($types) {
            if ($this->shouldSyncProduct($event->sender, $types)) {
                $this->dispatchForAllSites($event->sender, 'delete');
            }
        });

        // Selling out does not re-save the product, so refresh products whose
        // purchased variant just ran out. Commerce updates inventory before
        // this event fires (Order::afterOrderComplete).
        Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, function (Event $event) use ($types) {
            try {
                $soldOut = [];

                foreach ($event->sender->getLineItems() as $lineItem) {
                    // Fresh load: the line item's purchasable caches pre-order stock.
                    $variant = Variant::find()
                        ->id($lineItem->purchasableId)
                        ->siteId(Craft::$app->getSites()->getPrimarySite()->id)
                        ->status(null)
                        ->one();
                    $product = $variant?->getOwner();

                    if ($product && ! $variant->hasStock() && $this->shouldSyncProduct($product, $types)) {
                        $soldOut[$product->id] = $product;
                    }
                }

                foreach ($soldOut as $product) {
                    $this->dispatchForAllSites($product, 'upsert');
                }
            } catch (\Throwable $e) {
                Craft::warning('Angie Chat: Sold-out refresh failed: ' . $e->getMessage(), __METHOD__);
            }
        });

        // Manual stock edits (Commerce → Inventory, imports) don't re-save the
        // product either. Orders take a different path (inventory movements),
        // so this fires only for hand edits, not on every sale.
        Event::on(\craft\commerce\services\Inventory::class, \craft\commerce\services\Inventory::EVENT_AFTER_EXECUTE_UPDATE_INVENTORY_LEVEL, function (Event $event) use ($types) {
            try {
                $this->queueProductsForPurchasables($types, (new Query())
                    ->select('purchasableId')
                    ->from('{{%commerce_inventoryitems}}')
                    ->where(['id' => $event->updateInventoryLevel->inventoryItemId])
                    ->column());
            } catch (\Throwable $e) {
                Craft::warning('Angie Chat: Stock edit refresh failed: ' . $e->getMessage(), __METHOD__);
            }
        });

        // Deleting a pricing rule or sale only cascades rows away; no price
        // regeneration follows, so the schedule check below would miss it.
        Event::on(\craft\commerce\records\CatalogPricingRule::class, \yii\db\BaseActiveRecord::EVENT_BEFORE_DELETE, function (Event $event) use ($types) {
            try {
                $this->queueProductsForPurchasables($types, (new Query())
                    ->select('purchasableId')->distinct()
                    ->from('{{%commerce_catalogpricing}}')
                    ->where(['catalogPricingRuleId' => $event->sender->id])
                    ->column());
            } catch (\Throwable $e) {
                Craft::warning('Angie Chat: Pricing rule delete refresh failed: ' . $e->getMessage(), __METHOD__);
            }
        });
        Event::on(\craft\commerce\records\Sale::class, \yii\db\BaseActiveRecord::EVENT_AFTER_DELETE, function () use ($types) {
            try {
                $this->queueRows('product', $this->productQuery($types));
            } catch (\Throwable $e) {
                Craft::warning('Angie Chat: Sale delete refresh failed: ' . $e->getMessage(), __METHOD__);
            }
        });

        // Promotions and scheduled products change on a clock, not on a save.
        // Piggyback on normal site/CP traffic instead of asking for a cron.
        if (! Craft::$app->getRequest()->getIsConsoleRequest()) {
            Craft::$app->on(\yii\base\Application::EVENT_AFTER_REQUEST, function () use ($types) {
                $this->syncScheduledProductChanges($types);
            });
        }
    }

    /**
     * Queue entries whose post or expiry date passed since the last check, in
     * every site: the job indexes the ones now live and removes the expired.
     * Runs at most once per 5 minutes.
     */
    public function syncScheduledEntryChanges(array $sections): int
    {
        $cache = Craft::$app->getCache();

        if (! $cache->add('angie-chat:entry-schedule-lock', 1, 300)) {
            return 0;
        }

        try {
            $now = new \DateTime();
            $since = $cache->get('angie-chat:entry-schedule-checked-at') ?: (clone $now)->modify('-1 hour');
            $from = Db::prepareDateForDb($since);
            $to = Db::prepareDateForDb($now);
            $crossed = fn (string $col) => ['and', ['>', $col, $from], ['<=', $col, $to]];

            $queued = $this->queueRows('entry', Entry::find()
                ->section($sections)
                ->siteId('*')
                ->status(null)
                ->andWhere(['or', $crossed('entries.postDate'), $crossed('entries.expiryDate')]));

            $cache->set('angie-chat:entry-schedule-checked-at', $now);

            if ($queued > 0) {
                Craft::info("Angie Chat: Re-syncing {$queued} entries after post/expiry dates passed", __METHOD__);
            }

            return $queued;
        } catch (\Throwable $e) {
            Craft::warning('Angie Chat: Entry schedule check failed: ' . $e->getMessage(), __METHOD__);

            return 0;
        }
    }

    /**
     * Re-sync products whose price or visibility changed without a save:
     * a catalog pricing window opened/closed, catalog prices were regenerated
     * (rule or price edited), a legacy sale changed, or a product's post/expiry
     * date passed. Runs at most once per 5 minutes and only queues what changed.
     */
    public function syncScheduledProductChanges(array $types): int
    {
        $cache = Craft::$app->getCache();

        if (! $cache->add('angie-chat:schedule-check-lock', 1, 300)) {
            return 0;
        }

        try {
            $now = new \DateTime();
            $since = $cache->get('angie-chat:schedule-checked-at') ?: (clone $now)->modify('-1 hour');
            $from = Db::prepareDateForDb($since);
            $to = Db::prepareDateForDb($now);
            $crossed = fn (string $col) => ['and', ['>', $col, $from], ['<=', $col, $to]];
            $pricing = '{{%commerce_catalogpricing}}';
            $sales = '{{%commerce_sales}}';

            // Regenerated prices are deleted and re-inserted, so new row ids mark
            // them. Ids, not dateUpdated: Commerce writes base rows with SQL NOW(),
            // which follows the DB server's timezone instead of Craft's UTC.
            // A full rebuild truncates the table and restarts ids (max goes down).
            $maxId = (int) (new Query())->from($pricing)->max('id');
            $lastMaxId = $cache->get('angie-chat:catalog-pricing-max-id');
            $resyncAll = $lastMaxId !== false && $maxId < $lastMaxId;

            $priceWindows = ['or', $crossed('dateFrom'), $crossed('dateTo')];
            if ($lastMaxId !== false) {
                $priceWindows[] = ['>', 'id', $lastMaxId];
            }
            $purchasableIds = (new Query())->select('purchasableId')->distinct()
                ->from($pricing)->where($priceWindows)->column();

            // Legacy sales can target categories, so any change re-syncs every product.
            $resyncAll = $resyncAll || (Craft::$app->getDb()->tableExists($sales) && (new Query())
                ->from($sales)
                ->where(['or', $crossed('dateFrom'), $crossed('dateTo'), ['>', 'dateUpdated', $from]])
                ->exists());

            if ($resyncAll) {
                $queued = $this->queueRows('product', $this->productQuery($types));
            } else {
                $queued = $this->queueProductsForPurchasables($types, $purchasableIds, $this->productQuery($types)
                    ->andWhere(['or', $crossed('commerce_products.postDate'), $crossed('commerce_products.expiryDate')])
                    ->ids());
            }

            $cache->set('angie-chat:schedule-checked-at', $now);
            $cache->set('angie-chat:catalog-pricing-max-id', $maxId);

            if ($queued > 0) {
                Craft::info("Angie Chat: Re-syncing {$queued} products after price/schedule changes", __METHOD__);
            }

            return $queued;
        } catch (\Throwable $e) {
            Craft::warning('Angie Chat: Schedule check failed: ' . $e->getMessage(), __METHOD__);

            return 0;
        }
    }

    private function queueProductsForPurchasables(array $types, array $purchasableIds, array $productIds = []): int
    {
        if ($purchasableIds !== []) {
            $productIds = array_merge($productIds, (new Query())
                ->select('primaryOwnerId')->distinct()
                ->from('{{%commerce_variants}}')
                ->where(['id' => $purchasableIds])
                ->column());
        }

        return $productIds === []
            ? 0
            : $this->queueRows('product', $this->productQuery($types)->id(array_unique($productIds)));
    }

    /** Enabled product types, one row per site (language), any status (non-live ones sync as deletes). */
    private function productQuery(array $types): ElementQuery
    {
        return Product::find()
            ->type($types)
            ->siteId('*')
            ->status(null);
    }

    private function shouldSyncProduct(Element $product, array $types): bool
    {
        return ! $product->getIsDraft()
            && ! $product->getIsRevision()
            && in_array($product->getType()->handle, $types, true);
    }

    private function commerceEnabled(): bool
    {
        return class_exists(Product::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
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

    /** One job per site the element exists in (each language is indexed separately). */
    private function dispatchForAllSites(Element $element, string $action): void
    {
        foreach (\craft\helpers\ElementHelper::supportedSitesForElement($element) as $site) {
            $this->dispatchSyncJob($element, $action, (int) $site['siteId']);
        }
    }

    private function dispatchSyncJob(Element $entry, string $action, ?int $siteId = null): void
    {
        try {
            // Phase 2: pass only lightweight identifiers. All heavy payload
            // building (Matrix traversal, image lookup) happens inside
            // SyncElementJob::execute() – fully off the request thread.
            Craft::$app->getQueue()->push(new SyncElementJob([
                'entryId'  => (int) $entry->id,
                'entryUid' => (string) $entry->uid,
                'siteId'   => $siteId ?? (int) $entry->siteId,
                'action'   => $action,
                'elementType' => $entry instanceof Entry ? 'entry' : 'product',
            ]));

            Craft::info("Angie Chat: Queued {$action} for entry #{$entry->id}", __METHOD__);
        } catch (\Exception $e) {
            Craft::error("Angie Chat: Failed to queue {$action} for entry #{$entry->id}: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Register Craft Commerce event listeners for order completion.
     * When an order is paid/completed, we notify the backend so it can mark
     * the corresponding AbandonedCart record as recovered.
     */
    private function registerCommerceEvents(): void
    {
        // Only register if Commerce is installed
        if (! Craft::$app->getPlugins()->isPluginInstalled('commerce')
            || ! Craft::$app->getPlugins()->isPluginEnabled('commerce')) {
            return;
        }

        $orderClass = 'craft\\commerce\\elements\\Order';
        if (! class_exists($orderClass)) {
            return;
        }

        // Craft Commerce 4/5: EVENT_AFTER_ORDER_AUTHORIZED fires when payment is authorised.
        // Fall back to EVENT_AFTER_COMPLETE_ORDER for older versions.
        $events = ['EVENT_AFTER_ORDER_AUTHORIZED', 'EVENT_AFTER_COMPLETE_ORDER'];

        foreach ($events as $eventConst) {
            if (! defined("{$orderClass}::{$eventConst}")) {
                continue;
            }

            Event::on(
                $orderClass,
                constant("{$orderClass}::{$eventConst}"),
                function (Event $event) {
                    /** @var \craft\commerce\elements\Order $order */
                    $order = $event->sender;

                    try {
                        $number = method_exists($order, 'getNumber') ? $order->getNumber() : ($order->number ?? null);

                        Craft::$app->getQueue()->push(new OrderPlacedJob([
                            'orderId'     => (int) $order->id,
                            'orderNumber' => $number,
                        ]));
                    } catch (\Exception $e) {
                        Craft::warning(
                            'Angie Chat: Failed to queue OrderPlacedJob: ' . $e->getMessage(),
                            __METHOD__
                        );
                    }
                }
            );

            // Only register one event constant (prefer the more specific one)
            break;
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
            // Singles included: shipping, returns and FAQ pages are often Singles.
            foreach (Craft::$app->getEntries()->getAllSections() as $section) {
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

    public function getAvailableProductTypes(): array
    {
        if (! $this->commerceEnabled()) {
            return [];
        }

        $types = [];

        try {
            foreach (\craft\commerce\Plugin::getInstance()->getProductTypes()->getAllProductTypes() as $type) {
                $types[] = [
                    'handle' => $type->handle,
                    'name' => $type->name,
                    'productCount' => Product::find()->type($type->handle)->status(null)->count(),
                ];
            }
        } catch (\Exception $e) {
            Craft::warning('Angie Chat: Failed to get product types: ' . $e->getMessage(), __METHOD__);
        }

        return $types;
    }

    /**
     * Queue every live entry in the enabled sections and every live product in
     * the enabled product types. Used by the CP button and the console command.
     *
     * @return int Number of jobs queued
     */
    public function queueFullSync(): int
    {
        $settings = $this->getSettings();

        $queued = 0;

        foreach ($settings->enabledSections ?? [] as $handle) {
            // Any status: non-live ones are sent as deletes (cleans up promos indexed before going live).
            $queued += $this->queueRows('entry', Entry::find()->section($handle)->siteId('*')->status(null));
        }

        if ($this->commerceEnabled() && ! empty($settings->enabledProductTypes)) {
            $queued += $this->queueRows('product', $this->productQuery($settings->enabledProductTypes)->status('live'));
        }

        return $queued;
    }

    private function queueRows(string $elementType, ElementQuery $query): int
    {
        // Ids only: payloads are built inside SyncElementJob on the queue worker.
        $rows = $query
            ->select(['elements.id', 'elements.uid', 'elements_sites.siteId'])
            ->asArray()
            ->all();

        foreach ($rows as $row) {
            Craft::$app->getQueue()->push(new SyncElementJob([
                'entryId'     => (int) $row['id'],
                'entryUid'    => (string) $row['uid'],
                'siteId'      => (int) $row['siteId'],
                'action'      => 'upsert',
                'elementType' => $elementType,
            ]));
        }

        return count($rows);
    }

    public function getSyncStatus(): array
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        if (empty($settings->getLicenseKey())) {
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

    public function getUsage(): UsageService
    {
        return $this->usage;
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
            if (empty($settings->getLicenseKey())) {
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
