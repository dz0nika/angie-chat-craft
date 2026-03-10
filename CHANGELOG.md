# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **BREAKING:** Namespace changed from `nikolapopovic\angiechat` to `Dz0nika\AngieChatCraft` to align with the Composer package name `dz0nika/angie-chat-craft`. If you have extended any plugin classes, update your `use` statements accordingly.

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
