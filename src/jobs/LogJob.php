<?php

namespace nikolapopovic\angiechat\jobs;

use Craft;
use craft\queue\BaseJob;
use GuzzleHttp\Client;
use nikolapopovic\angiechat\AngieChat;

/**
 * Log Job – streams a structured log entry to the Laravel backend.
 *
 * This is fire-and-forget: failures are silently swallowed so a logging
 * problem never cascades into a broken queue. We intentionally bypass
 * ApiService here to avoid infinite loops (a failed API call would log
 * an error → enqueue another LogJob → fail → repeat).
 *
 * Retries are disabled by returning false from getTtr() and NOT re-throwing.
 */
class LogJob extends BaseJob
{
    public string $level   = 'info'; // info | warning | error
    public string $message = '';
    public array  $context = [];

    public function execute($queue): void
    {
        if (! AngieChat::$plugin) {
            return;
        }

        $settings = AngieChat::$plugin->getSettings();

        if (empty($settings->licenseKey)) {
            return;
        }

        try {
            $client = new Client([
                'timeout'         => 5,
                'connect_timeout' => 3,
                'http_errors'     => false,
            ]);

            $url = rtrim($settings->apiEndpoint, '/') . '/api/v1/craft/log';

            $client->post($url, [
                'headers' => [
                    'X-Craft-License' => $settings->licenseKey,
                    'Content-Type'    => 'application/json',
                    'Accept'          => 'application/json',
                    'User-Agent'      => 'AngieChatCraft/1.0',
                ],
                'json' => [
                    'level'   => $this->level,
                    'message' => $this->message,
                    'context' => empty($this->context) ? null : $this->context,
                ],
            ]);
            // Response is intentionally ignored – fire and forget
        } catch (\Throwable $e) {
            // Fall back to Craft's native log only, never re-queue
            Craft::warning(
                "Angie Chat: Failed to stream log to backend: {$e->getMessage()}",
                __METHOD__
            );
        }
    }

    protected function defaultDescription(): ?string
    {
        return null; // Keep queue UI clean – log jobs are invisible
    }

    /**
     * No retries for log jobs.
     */
    public function getTtr(): int
    {
        return 30;
    }
}
