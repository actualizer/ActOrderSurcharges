<?php declare(strict_types=1);

namespace Act\OrderSurcharges\Cart;

use Act\OrderSurcharges\Cart\LineItem\CodFeeLineItem;
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

class CodFeeProcessor implements CartProcessorInterface
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
            'ActOrderSurcharges.config.codFeeActive',
            $context->getSalesChannelId()
        );

        if (!$isActive) {
            $this->removeCodFee($toCalculate);
            return;
        }

        // Check if payment method is COD
        if (!$this->isCodPayment($context)) {
            $this->removeCodFee($toCalculate);
            return;
        }

        // Check if cart has regular items
        if (!$this->hasRegularItems($toCalculate)) {
            $this->removeCodFee($toCalculate);
            return;
        }

        // Add or update COD fee
        $this->addCodFee($toCalculate, $context);
    }

    private function isCodPayment(SalesChannelContext $context): bool
    {
        $paymentMethod = $context->getPaymentMethod();
        $paymentName = strtolower($paymentMethod->getName());

        return strpos($paymentName, 'nachnahme') !== false ||
               strpos($paymentName, 'cash on delivery') !== false ||
               strpos($paymentName, 'cod') !== false;
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

    private function removeCodFee(Cart $cart): void
    {
        $cart->getLineItems()->remove(CodFeeLineItem::TYPE);
    }

    private function addCodFee(Cart $cart, SalesChannelContext $context): void
    {
        // Get fee amount from config
        $feeAmount = (float) $this->systemConfigService->get(
            'ActOrderSurcharges.config.codFeeAmount',
            $context->getSalesChannelId()
        ) ?? 0.0;

        if ($feeAmount <= 0) {
            $this->removeCodFee($cart);
            return;
        }

        // Check if COD fee already exists
        $lineItem = $cart->getLineItems()->get(CodFeeLineItem::TYPE);

        if (!$lineItem) {
            $lineItem = new CodFeeLineItem(
                CodFeeLineItem::TYPE,
                1
            );
            $cart->getLineItems()->add($lineItem);
        }

        $lineItem->setLabel($this->translator->trans(CodFeeLineItem::SNIPPET_KEY));

        // Get tax rate
        $taxRate = $this->getTaxRate($cart, $context);

        // Create price definition
        $definition = new QuantityPriceDefinition(
            $feeAmount,
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