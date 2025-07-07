<?php declare(strict_types=1);

namespace Act\OrderSurcharges\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\Event\CartChangedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class LogisticSurchargeService implements EventSubscriberInterface
{
    private const LOGISTIC_SURCHARGE_ID = 'logistic-surcharge';

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
        ];
    }

    /**
     * Handle cart changed event to add logistic surcharge if needed
     */
    public function onCartChanged(CartChangedEvent $event): void
    {
        $cart = $event->getCart();
        $context = $event->getSalesChannelContext();

        // Check if the plugin is active
        $isActive = $this->systemConfigService->getBool(
            'ActOrderSurcharges.config.logisticSurchargeActive',
            $context->getSalesChannelId()
        );

        if (!$isActive) {
            return;
        }

        // Count the number of regular items (excluding logistic surcharge and COD fee)
        $regularItemCount = 0;
        foreach ($cart->getLineItems() as $item) {
            if ($item->getId() !== self::LOGISTIC_SURCHARGE_ID && $item->getId() !== 'cod-fee') {
                $regularItemCount++;
            }
        }

        // If there are no regular items, remove the logistic surcharge
        if ($regularItemCount === 0) {
            // Remove the logistic surcharge if present
            if ($cart->has(self::LOGISTIC_SURCHARGE_ID)) {
                $cart->remove(self::LOGISTIC_SURCHARGE_ID);

                // Important: Save the cart explicitly
                $this->cartService->recalculate($cart, $context);

                // Directly save the cart
                $token = $context->getToken();
                if ($token) {
                    $this->cartService->setCart($cart);

                    // Start a new request to update the cart
                    // This is necessary because otherwise the cart won't be updated
                    $currentCart = $this->cartService->getCart($token, $context);

                    // Check if the logistic surcharge is still in the cart
                    if ($currentCart->has(self::LOGISTIC_SURCHARGE_ID)) {
                        // Remove it again
                        $currentCart->remove(self::LOGISTIC_SURCHARGE_ID);
                        $this->cartService->recalculate($currentCart, $context);
                        $this->cartService->setCart($currentCart);
                    }
                }
            }
            return;
        }

        // Otherwise add the logistic surcharge if it doesn't exist yet
        $this->addLogisticSurchargeIfNeeded($cart, $context);
    }

    /**
     * Add logistic surcharge to cart if needed
     */
    private function addLogisticSurchargeIfNeeded(Cart $cart, SalesChannelContext $salesChannelContext): void
    {
        // Check if plugin is active
        $isActive = $this->systemConfigService->getBool(
            'ActOrderSurcharges.config.logisticSurchargeActive',
            $salesChannelContext->getSalesChannelId()
        );

        if (!$isActive) {
            return;
        }

        // Check if cart is empty
        if (count($cart->getLineItems()) === 0) {
            return;
        }

        // Check if surcharge is already in cart
        if ($cart->has(self::LOGISTIC_SURCHARGE_ID)) {
            return;
        }

        // Get surcharge amount from config
        $surchargeAmount = (float) $this->systemConfigService->get(
            'ActOrderSurcharges.config.logisticSurchargeAmount',
            $salesChannelContext->getSalesChannelId()
        );

        // Create line item for logistic surcharge
        $lineItem = new LineItem(
            self::LOGISTIC_SURCHARGE_ID,
            LineItem::CUSTOM_LINE_ITEM_TYPE,
            null,
            1
        );

        // Use direct label instead of snippet
        $locale = $salesChannelContext->getContext()->getLanguageId() === \Shopware\Core\Defaults::LANGUAGE_SYSTEM ? 'en-GB' : 'de-DE';
        if ($locale === 'en-GB') {
            $lineItem->setLabel('Logistic surcharge');
        } else {
            $lineItem->setLabel('Logistikpauschale');
        }

        // Create a TaxRuleCollection with the appropriate tax rate
        $taxRuleCollection = $this->createTaxRuleCollection($cart, $salesChannelContext);

        $lineItem->setPriceDefinition(
            new \Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition(
                $surchargeAmount,
                $taxRuleCollection,
                1
            )
        );

        // Set a standard icon for the logistic surcharge
        $lineItem->setPayloadValue('customFields', [
            'icon' => 'icon-shipping-box',
            'isLogisticSurcharge' => true
        ]);

        // Set additional properties to improve display in the shopping cart
        $lineItem->setPayloadValue('isLogisticSurcharge', true);
        $lineItem->setPayloadValue('hideDeliveryTime', true);

        // Set the logistic surcharge as removable, but hide the button in the storefront
        $lineItem->setRemovable(true);
        $lineItem->setStackable(false);

        // Add a CSS class to hide the delete button
        $lineItem->addExtension('logisticSurcharge', new ArrayStruct());
        $lineItem->addExtension('cssClass', new ArrayStruct(['value' => 'cart-item-logistic-surcharge']));

        // Add line item to cart
        $this->cartService->add($cart, $lineItem, $salesChannelContext);
    }

    /**
     * Create a TaxRuleCollection for the logistic surcharge
     */
    private function createTaxRuleCollection(Cart $cart, SalesChannelContext $context): \Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection
    {
        $taxRuleCollection = new \Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection();

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
        $taxRuleCollection->add(new \Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule($taxRate));

        return $taxRuleCollection;
    }
}
