# Angie Chat - Craft CMS Plugin

AI-powered customer service chat widget for Craft CMS 5. This plugin connects your Craft content to the Angie Chat SaaS platform, enabling intelligent product Q&A and customer support.

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later
- An active [Angie Chat](https://angiechat.com) subscription

## Installation

### Via Composer (Recommended)

```bash
composer require dz0nika/angie-chat-craft
php craft plugin/install angie-chat
```

### From the Plugin Store

Search for "Angie Chat" in the Craft Plugin Store and click Install.

## Configuration

1. **Get Your License Key**
   - Log in to your [Angie Chat Dashboard](https://app.angiechat.com)
   - Navigate to **Websites** → Select your website → **Settings**
   - Copy your **Craft License Key**

2. **Configure the Plugin**
   - In Craft, go to **Settings** → **Plugins** → **Angie Chat**
   - Paste your license key
   - Select which sections should be synced to the AI

3. **Initial Sync**
   - Click **Force Sync All Data** to send existing content to the AI
   - Future saves will sync automatically

## How It Works

### Content Sync

When you save an entry in an enabled section, the plugin:

1. Extracts text content from all fields (including Matrix blocks)
2. Strips HTML and flattens nested content
3. Extracts the primary image URL
4. Queues a background job to send data to Angie Chat

The sync happens asynchronously via Craft's queue, so your Control Panel stays fast.

### Chat Widget

The plugin automatically injects the Angie Chat widget on your frontend pages. The widget:

- Loads asynchronously (no impact on page speed)
- Uses your custom styling from the Angie Chat dashboard
- Maintains conversation context across page navigation

### Abandoned Cart Recovery (Craft Commerce)

If you have Craft Commerce installed and the Growth Tier subscription:

1. Enable "Abandoned Cart Recovery" in plugin settings
2. Add a cron job to check for abandoned carts (see [Abandoned Cart Setup](#abandoned-cart-setup))
3. When a cart is detected as abandoned, the plugin sends cart data to Angie Chat
4. The AI generates a personalized recovery email

---

## Configuration File

You can override settings via `config/angie-chat.php`:

```php
<?php

return [
    'licenseKey' => getenv('ANGIE_LICENSE_KEY'),
    'enabledSections' => ['products', 'articles'],
    'enableAbandonedCart' => true,
    'apiEndpoint' => 'https://app.angiechat.com',
    'widgetUrl' => 'https://cdn.angiechat.com/widget.js',
    'autoInjectWidget' => true,
    'excludeSelectors' => '.no-chat, #checkout',
];
```

## Manual Widget Injection

If you disable auto-injection, add the widget manually in your template:

```twig
{% if craft.app.plugins.isPluginEnabled('angie-chat') %}
    {{ craft.angieChat.widget.renderWidgetScript()|raw }}
{% endif %}
```

## Troubleshooting

### "Not Connected" Status

- Verify your license key is correct
- Check that your Angie Chat subscription is active
- Ensure your server can reach `app.angiechat.com`

### Content Not Syncing

- Check the Craft queue utility for failed jobs
- Verify the section is enabled in plugin settings
- Check Craft logs for error messages

### Widget Not Appearing

- Verify "Auto-inject Widget" is enabled
- Check if the page matches an exclude selector
- Ensure you're viewing a frontend (site) request

## Support

- Documentation: [angiechat.com](https://angiechat.com/documentation)
- Email: support@angiechat.com
- Dashboard: [app.angiechat.com](https://app.angiechat.com)

## License

MIT License - see [LICENSE](LICENSE) for details.
