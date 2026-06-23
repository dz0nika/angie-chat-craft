<?php

namespace Dz0nika\AngieChatCraft\models;

use craft\base\Model;

/**
 * Plugin settings model.
 *
 * Stores the license key and configuration for which Craft sections
 * should be synced to the Angie Chat AI engine.
 */
class Settings extends Model
{
    /**
     * The license key from the Angie Chat dashboard. Server-only secret —
     * NEVER emitted in HTML or sent to the browser. Sent as X-Craft-License
     * header on outbound webhooks to the backend.
     *
     * Format: sk_angie_live_... or sk_angie_test_...
     */
    public string $licenseKey = '';

    /**
     * Browser-safe widget public key from the Angie Chat dashboard. THIS is
     * what gets embedded in the page as data-widget-key="pk_widget_..." and
     * is domain-locked server-side so possession alone grants no write access.
     */
    public string $widgetPublicKey = '';

    /**
     * Per-tenant HMAC secret from the Angie Chat dashboard. Used by
     * ApiService to sign every outbound webhook body so the backend can
     * verify the call really came from this specific Craft install (not a
     * scraped license key being replayed).
     *
     * Stored encrypted in the Craft control panel using Craft's secret
     * Twig env reference whenever possible — see settings.twig for the
     * field type. Format: whk_... + 48 random chars.
     */
    public string $webhookSecret = '';

    /**
     * Array of section handles that should be synced to the AI.
     * e.g., ['products', 'articles', 'services']
     */
    public array $enabledSections = [];

    /**
     * Whether to enable abandoned cart tracking (requires Craft Commerce).
     */
    public bool $enableAbandonedCart = false;

    /**
     * The API endpoint URL for the Angie Chat backend.
     * Can be overridden for testing/staging environments.
     */
    public string $apiEndpoint = 'https://app.angiechat.com';

    /**
     * Server-side API URL for Craft → Laravel calls (webhooks, usage, sync).
     * In Docker, set this to http://host.docker.internal:8080 while keeping
     * apiEndpoint as http://localhost:8080 for the browser widget.
     */
    public string $serverApiEndpoint = '';

    /**
     * The CDN URL for the widget JavaScript.
     */
    public string $widgetUrl = 'https://cdn.angiechat.com/widget.js';

    /**
     * Whether to inject the widget script automatically.
     */
    public bool $autoInjectWidget = true;

    /**
     * CSS selector to exclude widget from certain pages (comma-separated).
     * e.g., '.no-chat, #admin-page'
     */
    public string $excludeSelectors = '';

    public function defineRules(): array
    {
        return [
            [['licenseKey', 'widgetPublicKey', 'webhookSecret'], 'string', 'max' => 200],
            [['apiEndpoint', 'serverApiEndpoint', 'widgetUrl'], 'url'],
            [['enabledSections'], 'each', 'rule' => ['string']],
            [['enableAbandonedCart', 'autoInjectWidget'], 'boolean'],
            [['excludeSelectors'], 'string', 'max' => 500],
        ];
    }

    /**
     * Check if the plugin is properly configured.
     *
     * The widget public key is required for the chat widget to render;
     * the webhook secret is checked separately by isFullyConfigured() so
     * existing installs can keep syncing during the grace period.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->licenseKey)
            && ! empty($this->widgetPublicKey)
            && ! empty($this->enabledSections);
    }

    /**
     * True only when all three credentials (license, widget key, webhook
     * secret) are present. ApiService::post will warn (not fail) if the
     * webhook secret is missing so existing installs aren't broken by the
     * upgrade — backend has a matching grace period.
     */
    public function isFullyConfigured(): bool
    {
        return $this->isConfigured() && ! empty($this->webhookSecret);
    }

    /**
     * Check if this is a test license key.
     */
    public function isTestMode(): bool
    {
        return str_contains($this->licenseKey, '_test_');
    }

    /**
     * Get the full API URL for a given endpoint.
     */
    public function getApiUrl(string $endpoint): string
    {
        $baseUrl = rtrim($this->serverApiEndpoint !== '' ? $this->serverApiEndpoint : $this->apiEndpoint, '/');

        return $baseUrl . '/api/v1/craft/' . ltrim($endpoint, '/');
    }
}
