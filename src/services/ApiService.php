<?php

namespace nikolapopovic\angiechat\services;

use Craft;
use craft\base\Component;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use nikolapopovic\angiechat\AngieChat;
use nikolapopovic\angiechat\models\Settings;

/**
 * API Service - Handles all HTTP communication with the Laravel backend.
 *
 * This service wraps Guzzle and manages:
 * - Authentication via X-Craft-License header
 * - Request/response handling
 * - Error handling and logging
 */
class ApiService extends Component
{
    private ?Client $client = null;

    public function init(): void
    {
        parent::init();

        $this->client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'AngieChatCraft/1.0',
            ],
        ]);
    }

    /**
     * Send content to be upserted in the vector database.
     */
    public function upsert(array $payload): array
    {
        return $this->post('upsert', $payload);
    }

    /**
     * Send a delete request to remove content from the vector database.
     */
    public function delete(array $payload): array
    {
        return $this->post('delete', $payload);
    }

    /**
     * Bulk sync multiple entries at once.
     */
    public function bulkSync(array $entries): array
    {
        return $this->post('bulk-sync', ['entries' => $entries]);
    }

    /**
     * Send abandoned cart data for recovery processing.
     */
    public function abandonedCart(array $payload): array
    {
        return $this->post('abandoned-cart', $payload);
    }

    /**
     * Check connection status with the backend.
     */
    public function checkStatus(): array
    {
        try {
            $response = $this->get('status');

            return [
                'connected'        => true,
                'message'          => 'Connected to Angie Chat',
                'data'             => $response,
                'enabled_features' => $response['enabled_features'] ?? [],
            ];
        } catch (\Exception $e) {
            return [
                'connected'        => false,
                'message'          => $e->getMessage(),
                'data'             => null,
                'enabled_features' => [],
            ];
        }
    }

    /**
     * Return the feature slugs active for this license, cached for 5 minutes.
     *
     * Uses the /status endpoint which already returns enabled_features, so no
     * extra HTTP round-trip is needed.
     *
     * @return string[]
     */
    public function getEnabledFeatures(): array
    {
        $cacheKey = 'angie_chat_enabled_features_' . md5($this->getSettings()->licenseKey);
        $cached   = Craft::$app->getCache()->get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        try {
            $response = $this->get('status');
            $features = $response['enabled_features'] ?? [];
            Craft::$app->getCache()->set($cacheKey, $features, 300); // 5 min TTL
            return $features;
        } catch (\Exception $e) {
            // Backend unreachable – return empty but don't cache so we retry next time
            return [];
        }
    }

    /**
     * Check whether a specific feature slug is active for this license.
     */
    public function hasFeature(string $slug): bool
    {
        return in_array($slug, $this->getEnabledFeatures(), true);
    }

    /**
     * Make a POST request to the API.
     */
    private function post(string $endpoint, array $data): array
    {
        $settings = $this->getSettings();
        $url = $settings->getApiUrl($endpoint);

        try {
            $response = $this->client->post($url, [
                'headers' => $this->getAuthHeaders(),
                'json' => $data,
            ]);

            return $this->handleResponse($response, $endpoint);
        } catch (RequestException $e) {
            return $this->handleException($e, $endpoint);
        } catch (GuzzleException $e) {
            Craft::error("Angie Chat API error ({$endpoint}): ".$e->getMessage(), __METHOD__);

            throw new \RuntimeException('Failed to connect to Angie Chat API: '.$e->getMessage());
        }
    }

    /**
     * Make a GET request to the API.
     */
    private function get(string $endpoint): array
    {
        $settings = $this->getSettings();
        $url = $settings->getApiUrl($endpoint);

        try {
            $response = $this->client->get($url, [
                'headers' => $this->getAuthHeaders(),
            ]);

            return $this->handleResponse($response, $endpoint);
        } catch (RequestException $e) {
            return $this->handleException($e, $endpoint);
        } catch (GuzzleException $e) {
            Craft::error("Angie Chat API error ({$endpoint}): ".$e->getMessage(), __METHOD__);

            throw new \RuntimeException('Failed to connect to Angie Chat API: '.$e->getMessage());
        }
    }

    /**
     * Get authentication headers.
     */
    private function getAuthHeaders(): array
    {
        $settings = $this->getSettings();

        return [
            'X-Craft-License' => $settings->licenseKey,
        ];
    }

    /**
     * Handle API response.
     */
    private function handleResponse($response, string $endpoint): array
    {
        $statusCode = $response->getStatusCode();
        $body = json_decode($response->getBody()->getContents(), true) ?? [];

        if ($statusCode === 401) {
            Craft::error('Angie Chat: Invalid or expired license key', __METHOD__);

            throw new \RuntimeException('Invalid or expired license key. Please check your Angie Chat dashboard.');
        }

        if ($statusCode === 403) {
            $message = $body['message'] ?? 'Access forbidden';
            Craft::warning("Angie Chat API forbidden ({$endpoint}): {$message}", __METHOD__);

            throw new \RuntimeException($message);
        }

        if ($statusCode >= 400) {
            $message = $body['message'] ?? 'API request failed';
            Craft::error("Angie Chat API error ({$endpoint}): {$message}", __METHOD__);

            throw new \RuntimeException($message);
        }

        return $body;
    }

    /**
     * Handle request exception.
     */
    private function handleException(RequestException $e, string $endpoint): array
    {
        $response = $e->getResponse();

        if ($response) {
            return $this->handleResponse($response, $endpoint);
        }

        Craft::error("Angie Chat API connection error ({$endpoint}): ".$e->getMessage(), __METHOD__);

        throw new \RuntimeException('Failed to connect to Angie Chat API. Please try again later.');
    }

    private function getSettings(): Settings
    {
        if (! AngieChat::$plugin) {
            throw new \RuntimeException('Angie Chat plugin is not initialized');
        }

        $settings = AngieChat::$plugin->getSettings();

        if (! $settings instanceof Settings) {
            throw new \RuntimeException('Angie Chat settings are not available');
        }

        return $settings;
    }
}
