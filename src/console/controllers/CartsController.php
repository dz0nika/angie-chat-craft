<?php

namespace nikolapopovic\angiechat\console\controllers;

use Craft;
use craft\console\Controller;
use nikolapopovic\angiechat\AngieChat;
use nikolapopovic\angiechat\jobs\AbandonedCartJob;
use yii\console\ExitCode;

/**
 * Abandoned cart recovery console commands.
 *
 * Schedule this via cron to detect and queue carts that shoppers left behind:
 *
 *   php craft angie-chat/carts/check-abandoned
 *
 * Recommended schedule: every 30 minutes.
 * Cron example: 0,30 * * * * /path/to/php /path/to/craft angie-chat/carts/check-abandoned
 *
 * Why a console command instead of an event listener?
 * Craft Commerce 4/5 has no "cart abandoned" event. The only native mechanism
 * (`purgeIncompleteCarts`) silently deletes old carts with zero hooks. A cron
 * command is the correct, idiomatic solution used by Commerce-integrated services.
 */
class CartsController extends Controller
{
    /**
     * How long (seconds) a cart must be inactive before it qualifies as abandoned.
     * Default: 1 hour. Override via config/angie-chat.php `abandonedCartThreshold`.
     */
    public int $threshold = 3600;

    public $defaultAction = 'check-abandoned';

    /**
     * Finds inactive Commerce carts and queues them for recovery emails.
     *
     * Usage:  php craft angie-chat/carts/check-abandoned [--threshold=3600]
     */
    public function actionCheckAbandoned(): int
    {
        if (! AngieChat::$plugin) {
            $this->stderr("Angie Chat plugin is not initialised.\n");
            return ExitCode::UNAVAILABLE;
        }

        $settings = AngieChat::$plugin->getSettings();

        if (empty($settings->licenseKey)) {
            $this->stderr("Angie Chat: No license key configured. Skipping.\n");
            return ExitCode::CONFIG;
        }

        if (! $settings->enableAbandonedCart) {
            $this->stdout("Angie Chat: Abandoned cart recovery is disabled in settings.\n");
            return ExitCode::OK;
        }

        $orderClass = 'craft\\commerce\\elements\\Order';

        if (! class_exists($orderClass)) {
            $this->stderr("Craft Commerce is not installed. Skipping.\n");
            return ExitCode::UNAVAILABLE;
        }

        // Use Commerce's activeCartDuration if accessible, otherwise use the property/config
        $threshold = $this->threshold;

        try {
            $commercePlugin = \craft\commerce\Plugin::getInstance();
            if ($commercePlugin && method_exists($commercePlugin->getSettings(), 'getActiveCartDuration')) {
                $duration = $commercePlugin->getSettings()->activeCartDuration;
                if ($duration > 0) {
                    $threshold = (int) $duration;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to default threshold
        }

        $cutoff = date('Y-m-d H:i:s', time() - $threshold);

        $this->stdout("Checking for carts inactive since {$cutoff}...\n");

        try {
            /** @var \craft\commerce\elements\db\OrderQuery $query */
            $query = $orderClass::find()
                ->isCompleted(false)
                ->email(':notempty:')
                ->dateUpdated('< ' . $cutoff)
                ->status(null);

            $orders = $query->all();
        } catch (\Throwable $e) {
            $this->stderr("Angie Chat: Failed to query orders: {$e->getMessage()}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $queued = 0;
        $skipped = 0;

        foreach ($orders as $order) {
            // Must have at least one line item – empty carts aren't worth recovering
            $lineItems = method_exists($order, 'getLineItems') ? $order->getLineItems() : [];
            if (empty($lineItems)) {
                $skipped++;
                continue;
            }

            $email = method_exists($order, 'getEmail') ? $order->getEmail() : ($order->email ?? null);
            if (empty($email)) {
                $skipped++;
                continue;
            }

            // Deduplicate: cache for 7 days so repeated cron runs don't re-queue
            $cacheKey = 'angie_abandoned_' . $order->id;
            if (Craft::$app->getCache()->get($cacheKey)) {
                $skipped++;
                continue;
            }

            Craft::$app->getQueue()->push(new AbandonedCartJob([
                'orderId' => (int) $order->id,
                'email'   => (string) $email,
            ]));

            Craft::$app->getCache()->set($cacheKey, true, 7 * 24 * 3600);

            $queued++;
        }

        $this->stdout("Done: {$queued} carts queued for recovery, {$skipped} skipped.\n");

        return ExitCode::OK;
    }
}
