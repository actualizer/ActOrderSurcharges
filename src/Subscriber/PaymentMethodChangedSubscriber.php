<?php declare(strict_types=1);

namespace Act\OrderSurcharges\Subscriber;

use Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PaymentMethodChangedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            SalesChannelContextSwitchEvent::class => 'onPaymentMethodChanged',
        ];
    }

    public function onPaymentMethodChanged(SalesChannelContextSwitchEvent $event): void
    {
        // The cart processors will automatically handle the payment method change
        // No need to manually recalculate the cart here
    }
}
