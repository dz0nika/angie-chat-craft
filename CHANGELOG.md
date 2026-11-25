# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Image Field** setting: pick the Assets field that holds the product photo shown on product cards in the chat. Run *Sync All Content* after changing it.

### Fixed
- **The product photo is picked correctly.** The first Assets field was used even when it held a size guide or a logo. Now the chosen Image Field, then common photo handles (`images`, `gallery`, `productPhotos`…), then any other Assets field not named like a size guide, logo or download. Only images count. Products without one use their default variant's image.

## [1.1.3] - 2026-11-25

### Fixed
- **Every language is synced.** Stores with several sites (e.g. French and English) now sync each language version of entries and products, so the assistant answers English shoppers with English content and `/en/` links. Force Sync, sold-out and price refreshes, and deletes cover all sites.
- **Content in nested blocks is synced.** Text inside Matrix blocks nested in other blocks (page builders), Categories, Tags and other relation fields used to arrive as `craft\elements\db\ElementQuery`. They now send their text or titles. Number and Money fields are sent with their field name ("Poids (kg): 2.5"). Users' profile fields are never read.
- Line breaks between fields are kept, so words no longer run together ("Bottesdefault").
- A broken third-party field can no longer stop an entry from syncing.
- **Scheduled, disabled and expired entries are no longer indexed** (a Black Friday promo prepared ahead of time could be quoted by the assistant early). Entries are synced automatically when their post date arrives and removed when they expire, checked every 5 minutes on normal site traffic. *Force Sync All Data* also removes entries that are not live.
- **Singles can be selected** under Content Sections (shipping, returns and FAQ pages are often Singles).
- **Sync jobs retry** after a rate limit (429), a server error (5xx) or a network failure: up to 5 attempts, 2 minutes apart. Invalid keys or payloads fail right away.
- **The license key is never printed into pages.** Without a Widget Public Key the widget is simply not rendered (it used to fall back to the secret license key), and `craft.angieChat.widgetConfig` no longer contains it.

### Added
- **Environment variables for the keys.** License Key, Widget Public Key and Webhook Secret accept `$ENV_VAR` values (the fields suggest your variables), so staging and production each use their own keys and secrets stay out of project config.

### Upgrade
Update the plugin, move the keys to `.env` and set the fields to `$ANGIE_LICENSE_KEY`, `$ANGIE_WIDGET_KEY` and `$ANGIE_WEBHOOK_SECRET`, then run **Force Sync All Data** once so existing content is re-sent per language.

## [1.1.2] - 2026-11-24

### Fixed
- **Clear error when a request is rejected.** A 401 now shows the backend's reason (for example a missing or wrong webhook secret) instead of always saying "Invalid or expired license key".
- **Plugin log lines are signed.** Sites that require signed webhooks rejected the plugin's log posts, so they never reached the Angie Chat dashboard.

## [1.1.1] - 2026-11-24

### Fixed
- **Storefront pages no longer wait on the Angie Chat API.** The widget's quota check now reads only the cached usage status (refreshed by queue jobs). Before, an unreachable or slow backend made every page wait for the API timeout (measured: 10 s per page view).

## [1.1.0] - 2026-11-24

### Added
- **Craft Commerce product sync**: pick product types under *Commerce Products* in the plugin settings (or `enabledProductTypes` in `config/angie-chat.php`). Each product is sent with its own fields plus one line per variant: name, SKU, price, sale price and stock status. Saves and deletes sync automatically; *Force Sync All Data* and `php craft angie-chat/sync/all` include products.
- **Sold-out refresh**: when a completed order leaves a variant out of stock, its product is re-synced so the assistant stops offering it.
- **Stock edit refresh**: setting or adjusting stock by hand (Commerce → Inventory, imports) re-syncs the product right away.
- **Automatic price/schedule refresh**: when a catalog pricing rule or sale starts, ends, is edited or deleted, or a product hits its post/expiry date, the affected products are re-synced within 5 minutes. No cron: the check runs on normal site traffic, at most once per 5 minutes, and queues only what changed.

- Products that are disabled, expired or not yet live are removed from the AI index when saved.

## [1.0.9] - 2026-09-10

### Added
- **Cart snapshot for the widget**: the shopper's live Commerce cart (items, unit and sale prices, per-item discounts, subtotal, discount total, total, cart URL) is injected into every page as `<script type="application/json" id="angie-cart">`. The widget uses it for exit-intent rescue ("leave now and you'll lose $X in discounts") and the assistant can answer questions about the cart. Requires Craft Commerce; silently absent otherwise.
- **Return-to-cart links** now use Commerce's `commerce/cart/load-cart` action, so a recovery email opened on another device restores the cart; set `loadCartRedirectUrl` in `config/commerce.php` to your cart page.
- **Versioned widget URL**: `?v=<plugin version>` is appended to the widget script tag so upgrades bypass the CDN's 24 h cache.

### Fixed
- Abandoned-cart cron: the cart link no longer assumes the cart page lives at `/shop/cart`.

## [1.0.8] - 2026-06-23

### Changed
- Better usage tracking, interface image rendering fixes, RAG service optimisations.

## [1.0.7] - 2026-03-19

### Added
- **Order-placed webhook**: New `OrderPlacedJob` notifies the backend when a Craft Commerce order is completed, marking the abandoned cart as recovered and preventing recovery emails from being sent to customers who already purchased.
- **Commerce event listeners**: The plugin now listens for `EVENT_AFTER_ORDER_AUTHORIZED` (Craft Commerce 4/5) or `EVENT_AFTER_COMPLETE_ORDER` (older versions) to automatically detect completed orders.
- **`orderPlaced()` API method**: New method on `ApiService` to call `POST /api/v1/craft/order-placed`.
- **Cart URL in abandoned cart payload**: `AbandonedCartJob` now includes a `cart_url` field (e.g. `/shop/cart?number=abc123`) so recovery emails can link shoppers directly back to their cart.

### Changed
- **BREAKING:** Namespace changed from `nikolapopovic\angiechat` to `Dz0nika\AngieChatCraft` to align with the Composer package name `dz0nika/angie-chat-craft`. If you have extended any plugin classes, update your `use` statements accordingly.
- Updated author and support email addresses in `composer.json`.

## [1.0.0] - 2026-03-04

### Added
- Initial release
- Automatic content sync on entry save/delete
- Support for all field types including Matrix (nested entries in Craft 5)
- Primary image extraction from asset fields
- Background queue processing for zero-latency saves
- Settings page with section selector
- Force Sync All button for initial data seeding
- Automatic chat widget injection on frontend pages
- Craft Commerce abandoned cart integration (conditional)
- Comprehensive error handling - never crashes client websites
- Test mode support for development environments

### Security
- License key authentication via X-Craft-License header
- No sensitive data stored in plugin
- All API calls use HTTPS
