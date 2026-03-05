<?php

namespace nikolapopovic\angiechat\models;

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
     * The license key from the Angie Chat dashboard.
     * Format: sk_craft_live_... or sk_craft_test_...
     */
    public string $licenseKey = '';

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
    public string $apiEndpoint = 'https://api.angie-chat.com';

    /**
     * The CDN URL for the widget JavaScript.
     */
    public string $widgetUrl = 'https://cdn.angie-chat.com/widget.js';

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
            [['licenseKey'], 'string', 'max' => 100],
            [['apiEndpoint', 'widgetUrl'], 'url'],
            [['enabledSections'], 'each', 'rule' => ['string']],
            [['enableAbandonedCart', 'autoInjectWidget'], 'boolean'],
            [['excludeSelectors'], 'string', 'max' => 500],
        ];
    }

    /**
     * Check if the plugin is properly configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->licenseKey) && ! empty($this->enabledSections);
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
        $baseUrl = rtrim($this->apiEndpoint, '/');

        return $baseUrl.'/api/v1/craft/'.ltrim($endpoint, '/');
    }
}
