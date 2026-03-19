# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
