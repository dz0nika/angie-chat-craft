# Angie Chat - Craft CMS Plugin

AI-powered customer service chat widget for Craft CMS 5. This plugin connects your Craft content to the Angie Chat SaaS platform, enabling intelligent product Q&A and customer support.

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later
- An active [Angie Chat](https://angie-chat.com) subscription

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
   - Log in to your [Angie Chat Dashboard](https://app.angie-chat.com)
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

## Abandoned Cart Setup

Craft Commerce has no native "cart abandoned" event — carts are simply purged after they expire. The plugin detects abandonment via a console command that you schedule with cron.

### 1. Enable in settings

Go to **Settings → Plugins → Angie Chat** and enable "Abandoned Cart Recovery".

### 2. Add the cron job

Add this line to your server's crontab (run `crontab -e`):

```
0,30 * * * * /usr/bin/php /path/to/your-craft-site/craft angie-chat/carts/check-abandoned
```

Replace `/path/to/your-craft-site/` with the absolute path to your Craft installation.

**Recommended interval:** every 30 minutes.

The command finds carts that are:
- Not completed
- Have at least one item
- Have a customer email address
- Have been inactive longer than Commerce's `activeCartDuration` (default: 1 hour)

Each qualifying cart is queued once (deduplicated for 7 days via Craft's cache) and sent to the Angie Chat API for AI-powered recovery email generation.

### 3. Test it

```bash
php craft angie-chat/carts/check-abandoned
```

---

## Configuration File

You can override settings via `config/angie-chat.php`:

```php
<?php

return [
    'licenseKey' => getenv('ANGIE_LICENSE_KEY'),
    'enabledSections' => ['products', 'articles'],
    'enableAbandonedCart' => true,
    'apiEndpoint' => 'https://api.angie-chat.com',
    'widgetUrl' => 'https://cdn.angie-chat.com/widget.js',
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
- Ensure your server can reach `api.angie-chat.com`

### Content Not Syncing

- Check the Craft queue utility for failed jobs
- Verify the section is enabled in plugin settings
- Check Craft logs for error messages

### Widget Not Appearing

- Verify "Auto-inject Widget" is enabled
- Check if the page matches an exclude selector
- Ensure you're viewing a frontend (site) request

## Support

- Documentation: [docs.angie-chat.com](https://docs.angie-chat.com)
- Email: support@angie-chat.com
- Dashboard: [app.angie-chat.com](https://app.angie-chat.com)

## License

MIT License - see [LICENSE](LICENSE) for details.
