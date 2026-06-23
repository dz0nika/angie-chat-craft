<?php

namespace Dz0nika\AngieChatCraft\services;

use Craft;
use craft\base\Component;
use Dz0nika\AngieChatCraft\AngieChat;

/**
 * Usage Service — caches the backend's UsageStatus snapshot so plugin code
 * can quickly decide whether the chat surface (widget + cart job + sync job)
 * is currently allowed to consume LLM compute.
 *
 * The backend is the single source of truth (see App\Services\UsageEnforcer
 * in angie-chat-be). This service just mirrors that snapshot locally with a
 * short TTL so we don't pay an HTTP round-trip on every page render.
 *
 * Fail-open philosophy: if the backend is unreachable or returns garbage,
 * we treat the customer as "ok" rather than blocking them. The backend's own
 * enforcement on the chat endpoint is the hard gate — this service is the
 * preventative layer that avoids wasting customer cron / queue resources.
 */
class UsageService extends Component
{
    private const CACHE_TTL_SECONDS = 300; // 5 minutes

    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_UNKNOWN = 'unknown';

    /**
     * Fetch the current usage snapshot, using a 5-minute cache.
     *
     * Shape (when known):
     *   [
     *     'status' => 'ok' | 'warning' | 'blocked',
     *     'blocking_reason' => string|null,
     *     'budgets' => [
     *       'monthly_chat_sessions' => ['used'=>int,'limit'=>int,'remaining'=>int,'percent'=>float],
     *       'monthly_tokens'        => [...],
     *     ],
     *     'reset_at' => ISO-8601 string,
     *     'warning_threshold' => float,
     *   ]
     *
     * When unreachable: ['status' => 'unknown']. Callers MUST treat unknown
     * as "allow" — we never let the plugin go down because the SaaS is down.
     */
    public function getStatus(bool $forceRefresh = false): array
    {
        $settings = $this->getSettings();

        if (! $settings || empty($settings->licenseKey)) {
            return ['status' => self::STATUS_UNKNOWN];
        }

        $cacheKey = 'angie_chat_usage_' . md5($settings->licenseKey);

        if (! $forceRefresh) {
            $cached = Craft::$app->getCache()->get($cacheKey);
            if ($cached !== false) {
                return $cached;
            }
        }

        try {
            $response = $this->getApi()->getUsageStatus();
            $usage = $response['usage'] ?? null;

            if (! is_array($usage) || ! isset($usage['status'])) {
                // Unexpected shape — fail open, don't poison cache.
                return ['status' => self::STATUS_UNKNOWN];
            }

            Craft::$app->getCache()->set($cacheKey, $usage, self::CACHE_TTL_SECONDS);

            return $usage;
        } catch (\Throwable $e) {
            // Backend unreachable — fail open, don't cache.
            Craft::warning(
                'Angie Chat: usage status fetch failed: ' . $e->getMessage(),
                __METHOD__
            );

            return ['status' => self::STATUS_UNKNOWN];
        }
    }

    public function isBlocked(): bool
    {
        return ($this->getStatus()['status'] ?? self::STATUS_UNKNOWN) === self::STATUS_BLOCKED;
    }

    public function isWarning(): bool
    {
        return ($this->getStatus()['status'] ?? self::STATUS_UNKNOWN) === self::STATUS_WARNING;
    }

    /**
     * Reason key matching App\Support\UsageStatus::REASON_* constants when
     * blocked. Useful for the CP banner and plugin logs.
     */
    public function blockingReason(): ?string
    {
        $status = $this->getStatus();

        return $status['blocking_reason'] ?? null;
    }

    /**
     * Whether the widget script should be injected on a site request, with
     * usage state taken into account. Other gates (license, settings, request
     * type, URL exclusion) are still owned by WidgetService.
     */
    public function shouldAllowWidget(): bool
    {
        // Fail open — only block when we KNOW the customer is over quota.
        return ! $this->isBlocked();
    }

    /**
     * Invalidate the local cache. Call after admin actions in the CP that
     * might change the situation (e.g. manual sync that just hit the limit).
     */
    public function forget(): void
    {
        $settings = $this->getSettings();

        if (! $settings || empty($settings->licenseKey)) {
            return;
        }

        Craft::$app->getCache()->delete('angie_chat_usage_' . md5($settings->licenseKey));
    }

    private function getApi(): ApiService
    {
        return AngieChat::$plugin->getApi();
    }

    private function getSettings(): ?\Dz0nika\AngieChatCraft\models\Settings
    {
        if (! AngieChat::$plugin) {
            return null;
        }

        return AngieChat::$plugin->getSettings();
    }
}
