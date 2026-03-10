<?php

namespace Dz0nika\AngieChatCraft\services;

use Craft;
use craft\base\Component;
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

            $attributes = [
                'src'          => $settings->widgetUrl,
                'data-license' => $settings->licenseKey,
                'data-api'     => $settings->apiEndpoint,
                'defer'        => true,
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

            if (! $settings || empty($settings->licenseKey)) {
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
            'licenseKey' => $settings->licenseKey,
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
}
