<?php declare(strict_types=1);

namespace Act\OrderSurcharges\Subscriber;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;

class CartPaymentMethodSubscriber implements EventSubscriberInterface
{
    private const COD_FEE_ID = 'cod-fee';

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var CartService
     */
    private $cartService;

    public function __construct(
        SystemConfigService $systemConfigService,
        CartService $cartService
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->cartService = $cartService;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutCartPageLoadedEvent::class => 'onCartPageLoaded',
        ];
    }

    /**
     * Responds to cart page loading
     */
    public function onCartPageLoaded(CheckoutCartPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $context = $event->getSalesChannelContext();
        $cart = $page->getCart();

        // Is COD fee active?
        $isActive = $this->systemConfigService->getBool(
            'ActOrderSurcharges.config.codFeeActive',
            $context->getSalesChannelId()
        );

        if (!$isActive) {
            $this->removeCodFee($cart, $context);
            return;
        }

        // Configured handler identifier
        $configuredHandler = $this->systemConfigService->getString(
            'ActOrderSurcharges.config.codPaymentHandlerIdentifier',
            $context->getSalesChannelId()
        );

        // Get payment method from page object
        $paymentMethod = $context->getPaymentMethod();

        // Is it cash on delivery?
        $isCashOnDelivery = false;

        // Technical name matches configuration
        if ($paymentMethod->getTechnicalName() === $configuredHandler) {
            $isCashOnDelivery = true;
        }

        // Name contains "Nachnahme" or "cash"
        $paymentName = strtolower($paymentMethod->getName());
        if (strpos($paymentName, 'nachnahme') !== false ||
            strpos($paymentName, 'cash') !== false) {
            $isCashOnDelivery = true;
        }

        // Add or remove COD fee
        if ($isCashOnDelivery) {
            $this->addCodFeeIfNotExists($cart, $context);
        } else {
            $this->removeCodFee($cart, $context);
        }
    }

    /**
     * Remove COD fee from cart
     */
    private function removeCodFee($cart, $context): void
    {
        if ($cart->has(self::COD_FEE_ID)) {
            $cart->remove(self::COD_FEE_ID);
            $this->cartService->recalculate($cart, $context);
        }
    }

    /**
     * Add COD fee if it doesn't already exist
     */
    private function addCodFeeIfNotExists($cart, $context): void
    {
        if ($cart->has(self::COD_FEE_ID)) {
            return;
        }

        // Get fee amount from configuration
        $feeAmount = (float) $this->systemConfigService->get(
            'ActOrderSurcharges.config.codFeeAmount',
            $context->getSalesChannelId()
        );

        // Create line item
        $lineItem = new LineItem(
            self::COD_FEE_ID,
            LineItem::CUSTOM_LINE_ITEM_TYPE,
            null,
            1
        );

        // Set name directly - no snippets
        $lineItem->setLabel('Cash on delivery fee');

        // Add tax
        $taxCollection = new TaxRuleCollection();
        $taxCollection->add(new TaxRule(19));

        // Define price
        $lineItem->setPriceDefinition(
            new QuantityPriceDefinition(
                $feeAmount,
                $taxCollection,
                1
            )
        );

        // Additional properties
        $lineItem->setPayloadValue('hideDeliveryTime', true);
        $lineItem->setPayloadValue('isCodFee', true);

        // Add to cart
        $cart->add($lineItem);
        $this->cartService->recalculate($cart, $context);
    }
}
