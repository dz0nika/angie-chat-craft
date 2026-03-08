<?php

/**
 * Angie Chat plugin default configuration.
 *
 * Copy this file to config/angie-chat.php in your Craft project
 * to override these settings.
 */

return [
    // Your license key from the Angie Chat dashboard
    'licenseKey' => '',

    // Section handles to sync (e.g., ['products', 'articles'])
    'enabledSections' => [],

    // Enable abandoned cart tracking (requires Craft Commerce)
    'enableAbandonedCart' => false,

    // API endpoint (override for testing/staging)
    'apiEndpoint' => 'https://app.angiechat.com',

    // Widget script URL (override for testing/staging)
    'widgetUrl' => 'https://cdn.angiechat.com/widget.js',

    // Auto-inject widget on frontend pages
    'autoInjectWidget' => true,

    // CSS selectors for pages to exclude widget from
    'excludeSelectors' => '',
];
