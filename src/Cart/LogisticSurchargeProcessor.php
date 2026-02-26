<?php declare(strict_types=1);

namespace Act\OrderSurcharges\Cart;

use Act\OrderSurcharges\Cart\LineItem\LogisticSurchargeLineItem;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\Translation\TranslatorInterface;

class LogisticSurchargeProcessor implements CartProcessorInterface
{
    private SystemConfigService $systemConfigService;
    private QuantityPriceCalculator $calculator;
    private TranslatorInterface $translator;

    public function __construct(
        SystemConfigService $systemConfigService,
        QuantityPriceCalculator $calculator,
        TranslatorInterface $translator
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->calculator = $calculator;
        $this->translator = $translator;
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        // Check if plugin is active
        $isActive = $this->systemConfigService->getBool(
            'ActOrderSurcharges.config.logisticSurchargeActive',
            $context->getSalesChannelId()
        );

        if (!$isActive) {
            $this->removeLogisticSurcharge($toCalculate);
            return;
        }

        // Check if cart has regular items
        if (!$this->hasRegularItems($toCalculate)) {
            $this->removeLogisticSurcharge($toCalculate);
            return;
        }

        // Add or update logistic surcharge
        $this->addLogisticSurcharge($toCalculate, $context);
    }

    private function hasRegularItems(Cart $cart): bool
    {
        foreach ($cart->getLineItems() as $item) {
            if ($item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE) {
                return true;
            }
        }
        return false;
    }

    private function removeLogisticSurcharge(Cart $cart): void
    {
        $cart->getLineItems()->remove(LogisticSurchargeLineItem::TYPE);
    }

    private function addLogisticSurcharge(Cart $cart, SalesChannelContext $context): void
    {
        // Get surcharge amount from config
        $surchargeAmount = (float) $this->systemConfigService->get(
            'ActOrderSurcharges.config.logisticSurchargeAmount',
            $context->getSalesChannelId()
        ) ?? 0.0;

        if ($surchargeAmount <= 0) {
            $this->removeLogisticSurcharge($cart);
            return;
        }

        // Check if logistic surcharge already exists
        $lineItem = $cart->getLineItems()->get(LogisticSurchargeLineItem::TYPE);

        if (!$lineItem) {
            $lineItem = new LogisticSurchargeLineItem(
                LogisticSurchargeLineItem::TYPE,
                1
            );
            $cart->getLineItems()->add($lineItem);
        }

        $lineItem->setLabel($this->translator->trans(LogisticSurchargeLineItem::SNIPPET_KEY));

        // Get tax rate
        $taxRate = $this->getTaxRate($cart, $context);

        // Create price definition
        $definition = new QuantityPriceDefinition(
            $surchargeAmount,
            new TaxRuleCollection([new TaxRule($taxRate)]),
            1
        );

        // Calculate price
        $price = $this->calculator->calculate($definition, $context);
        $lineItem->setPrice($price);
    }

    private function getTaxRate(Cart $cart, SalesChannelContext $context): float
    {
        // Try to get tax rate from product items
        foreach ($cart->getLineItems() as $item) {
            if ($item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE && $item->getPrice() !== null) {
                $taxRules = $item->getPrice()->getTaxRules();
                if ($taxRules->count() > 0) {
                    return $taxRules->first()->getTaxRate();
                }
            }
        }

        // Fallback to default tax rate
        return (float) $this->systemConfigService->get(
            'ActOrderSurcharges.config.defaultTaxRate',
            $context->getSalesChannelId()
        ) ?? 19.0;
    }
}