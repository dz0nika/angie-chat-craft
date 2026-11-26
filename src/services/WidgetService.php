<?php

namespace Dz0nika\AngieChatCraft\services;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use Dz0nika\AngieChatCraft\AngieChat;
use Dz0nika\AngieChatCraft\models\Settings;

/**
 * Widget Service - Handles frontend chat widget injection.
 *
 * This service determines if/when to inject the widget script tag
 * and provides the necessary configuration for the Svelte widget.
 */
class WidgetService extends Component
{
    /**
     * Render the widget script tag for injection.
     */
    public function renderWidgetScript(): string
    {
        try {
            if (! $this->shouldInjectWidget()) {
                return '';
            }

            $settings = $this->getSettings();

            if (! $settings) {
                return '';
            }

            // Emit the BROWSER-SAFE widget public key, NOT the secret
            // license key. The widget key is domain-locked server-side so
            // a scraper copying it gets nothing useful — they'd still hit
            // the registered domain check. The secret license key NEVER
            // leaves the server (used only for outbound webhooks).
            //
            // No widget public key: no widget. Falling back to the license
            // key would print a server secret into every page.
            $widgetCredential = $settings->getWidgetPublicKey();
            if ($widgetCredential === '') {
                Craft::warning('Angie Chat: Widget Public Key missing, widget not rendered.', __METHOD__);

                return '';
            }

            $attributes = [
                'src'             => $this->versionedWidgetUrl($settings->widgetUrl),
                'data-widget-key' => $widgetCredential,
                'async'           => true,
            ];

            // Pass exclude selectors to the widget JS for client-side evaluation.
            // The widget reads data-exclude and calls document.querySelector() on
            // each selector – if a match is found the widget stays hidden.
            if (! empty($settings->excludeSelectors)) {
                $attributes['data-exclude'] = $settings->excludeSelectors;
            }

            if ($settings->isTestMode()) {
                $attributes['data-test-mode'] = 'true';
            }

            // "Add to cart" on the chat's product cards posts to Commerce's own
            // cart action from the shopper's browser: same origin, so their
            // cart session and CSRF token just work. Paths, not absolute URLs,
            // so a site served on another host than its base URL still works.
            // Under the current site's base URL ("/en/actions/…"), like a cart
            // form on that page: actionUrl() always uses the primary site, and
            // a new cart would belong to it (emails and errors in its language).
            if (Craft::$app->getPlugins()->isPluginEnabled('commerce')) {
                $path = fn (string $url) => parse_url($url, PHP_URL_PATH) . (($q = parse_url($url, PHP_URL_QUERY)) ? "?{$q}" : '');
                $action = fn (string $route) => $path(UrlHelper::siteUrl(Craft::$app->getConfig()->getGeneral()->actionTrigger . '/' . $route));
                $attributes['data-cart-add'] = $action('commerce/cart/update-cart');
                $attributes['data-session-info'] = $action('users/session-info');
                $cartPage = \craft\commerce\Plugin::getInstance()?->getSettings()->loadCartRedirectUrl;
                if ($cartPage) {
                    $attributes['data-cart-page'] = $path(UrlHelper::siteUrl($cartPage));
                }
            }

            $defaultApi = 'https://app.angiechat.com';
            if ($settings->apiEndpoint !== '' && rtrim($settings->apiEndpoint, '/') !== $defaultApi) {
                $attributes['data-api'] = rtrim($settings->apiEndpoint, '/');
            }

            $attributeString = $this->buildAttributeString($attributes);

            return "\n<script {$attributeString}></script>\n";
        } catch (\Exception $e) {
            // Never crash the frontend
            return '';
        }
    }

    /**
     * Determine if the widget should be injected on the current page.
     */
    public function shouldInjectWidget(): bool
    {
        try {
            $settings = $this->getSettings();

            if (! $settings || empty($settings->getLicenseKey())) {
                return false;
            }

            if (! $settings->autoInjectWidget) {
                return false;
            }

            // Safely check request type with try-catch
            $request = Craft::$app->getRequest();

            if ($request->getIsConsoleRequest()) {
                return false;
            }

            if ($request->getIsCpRequest()) {
                return false;
            }

            if ($request->getIsAjax()) {
                return false;
            }

            if (! $request->getIsSiteRequest()) {
                return false;
            }

            if ($this->isExcludedByUrl()) {
                return false;
            }

            // Usage gate: don't inject the widget if the backend has signalled
            // we're over quota. Fails open if the backend is unreachable so a
            // SaaS outage never takes down the customer's frontend.
            //
            // The backend would also 429 the chat call itself, so even if a
            // visitor somehow already has the widget loaded from a cached
            // page, sending a message is still blocked server-side. This is
            // the preventative layer.
            if (AngieChat::$plugin && ! AngieChat::$plugin->getUsage()->shouldAllowWidget()) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            // If anything fails, don't inject the widget
            return false;
        }
    }

    /**
     * Server-side URL exclusion: checks whether the current request path
     * matches any path pattern in the exclude list.
     *
     * CSS selectors (e.g. ".no-chat", "#checkout") are passed through to the
     * widget via data-exclude and evaluated client-side by the widget JS.
     * Only patterns that start with "/" are treated as URL path prefixes here.
     *
     * Example settings value: ".no-chat, /checkout, /account"
     * → ".no-chat" → skipped here, handled by widget JS
     * → "/checkout" → excluded if the current URL path starts with /checkout
     * → "/account"  → excluded if the current URL path starts with /account
     */
    private function isExcludedByUrl(): bool
    {
        $settings = $this->getSettings();

        if (empty($settings->excludeSelectors)) {
            return false;
        }

        try {
            $currentPath = Craft::$app->getRequest()->getPathInfo();
            $currentPath = '/' . ltrim($currentPath, '/');

            $patterns = array_map('trim', explode(',', $settings->excludeSelectors));

            foreach ($patterns as $pattern) {
                if (empty($pattern) || $pattern[0] !== '/') {
                    continue; // CSS selector – handled client-side
                }

                $normalised = rtrim($pattern, '/');

                if ($normalised === '' || $normalised === '/') {
                    if ($currentPath === '/') {
                        return true;
                    }
                    continue;
                }

                // Match exact path or any sub-path (e.g. /checkout matches /checkout/step-2)
                if ($currentPath === $normalised || str_starts_with($currentPath, $normalised . '/')) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            return false;
        }

        return false;
    }

    /**
     * Get the widget configuration for manual injection.
     */
    public function getWidgetConfig(): array
    {
        $settings = $this->getSettings();

        return [
            'widgetKey' => $settings->getWidgetPublicKey(),
            'apiEndpoint' => $settings->apiEndpoint,
            'widgetUrl' => $settings->widgetUrl,
            'testMode' => $settings->isTestMode(),
        ];
    }

    /**
     * Build HTML attribute string from array.
     */
    private function buildAttributeString(array $attributes): string
    {
        $parts = [];

        foreach ($attributes as $key => $value) {
            if ($value === true) {
                $parts[] = $key;
            } elseif ($value !== false && $value !== null) {
                $parts[] = sprintf('%s="%s"', $key, htmlspecialchars($value, ENT_QUOTES));
            }
        }

        return implode(' ', $parts);
    }

    private function getSettings(): ?Settings
    {
        if (! AngieChat::$plugin) {
            return null;
        }

        return AngieChat::$plugin->getSettings();
    }
    /**
     * Append ?v=<plugin version> so a plugin upgrade bypasses the CDN's 24 h
     * cache and loads the widget build that matches it.
     */
    private function versionedWidgetUrl(string $url): string
    {
        $version = AngieChat::$plugin?->getVersion() ?: '0';

        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
    }
}
