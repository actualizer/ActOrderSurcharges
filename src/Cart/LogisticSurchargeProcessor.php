<?php declare(strict_types=1);

namespace Act\OrderSurcharges\Cart;

use Act\OrderSurcharges\Cart\LineItem\LogisticSurchargeLineItem;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\CheckoutPermissions;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\PercentageTaxRuleBuilder;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\Translation\TranslatorInterface;

class LogisticSurchargeProcessor implements CartProcessorInterface
{
    private const CALCULATION_PERCENTAGE = 'percentage';

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
        // During an admin order edit the order is recalculated through this same
        // processor. Preserve the logistic surcharge exactly as it was persisted with
        // the order instead of recomputing it from the current config, which would
        // retroactively add, change or drop it on an existing order. Storefront
        // checkout never sets this flag, so storefront behaviour is unaffected.
        // CheckoutPermissions exists from Shopware 6.7.2.0 on; older releases have no such flag.
        if (class_exists(CheckoutPermissions::class) && $behavior->hasPermission(CheckoutPermissions::SKIP_CART_PERSISTENCE)) {
            $persisted = $original->getLineItems()->get(LogisticSurchargeLineItem::TYPE);
            if ($persisted !== null) {
                $toCalculate->getLineItems()->add($persisted);
            }
            return;
        }

        // Check if plugin is active
        $isActive = $this->isConfigFlagEnabled('ActOrderSurcharges.config.logisticSurchargeActive', $context);

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

    private function isConfigFlagEnabled(string $key, SalesChannelContext $context): bool
    {
        // Robust against config values persisted as strings ("false"/"0"), which a
        // plain (bool) cast — and thus SystemConfigService::getBool — would read as
        // true. filter_var normalises bool, "true"/"false", "1"/"0" and null.
        return filter_var(
            $this->systemConfigService->get($key, $context->getSalesChannelId()),
            FILTER_VALIDATE_BOOLEAN
        );
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
        $percentage = null;
        if ($this->getConfigValue('logisticSurchargeCalculation', $context) === self::CALCULATION_PERCENTAGE) {
            [$surchargeAmount, $percentage] = $this->calculatePercentageSurcharge($cart, $context);
        } else {
            $surchargeAmount = $this->getConfigFloat('logisticSurchargeAmount', $context);
        }

        if ($surchargeAmount <= 0) {
            $this->removeLogisticSurcharge($cart);
            return;
        }

        // Check if logistic surcharge already exists
        $lineItem = $cart->getLineItems()->get(LogisticSurchargeLineItem::TYPE);

        if ($lineItem === null) {
            $lineItem = new LogisticSurchargeLineItem(
                LogisticSurchargeLineItem::TYPE,
                1
            );
            $cart->getLineItems()->add($lineItem);
        }

        if ($percentage !== null && $this->isConfigFlagEnabled('ActOrderSurcharges.config.logisticSurchargeShowPercentage', $context)) {
            $lineItem->setLabel($this->translator->trans(
                LogisticSurchargeLineItem::SNIPPET_KEY_PERCENTAGE,
                ['%percentage%' => $this->formatPercentage($percentage, $context)]
            ));
        } else {
            $lineItem->setLabel($this->translator->trans(LogisticSurchargeLineItem::SNIPPET_KEY));
        }

        // Create price definition
        $definition = new QuantityPriceDefinition(
            $surchargeAmount,
            $this->getTaxRules($cart, $context),
            1
        );

        // Calculate price
        $price = $this->calculator->calculate($definition, $context);
        $lineItem->setPrice($price);
    }

    /**
     * The base is the goods value after promotions, in the price display of the context
     * (gross or net), so the surcharge matches what the customer sees.
     *
     * @return array{float, ?float} amount, and the percentage when it was applied without a limit
     */
    private function calculatePercentageSurcharge(Cart $cart, SalesChannelContext $context): array
    {
        $percentage = $this->getConfigFloat('logisticSurchargePercentage', $context);
        if ($percentage <= 0) {
            return [0.0, null];
        }

        $base = 0.0;
        foreach ($cart->getLineItems() as $item) {
            if (\in_array($item->getType(), [LineItem::PRODUCT_LINE_ITEM_TYPE, LineItem::PROMOTION_LINE_ITEM_TYPE], true)) {
                $base += $item->getPrice()?->getTotalPrice() ?? 0.0;
            }
        }

        $amount = max(0.0, $base) * $percentage / 100;

        $minAmount = $this->getConfigFloat('logisticSurchargeMinAmount', $context);
        if ($minAmount > 0 && $amount < $minAmount) {
            return [$minAmount, null];
        }

        $maxAmount = $this->getConfigFloat('logisticSurchargeMaxAmount', $context);
        if ($maxAmount > 0 && $amount > $maxAmount) {
            return [$maxAmount, null];
        }

        return [$amount, $percentage];
    }

    private function formatPercentage(float $percentage, SalesChannelContext $context): string
    {
        $formatter = new \NumberFormatter($context->getLanguageInfo()->localeCode, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
        $formatted = $formatter->format($percentage);

        return \is_string($formatted) ? $formatted : (string) $percentage;
    }

    private function getConfigValue(string $field, SalesChannelContext $context): mixed
    {
        return $this->systemConfigService->get('ActOrderSurcharges.config.' . $field, $context->getSalesChannelId());
    }

    private function getConfigFloat(string $field, SalesChannelContext $context): float
    {
        $value = $this->getConfigValue($field, $context);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function getTaxRules(Cart $cart, SalesChannelContext $context): TaxRuleCollection
    {
        // Split by the share each tax rate has in the goods of the cart.
        $goods = $cart->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE)->getPrices()->sum();
        $rules = (new PercentageTaxRuleBuilder())->buildRules($goods);
        if ($rules->count() > 0) {
            return $rules;
        }

        return new TaxRuleCollection([new TaxRule($this->getTaxRate($cart, $context))]);
    }

    private function getTaxRate(Cart $cart, SalesChannelContext $context): float
    {
        // Try to get tax rate from product items
        foreach ($cart->getLineItems() as $item) {
            if ($item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE && $item->getPrice() !== null) {
                $taxRules = $item->getPrice()->getTaxRules();
                $firstTaxRule = $taxRules->first();
                if ($firstTaxRule !== null) {
                    return $firstTaxRule->getTaxRate();
                }
            }
        }

        // Fallback to default tax rate
        return (float) ($this->systemConfigService->get(
            'ActOrderSurcharges.config.defaultTaxRate',
            $context->getSalesChannelId()
        ) ?? 19.0);
    }
}