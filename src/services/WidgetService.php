<?php

namespace nikolapopovic\angiechat\services;

use Craft;
use craft\base\Component;
use nikolapopovic\angiechat\AngieChat;
use nikolapopovic\angiechat\models\Settings;

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
                'src' => $settings->widgetUrl,
                'data-license' => $settings->licenseKey,
                'data-api' => $settings->apiEndpoint,
                'defer' => true,
            ];

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
     * Check if current URL should be excluded.
     */
    private function isExcludedByUrl(): bool
    {
        $settings = $this->getSettings();

        if (empty($settings->excludeSelectors)) {
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
