<?php declare(strict_types=1);

namespace Act\OrderSurcharges\Subscriber;

use Shopware\Core\Checkout\Cart\Event\LineItemRemovedEvent;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CartRemovedSubscriber implements EventSubscriberInterface
{
    /**
     * @var CartService
     */
    private $cartService;

    public function __construct(CartService $cartService)
    {
        $this->cartService = $cartService;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            LineItemRemovedEvent::class => 'onLineItemRemoved',
        ];
    }

    /**
     * Handle line item removed event
     */
    public function onLineItemRemoved(LineItemRemovedEvent $event): void
    {
        $lineItem = $event->getLineItem();
        $cart = $event->getCart();
        $context = $event->getSalesChannelContext();

        // If a regular item was removed and no regular items remain in the cart,
        // all surcharges should also be removed
        if ($lineItem->getId() !== 'logistic-surcharge' && $lineItem->getId() !== 'cod-fee') {
            $hasOtherRegularItems = false;
            foreach ($cart->getLineItems() as $item) {
                if ($item->getId() !== 'logistic-surcharge' && $item->getId() !== 'cod-fee') {
                    $hasOtherRegularItems = true;
                    break;
                }
            }

            if (!$hasOtherRegularItems) {
                $changed = false;

                // Remove all surcharges
                if ($cart->has('logistic-surcharge')) {
                    $cart->remove('logistic-surcharge');
                    $changed = true;
                }

                if ($cart->has('cod-fee')) {
                    $cart->remove('cod-fee');
                    $changed = true;
                }

                // If changes were made, save the cart
                if ($changed) {
                    $this->cartService->recalculate($cart, $context);

                    // Make sure the cart is saved explicitly
                    $token = $context->getToken();
                    if ($token) {
                        $this->cartService->setCart($cart);

                        // Double-check by requesting a fresh cart
                        $currentCart = $this->cartService->getCart($token, $context);
                        $secondCheck = false;

                        if ($currentCart->has('logistic-surcharge')) {
                            $currentCart->remove('logistic-surcharge');
                            $secondCheck = true;
                        }

                        if ($currentCart->has('cod-fee')) {
                            $currentCart->remove('cod-fee');
                            $secondCheck = true;
                        }

                        if ($secondCheck) {
                            $this->cartService->recalculate($currentCart, $context);
                            $this->cartService->setCart($currentCart);
                        }
                    }
                }
            }
        }
    }
}
