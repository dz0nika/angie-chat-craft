<?php

namespace Dz0nika\AngieChatCraft\variables;

use Dz0nika\AngieChatCraft\AngieChat;

/**
 * Twig Variable Class - Exposes plugin functionality to Twig templates.
 *
 * Usage in Twig:
 *   {{ craft.angieChat.widgetScript|raw }}
 *   {% if craft.angieChat.isConfigured %}...{% endif %}
 */
class AngieChatVariable
{
    /**
     * Get the widget script tag for manual injection.
     *
     * Usage: {{ craft.angieChat.widgetScript|raw }}
     */
    public function getWidgetScript(): string
    {
        if (! AngieChat::$plugin) {
            return '';
        }

        try {
            return AngieChat::$plugin->getWidget()->renderWidgetScript();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Check if the plugin is properly configured.
     *
     * Usage: {% if craft.angieChat.isConfigured %}...{% endif %}
     */
    public function getIsConfigured(): bool
    {
        if (! AngieChat::$plugin) {
            return false;
        }

        try {
            $settings = AngieChat::$plugin->getSettings();

            return $settings ? $settings->isConfigured() : false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if the plugin is in test mode.
     *
     * Usage: {% if craft.angieChat.isTestMode %}...{% endif %}
     */
    public function getIsTestMode(): bool
    {
        if (! AngieChat::$plugin) {
            return false;
        }

        try {
            $settings = AngieChat::$plugin->getSettings();

            return $settings ? $settings->isTestMode() : false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the widget configuration array.
     *
     * Usage: {% set config = craft.angieChat.widgetConfig %}
     */
    public function getWidgetConfig(): array
    {
        if (! AngieChat::$plugin) {
            return [];
        }

        try {
            return AngieChat::$plugin->getWidget()->getWidgetConfig();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get the license key (masked for display).
     *
     * Usage: {{ craft.angieChat.maskedLicenseKey }}
     */
    public function getMaskedLicenseKey(): string
    {
        if (! AngieChat::$plugin) {
            return '';
        }

        try {
            $settings = AngieChat::$plugin->getSettings();
            if (! $settings || empty($settings->licenseKey)) {
                return '';
            }

            $key = $settings->licenseKey;
            if (strlen($key) <= 12) {
                return str_repeat('*', strlen($key));
            }

            return substr($key, 0, 8) . '...' . substr($key, -4);
        } catch (\Exception $e) {
            return '';
        }
    }
}
