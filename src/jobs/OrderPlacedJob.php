<?php

namespace Dz0nika\AngieChatCraft\jobs;

use Craft;
use craft\queue\BaseJob;
use Dz0nika\AngieChatCraft\AngieChat;

/**
 * Order Placed Job - Notifies the Angie Chat backend that an order has been completed.
 *
 * When a Craft Commerce order is marked as paid/completed, this job fires
 * POST /api/v1/craft/order-placed so the backend can:
 * 1. Mark the corresponding AbandonedCart record as recovered.
 * 2. Prevent any pending recovery emails from being sent.
 */
class OrderPlacedJob extends BaseJob
{
    /**
     * The Craft Commerce order ID (used as cart_id in the backend).
     */
    public int $orderId;

    /**
     * The order reference/number (optional, for logging).
     */
    public ?string $orderNumber = null;

    public function execute($queue): void
    {
        if (! AngieChat::$plugin) {
            return;
        }

        try {
            $apiService = AngieChat::$plugin->getApi();
            $apiService->orderPlaced([
                'cart_id'  => (string) $this->orderId,
                'order_id' => $this->orderNumber,
            ]);

            Craft::info("Angie Chat: Order #{$this->orderId} marked as recovered", __METHOD__);
        } catch (\Exception $e) {
            // Non-critical: if the backend is down, the duplicate guard in
            // ProcessAbandonedCart will still prevent sending once the order shows in Commerce.
            Craft::warning(
                "Angie Chat: Failed to notify order-placed for #{$this->orderId}: " . $e->getMessage(),
                __METHOD__
            );
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Angie Chat: Notify order placed #{$this->orderId}";
    }
}
