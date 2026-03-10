<?php

namespace Dz0nika\AngieChatCraft\jobs;

use Craft;
use craft\queue\BaseJob;
use Dz0nika\AngieChatCraft\AngieChat;
use Dz0nika\AngieChatCraft\jobs\LogJob;

/**
 * Abandoned Cart Job - Sends cart data to Laravel for recovery emails.
 *
 * This job is dispatched when a Craft Commerce cart is detected as abandoned.
 * The Laravel backend handles the actual email generation and sending.
 */
class AbandonedCartJob extends BaseJob
{
    /**
     * The Commerce order ID.
     */
    public int $orderId;

    /**
     * The shopper's email address.
     */
    public string $email;

    public function execute($queue): void
    {
        // Validate plugin is available
        if (! AngieChat::$plugin) {
            Craft::warning('Angie Chat: Plugin not initialized, skipping abandoned cart job', __METHOD__);

            return;
        }

        if (! $this->isCommerceInstalled()) {
            Craft::warning('Angie Chat: Commerce not installed, skipping abandoned cart job', __METHOD__);

            return;
        }

        $order = $this->getOrder();

        if (! $order) {
            Craft::warning("Angie Chat: Order #{$this->orderId} not found", __METHOD__);

            return;
        }

        // Check if order is completed using multiple methods for compatibility
        $isCompleted = false;
        if (method_exists($order, 'getIsCompleted')) {
            $isCompleted = $order->getIsCompleted();
        } elseif (isset($order->isCompleted)) {
            $isCompleted = (bool) $order->isCompleted;
        }

        if ($isCompleted) {
            Craft::info("Angie Chat: Order #{$this->orderId} was completed, skipping", __METHOD__);

            return;
        }

        $payload = $this->buildPayload($order);

        try {
            $apiService = AngieChat::$plugin->getApi();
            $apiService->abandonedCart($payload);

            AngieChat::log('info', "Abandoned cart #{$this->orderId} queued for recovery", [
                'order_id'   => $this->orderId,
                'email'      => $this->email,
                'cart_value' => $payload['cart_value'] ?? 0,
                'item_count' => count($payload['items'] ?? []),
            ]);
        } catch (\Exception $e) {
            $message = strtolower($e->getMessage());

            // Don't retry for feature-not-enabled or license errors
            if (str_contains($message, 'feature not enabled') ||
                str_contains($message, 'invalid license') ||
                str_contains($message, '401') ||
                str_contains($message, '403')) {
                Craft::info(
                    'Angie Chat: Abandoned cart recovery not enabled or license invalid',
                    __METHOD__
                );

                return;
            }

            AngieChat::log('error', "Failed to send abandoned cart #{$this->orderId}: {$e->getMessage()}", [
                'order_id' => $this->orderId,
                'error'    => $e->getMessage(),
            ]);

            Craft::error(
                "Angie Chat: Failed to send abandoned cart #{$this->orderId}: " . $e->getMessage(),
                __METHOD__
            );

            throw $e;
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Angie Chat: Process abandoned cart #{$this->orderId}";
    }

    private function buildPayload($order): array
    {
        $items = [];

        try {
            if (method_exists($order, 'getLineItems')) {
                foreach ($order->getLineItems() as $lineItem) {
                    $title = 'Product';
                    if (isset($lineItem->description)) {
                        $title = $lineItem->description;
                    } elseif (method_exists($lineItem, 'getDescription')) {
                        $title = $lineItem->getDescription() ?? 'Product';
                    }

                    $items[] = [
                        'title' => (string) $title,
                        'qty' => (int) ($lineItem->qty ?? 1),
                        'price' => (float) ($lineItem->price ?? $lineItem->salePrice ?? 0),
                        'url' => $this->getLineItemUrl($lineItem),
                    ];
                }
            }
        } catch (\Exception $e) {
            // Continue with empty items if line item extraction fails
            $items = [];
        }

        $abandonedAt = null;
        $dateSource = $order->dateUpdated ?? $order->dateCreated ?? null;
        if ($dateSource instanceof \DateTimeInterface) {
            $abandonedAt = $dateSource->format('c');
        }

        return [
            'cart_id' => (string) ($order->id ?? 'unknown'),
            'email' => $this->email,
            'cart_value' => (float) ($order->total ?? $order->itemTotal ?? 0),
            'items' => $items,
            'abandoned_at' => $abandonedAt ?? (new \DateTime())->format('c'),
        ];
    }

    private function getLineItemUrl($lineItem): ?string
    {
        try {
            if (method_exists($lineItem, 'getPurchasable')) {
                $purchasable = $lineItem->getPurchasable();

                if ($purchasable && method_exists($purchasable, 'getUrl')) {
                    return $purchasable->getUrl();
                }
            }
        } catch (\Exception $e) {
            // Ignore errors getting URL
        }

        return null;
    }

    private function getOrder()
    {
        $commerceClass = 'craft\\commerce\\Plugin';

        if (! class_exists($commerceClass)) {
            return null;
        }

        try {
            $commerce = $commerceClass::getInstance();

            if ($commerce && method_exists($commerce, 'getOrders')) {
                return $commerce->getOrders()->getOrderById($this->orderId);
            }
        } catch (\Exception $e) {
            Craft::error("Angie Chat: Error fetching order: ".$e->getMessage(), __METHOD__);
        }

        return null;
    }

    private function isCommerceInstalled(): bool
    {
        return Craft::$app->getPlugins()->isPluginInstalled('commerce')
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }
}
