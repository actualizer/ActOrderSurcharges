<?php declare(strict_types=1);

namespace Act\OrderSurcharges\Service;

use Shopware\Core\Checkout\Cart\Event\CartChangedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CartCleanupService implements EventSubscriberInterface
{
    /**
     * @var CartService
     */
    private $cartService;

    public function __construct(
        CartService $cartService
    ) {
        $this->cartService = $cartService;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CartChangedEvent::class => 'onCartChanged'
        ];
    }

    /**
     * Remove surcharges if cart is empty
     */
    public function onCartChanged(CartChangedEvent $event): void
    {
        $cart = $event->getCart();
        $context = $event->getSalesChannelContext();

        // Check if there are any product items in the cart
        $hasProductItems = false;
        foreach ($cart->getLineItems() as $item) {
            if ($item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE) {
                $hasProductItems = true;
                break;
            }
        }

        // If no product items, remove surcharges
        if (!$hasProductItems) {
            $changed = false;

            // Remove logistic surcharge
            if ($cart->has('logistic-surcharge')) {
                $cart->remove('logistic-surcharge');
                $changed = true;
            }

            // Remove COD fee
            if ($cart->has('cod-fee')) {
                $cart->remove('cod-fee');
                $changed = true;
            }

            // Recalculate cart if changed
            if ($changed) {
                $this->cartService->recalculate($cart, $context);
            }
        }
    }
}
