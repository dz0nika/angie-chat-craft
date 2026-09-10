<?php

namespace Dz0nika\AngieChatCraft\services;

use Craft;
use craft\helpers\UrlHelper;
use craft\base\Component;
use Dz0nika\AngieChatCraft\AngieChat;

/**
 * Exposes the shopper's current Commerce cart to the widget.
 *
 * Rendered into the page as
 *   <script type="application/json" id="angie-cart">{...}</script>
 * so the widget reads server-side truth (real discounts, real totals) instead
 * of scraping whatever the theme happens to render. The same shape is what
 * the widget later reports back on exit intent and what customers receive on
 * their cart webhook — keep it in sync with CartEventController's validation.
 *
 * Nothing sensitive is exposed: it is the visitor's own cart, already on screen.
 */
class CartSnapshotService extends Component
{
    public const ELEMENT_ID = 'angie-cart';

    /**
     * Cart snapshot for the current request, or null when there is nothing
     * worth telling the widget about (no Commerce, no cart, empty cart).
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(): ?array
    {
        if (! class_exists('craft\\commerce\\Plugin')) {
            return null;
        }

        try {
            $commerce = \craft\commerce\Plugin::getInstance();
            if (! $commerce) {
                return null;
            }

            // forceSave=false: never create a cart row just because the
            // widget looked. Returns an unsaved empty Order for new visitors.
            $order = $commerce->getCarts()->getCart(false);
            $lineItems = $order->getLineItems();

            if (empty($lineItems)) {
                return null;
            }

            $items = [];
            foreach ($lineItems as $li) {
                $items[] = [
                    'title' => (string) $li->getDescription(),
                    'sku' => (string) $li->getSku(),
                    'qty' => (int) $li->qty,
                    'price' => round((float) $li->getPrice(), 2),
                    'sale_price' => round((float) $li->getSalePrice(), 2),
                    // Commerce reports line discounts as negative adjustments;
                    // the widget wants "how much you're saving", so flip the sign.
                    'discount' => round(abs((float) $li->getDiscount()), 2),
                    'total' => round((float) $li->getTotal(), 2),
                    'url' => $this->purchasableUrl($li),
                ];
            }

            $number = (string) $order->number;

            return [
                'cart_id' => $order->id ? (string) $order->id : null,
                'number' => $number !== '' ? $number : null,
                'currency' => (string) ($order->currency ?: $order->paymentCurrency ?: 'USD'),
                'item_count' => array_sum(array_column($items, 'qty')),
                'subtotal' => round((float) $order->getItemSubtotal(), 2),
                'discount' => round(abs((float) $order->getTotalDiscount()), 2),
                'total' => round((float) $order->getTotalPrice(), 2),
                'cart_url' => $number !== '' ? self::cartLoadUrl($number) : null,
                'items' => $items,
            ];
        } catch (\Throwable $e) {
            Craft::warning('Angie Chat: cart snapshot failed: '.$e->getMessage(), __METHOD__);

            return null;
        }
    }

    /** The JSON script tag, or an empty string when there is no cart. */
    public function render(): string
    {
        $snapshot = $this->snapshot();

        if ($snapshot === null) {
            return '';
        }

        // JSON_HEX_TAG stops a product title containing "</script>" from
        // breaking out of the tag. Cheap insurance against odd catalogue data.
        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        return "\n<script type=\"application/json\" id=\"".self::ELEMENT_ID."\">{$json}</script>\n";
    }

    private function purchasableUrl($lineItem): ?string
    {
        try {
            $purchasable = $lineItem->getPurchasable();
            if ($purchasable && method_exists($purchasable, 'getUrl')) {
                return $purchasable->getUrl() ?: null;
            }
        } catch (\Throwable) {
            // A missing purchasable (deleted variant) is not our problem here.
        }

        return null;
    }
    /**
     * Link that re-attaches the cart to whoever opens it — Commerce's own
     * load-cart action, so it works from a recovery email on another device
     * and needs no knowledge of where the store keeps its cart page. Where
     * the shopper lands afterwards is Commerce's `loadCartRedirectUrl` setting.
     */
    public static function cartLoadUrl(string $number): string
    {
        return UrlHelper::actionUrl('commerce/cart/load-cart', ['number' => $number]);
    }
}
