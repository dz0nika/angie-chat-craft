# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
