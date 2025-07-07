<?php declare(strict_types=1);

namespace Act\OrderSurcharges\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Event\CartChangedEvent;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerChangedPaymentMethodEvent;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CodFeeService implements EventSubscriberInterface
{
    private const COD_FEE_ID = 'cod-fee';

    /**
     * @var EntityRepository
     */
    private $productRepository;

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var CartService
     */
    private $cartService;

    public function __construct(
        EntityRepository $productRepository,
        SystemConfigService $systemConfigService,
        CartService $cartService
    ) {
        $this->productRepository = $productRepository;
        $this->systemConfigService = $systemConfigService;
        $this->cartService = $cartService;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CartChangedEvent::class => 'onCartChanged',
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmPageLoaded',
            CustomerChangedPaymentMethodEvent::class => 'onCustomerChangedPaymentMethod',
            CheckoutOrderPlacedEvent::class => 'onCheckoutOrderPlaced'
        ];
    }

    /**
     * Handle cart changed event to add COD fee if needed
     */
    public function onCartChanged(CartChangedEvent $event): void
    {
        // For CartChanged event, only check if there are additional products in the cart
        // The main logic for the COD fee is handled by the PaymentMethodChangedSubscriber
        $cart = $event->getCart();

        // If there are no items in the cart, remove COD fee if present
        $regularItemCount = 0;
        foreach ($cart->getLineItems() as $item) {
            if ($item->getId() !== self::COD_FEE_ID && $item->getId() !== 'logistic-surcharge') {
                $regularItemCount++;
            }
        }

        if ($regularItemCount === 0 && $cart->has(self::COD_FEE_ID)) {
            $cart->remove(self::COD_FEE_ID);
            $this->cartService->recalculate($cart, $event->getSalesChannelContext());
            return;
        }
    }

    /**
     * Handle checkout confirm page loaded event
     */
    public function onCheckoutConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $cart = $event->getPage()->getCart();
        $context = $event->getSalesChannelContext();

        $this->updateCodFee($cart, $context);
        $this->cartService->setCart($cart);
    }

    /**
     * Handle payment method changed event
     */
    public function onCustomerChangedPaymentMethod(CustomerChangedPaymentMethodEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);

        $this->updateCodFee($cart, $salesChannelContext);
        $this->cartService->setCart($cart);
    }

    /**
     * Handle order placed event
     */
    public function onCheckoutOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        // This method is triggered when the order is placed
        // We leave it empty because we just want to make sure that previous events
        // have correctly set up the COD fee in the cart
    }

    /**
     * Update COD fee based on current payment method
     */
    private function updateCodFee(Cart $cart, SalesChannelContext $context): void
    {
        // Check if plugin is active
        $isActive = $this->systemConfigService->getBool(
            'ActOrderSurcharges.config.codFeeActive',
            $context->getSalesChannelId()
        );

        // If plugin is not active, remove COD fee if it exists
        if (!$isActive) {
            if ($cart->has(self::COD_FEE_ID)) {
                $cart->remove(self::COD_FEE_ID);
                $this->cartService->recalculate($cart, $context);
            }
            return;
        }

        // Get payment method
        $paymentMethod = $context->getPaymentMethod();
        $paymentName = strtolower($paymentMethod->getName());

        // Check if payment method is cash on delivery
        $isCod = strpos($paymentName, 'nachnahme') !== false ||
                 strpos($paymentName, 'cash on delivery') !== false ||
                 strpos($paymentName, 'cod') !== false;

        // If not cash on delivery, remove the fee if it exists
        if (!$isCod) {
            if ($cart->has(self::COD_FEE_ID)) {
                $cart->remove(self::COD_FEE_ID);
                $this->cartService->recalculate($cart, $context);
            }
            return;
        }

        // Count regular items in cart
        $regularItemCount = 0;
        foreach ($cart->getLineItems() as $item) {
            if ($item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE) {
                $regularItemCount++;
            }
        }

        // If no regular items in cart, don't add COD fee
        if ($regularItemCount === 0) {
            // Remove COD fee if it exists
            if ($cart->has(self::COD_FEE_ID)) {
                $cart->remove(self::COD_FEE_ID);
                $this->cartService->recalculate($cart, $context);
            }
            return;
        }

        // If COD fee already exists, don't add it again
        if ($cart->has(self::COD_FEE_ID)) {
            return;
        }

        // Get fee amount from config
        $feeAmount = (float) $this->systemConfigService->get(
            'ActOrderSurcharges.config.codFeeAmount',
            $context->getSalesChannelId()
        );

        // Add COD fee to cart
        $this->addCodFeeToCart($cart, $context, $feeAmount);
    }

    /**
     * Add COD fee to cart
     */
    private function addCodFeeToCart(Cart $cart, SalesChannelContext $context, float $feeAmount): void
    {
        // Create line item
        $lineItem = new LineItem(
            self::COD_FEE_ID,
            LineItem::CUSTOM_LINE_ITEM_TYPE,
            null,
            1
        );

        // Set label using snippet
        $lineItem->setLabel('actualize.order.surcharges.cod-fee');

        // Create tax rules with tax from context
        $taxRuleCollection = $this->createTaxRuleCollection($cart, $context);

        // Set price definition
        $lineItem->setPriceDefinition(
            new QuantityPriceDefinition(
                $feeAmount,
                $taxRuleCollection,
                1
            )
        );

        // Set properties
        $lineItem->setPayloadValue('customFields', [
            'icon' => 'icon-money',
            'isCodFee' => true
        ]);

        $lineItem->setPayloadValue('isCodFee', true);
        $lineItem->setPayloadValue('hideDeliveryTime', true);
        $lineItem->setRemovable(true);
        $lineItem->setStackable(false);
        $lineItem->addExtension('codFee', new ArrayStruct());
        $lineItem->addExtension('cssClass', new ArrayStruct(['value' => 'cart-item-cod-fee']));

        // Add to cart and recalculate
        $cart->add($lineItem);
        $this->cartService->recalculate($cart, $context);
    }

    /**
     * Create a TaxRuleCollection for the COD fee
     */
    private function createTaxRuleCollection(Cart $cart, SalesChannelContext $context): TaxRuleCollection
    {
        $taxRuleCollection = new TaxRuleCollection();

        // Get tax rate from context or fallback to existing cart items
        $taxRate = null;

        // First try to get the tax rate from the context
        try {
            // Get the tax collection from the context
            $taxes = $context->getTaxRules();
            if ($taxes->count() > 0) {
                $taxRate = $taxes->first()->getTaxRate();
            }
        } catch (\Exception $e) {
            // Tax rate could not be determined from context
        }

        // If still no tax rate, extract from existing items as fallback
        if ($taxRate === null) {
            foreach ($cart->getLineItems() as $item) {
                if ($item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE && $item->getPrice() !== null) {
                    $taxRules = $item->getPrice()->getTaxRules();
                    if ($taxRules->count() > 0) {
                        $taxRule = $taxRules->first();
                        $taxRate = $taxRule->getTaxRate();
                        break;
                    }
                }
            }
        }

        // Final fallback to configured default tax rate
        if ($taxRate === null) {
            $taxRate = (float) $this->systemConfigService->get(
                'ActOrderSurcharges.config.defaultTaxRate',
                $context->getSalesChannelId(),
                19.0
            );
        }

        // Add the tax rule to the collection
        $taxRuleCollection->add(new TaxRule($taxRate));

        return $taxRuleCollection;
    }
}
