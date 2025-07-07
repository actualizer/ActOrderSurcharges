<?php declare(strict_types=1);

namespace Act\OrderSurcharges\Subscriber;

use Shopware\Core\Checkout\Cart\Event\CartLoadedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Cart\Cart;

class CartLoadedSubscriber implements EventSubscriberInterface
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
            CartLoadedEvent::class => 'onCartLoaded'
        ];
    }

    /**
     * Called when the cart is loaded
     */
    public function onCartLoaded(CartLoadedEvent $event): void
    {
        $cart = $event->getCart();
        $context = $event->getSalesChannelContext();

        // Check plugin configuration
        $isActive = $this->systemConfigService->getBool(
            'ActOrderSurcharges.config.codFeeActive',
            $context->getSalesChannelId()
        );

        // If plugin is not active, remove fee if present
        if (!$isActive) {
            $this->removeCodFeeIfPresent($cart, $context);
            return;
        }

        // Check payment method
        $paymentMethod = $context->getPaymentMethod();
        $paymentName = strtolower($paymentMethod->getName());

        $isCod = strpos($paymentName, 'nachnahme') !== false ||
                 strpos($paymentName, 'cash on delivery') !== false ||
                 strpos($paymentName, 'cod') !== false;

        // If not cash on delivery, remove fee if present
        if (!$isCod) {
            $this->removeCodFeeIfPresent($cart, $context);
            return;
        }

        // Count regular items in cart
        $regularItemCount = 0;
        foreach ($cart->getLineItems() as $item) {
            if ($item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE) {
                $regularItemCount++;
            }
        }

        // If no regular items, remove fee if present
        if ($regularItemCount === 0) {
            $this->removeCodFeeIfPresent($cart, $context);
            return;
        }

        // If COD fee already exists, do nothing
        if ($cart->has(self::COD_FEE_ID)) {
            return;
        }

        // Otherwise add COD fee
        $this->addCodFee($cart, $context);
    }

    /**
     * Remove COD fee if present in the cart
     */
    private function removeCodFeeIfPresent($cart, SalesChannelContext $context): void
    {
        if ($cart->has(self::COD_FEE_ID)) {
            $cart->remove(self::COD_FEE_ID);
            $this->cartService->recalculate($cart, $context);
            $this->cartService->setCart($cart);
        }
    }

    /**
     * Add COD fee to cart
     */
    private function addCodFee($cart, SalesChannelContext $context): void
    {
        // Get fee amount from config
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

        // Set label using snippets
        $lineItem->setLabel('actualize.order.surcharges.cod-fee');

        // Create tax rules
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
        $this->cartService->setCart($cart);
    }

    /**
     * Create a TaxRuleCollection for the COD fee
     */
    private function createTaxRuleCollection(Cart $cart, SalesChannelContext $context): TaxRuleCollection
    {
        $taxRuleCollection = new TaxRuleCollection();

        // Get tax rate from context or fallback to existing cart items
        $taxRate = null;

        // First try to get the standard tax rate from the context
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

        // Add tax rule to collection
        $taxRuleCollection->add(new TaxRule($taxRate));

        return $taxRuleCollection;
    }
}
