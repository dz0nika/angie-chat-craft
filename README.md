# Angie Chat for Craft CMS

An AI chat assistant for Craft CMS 5 and Craft Commerce 5. It answers shoppers' questions from your own content, products, prices and stock, in their language, and hands the conversation to your team when it can't help.

## What it does

- **Answers from your content.** Entries from the sections you choose (channels, structures and singles), including nested Matrix blocks, categories and tags. When the answer isn't in your content, it says so and offers to connect the shopper with your team instead of making something up.
- **Knows your catalogue.** Commerce products with every variant's price, sale price and stock, so "a men's winter coat under $500?" gets real products, prices and links.
- **Stays up to date on its own.** Saves, deletes, sold-out variants, stock edits, sales and pricing rules, and scheduled entries sync through the Craft queue. No cron to set up.
- **Speaks your shoppers' language.** Every site of a multi-site install is synced; answers follow the shopper's language and link to the matching language version. The chat window itself is available in English, French, Spanish and German, or follows the page language.
- **Human handoff.** Shoppers can ask for a person: your team gets an email and can take over the chat from the Angie Chat dashboard or reply by email. If nobody picks it up within 10 minutes, the assistant carries on.
- **Made for storefronts.** The widget loads asynchronously and never calls the API while your pages render; links from the chat open in the same tab, and the conversation follows the shopper across pages and tabs.
- **Secure by default.** Server keys stay on the server and can come from environment variables, every request is HMAC-signed, and the browser key is locked to your domain (plus localhost for development).
- **Insights.** See the questions it couldn't answer and teach it the answer once: it is used whatever language the shopper asks in. Agencies can give each client a login limited to their own websites, to read conversations and answer those questions themselves.
- **Optional:** abandoned cart recovery emails (Craft Commerce, Craft Pro).

## Requirements

- Craft CMS 5.0 or later and PHP 8.2 or later
- Craft Commerce 5 for product sync (optional)
- An [Angie Chat](https://angiechat.com) account
- Cart recovery additionally needs Craft Pro and the *Abandoned Cart Recovery* add-on

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
   - Open **Connection & Syncing** and copy the **License Key**, **Widget Public Key** and **Webhook Secret**

2. **Configure the Plugin**
   - In Craft, go to **Settings** → **Plugins** → **Angie Chat**
   - Paste your license key
   - Select which sections should be synced to the AI
   - On Craft Commerce sites, select which product types should be synced

3. **Initial Sync**
   - Click **Force Sync All Data** to send existing content to the AI
   - Future saves will sync automatically

## How It Works

### Content Sync

When you save an entry in an enabled section, the plugin:

1. Extracts text content from all fields (including Matrix blocks)
2. Strips HTML and flattens nested content
3. Finds the product or entry photo (photo fields first, then variants and blocks; size charts,
   logos and downloads are skipped). Pick the field yourself under **Image Field** if needed
4. Queues a background job to send data to Angie Chat

The sync happens asynchronously via Craft's queue, so your Control Panel stays fast.

Commerce products in the selected product types sync the same way, with one line per
variant (name, SKU, price, sale price, stock status). Changes that don't re-save a product
are picked up automatically, with no cron needed:

- an order sells out a variant → that product is re-synced right away;
- stock is edited by hand (Commerce → Inventory, imports) → that product is re-synced right away;
- a catalog pricing rule or sale starts, ends, is edited or deleted, or a product reaches
  its post/expiry date → affected products are re-synced within 5 minutes (checked on
  normal site traffic, at most once per 5 minutes).

### Chat Widget

The plugin automatically injects the Angie Chat widget on your frontend pages. The widget:

- Loads asynchronously (no impact on page speed)
- Uses your custom styling from the Angie Chat dashboard
- Maintains conversation context across page navigation

### Cart Recovery (Craft Commerce)

Requires Craft Commerce and the *Abandoned Cart Recovery* add-on. Three layers, all
driven by the shopper's real Commerce cart:

1. **Exit-intent rescue** — the plugin injects the live cart (prices, discounts,
   totals) into every page; when a shopper with a non-empty cart moves to leave, the
   widget opens and tells them what they'd lose. Toggle and message: dashboard →
   Websites → Cart Recovery. Nothing to configure in Craft.
2. **Recovery emails** — enable "Abandoned Cart Recovery" in the plugin settings and
   add the cron job (see [Abandoned Cart Setup](src/docs/abandoned-cart.md)). Abandoned
   carts with an email get an AI-written recovery email and a 24 h follow-up. The
   "complete your order" link uses Commerce's `commerce/cart/load-cart` action, so it
   works from any device; set `loadCartRedirectUrl` in `config/commerce.php` to your
   cart page so shoppers land there.
3. **Webhooks to your own system** — instead of (or as well as) our emails, receive
   signed `cart.exit_intent` / `cart.abandoned` events at your URL and run your own
   workflow. Set up in the dashboard; spec at
   [docs/cart-webhooks](https://docs.angiechat.com/cart-webhooks).

---

## Configuration File

Keep the keys in each environment's `.env` and reference them from the plugin settings as
`$ANGIE_LICENSE_KEY`, `$ANGIE_WIDGET_KEY` and `$ANGIE_WEBHOOK_SECRET` (the fields suggest your
environment variables). Typed keys are stored in project config, so a staging key would reach
production with the next deploy and production content would be synced into the staging website.

```dotenv
ANGIE_LICENSE_KEY=sk_angie_live_...
ANGIE_WIDGET_KEY=pk_widget_...
ANGIE_WEBHOOK_SECRET=whk_...
```

You can also override settings via `config/angie-chat.php`:

```php
<?php

return [
    'licenseKey' => '$ANGIE_LICENSE_KEY',
    'widgetPublicKey' => '$ANGIE_WIDGET_KEY',
    'webhookSecret' => '$ANGIE_WEBHOOK_SECRET',
    'enabledSections' => ['products', 'articles'],
    'enableAbandonedCart' => true,
    'apiEndpoint' => 'https://app.angiechat.com',
    'widgetUrl' => 'https://cdn.angiechat.com/widget.js',
    'autoInjectWidget' => true,
    'excludeSelectors' => '.no-chat, #checkout',
    'imageField' => '', // optional: Assets field handle for product photos
];
```

## Manual Widget Injection

If you disable auto-injection, add the widget manually in your template:

```twig
{% if craft.app.plugins.isPluginEnabled('angie-chat') %}
    {{ craft.angieChat.widgetScript|raw }}
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
- Email: info@angiechat.com
- Dashboard: [app.angiechat.com](https://app.angiechat.com)

## License

MIT License - see [LICENSE](LICENSE) for details.
