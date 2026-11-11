<?php declare(strict_types=1);

namespace Act\OrderSurcharges\Tests\Unit\Cart;

use Act\OrderSurcharges\Cart\LineItem\LogisticSurchargeLineItem;
use Act\OrderSurcharges\Cart\LogisticSurchargeProcessor;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\CashRounding;
use Shopware\Core\Checkout\Cart\Price\GrossPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\NetPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Cart\Tax\TaxCalculator;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\LanguageInfo;
use Shopware\Core\Test\Generator;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * All goods are taxed at 19 % and priced gross.
 */
final class LogisticSurchargePercentageTest extends TestCase
{
    public function testFixedAmountIsUsedWhenNoCalculationIsConfigured(): void
    {
        $cart = $this->process($this->cart([200.0]), ['logisticSurchargePercentage' => 5.0], 'de-DE');

        self::assertSame(10.0, $this->surchargeTotal($cart));
    }

    public function testPercentageOfTheGoods(): void
    {
        $cart = $this->process($this->cart([150.0, 88.0]), $this->percentage(5.0), 'de-DE');

        self::assertSame(11.9, $this->surchargeTotal($cart));
    }

    public function testPromotionsReduceTheBase(): void
    {
        $cart = $this->process($this->cart([200.0, -100.0]), $this->percentage(5.0), 'de-DE');

        self::assertSame(5.0, $this->surchargeTotal($cart));
    }

    public function testMinimumAmountRaisesTheSurcharge(): void
    {
        $cart = $this->process($this->cart([20.0]), $this->percentage(5.0, min: 3.0, max: 25.0), 'de-DE');

        self::assertSame(3.0, $this->surchargeTotal($cart));
    }

    public function testMaximumAmountCapsTheSurcharge(): void
    {
        $cart = $this->process($this->cart([2000.0]), $this->percentage(5.0, min: 3.0, max: 25.0), 'de-DE');

        self::assertSame(25.0, $this->surchargeTotal($cart));
    }

    public function testNoSurchargeWithoutPercentage(): void
    {
        $cart = $this->process($this->cart([200.0]), $this->percentage(0.0, min: 3.0), 'de-DE');

        self::assertNull($cart->getLineItems()->get(LogisticSurchargeLineItem::TYPE));
    }

    public function testLabelShowsThePercentageInTheCustomersLocale(): void
    {
        $config = $this->percentage(2.5) + ['logisticSurchargeShowPercentage' => true];

        self::assertSame('Logistikpauschale (2,5 %)', $this->label($this->process($this->cart([200.0]), $config, 'de-DE')));
        self::assertSame('Logistikpauschale (2.5 %)', $this->label($this->process($this->cart([200.0]), $config, 'en-GB')));
    }

    public function testLabelOmitsThePercentageWhenALimitApplies(): void
    {
        $config = $this->percentage(5.0, min: 3.0, max: 25.0) + ['logisticSurchargeShowPercentage' => true];

        self::assertSame('Logistikpauschale', $this->label($this->process($this->cart([20.0]), $config, 'de-DE')));
        self::assertSame('Logistikpauschale', $this->label($this->process($this->cart([2000.0]), $config, 'de-DE')));
    }

    public function testLabelOmitsThePercentageUnlessEnabled(): void
    {
        self::assertSame('Logistikpauschale', $this->label($this->process($this->cart([200.0]), $this->percentage(5.0), 'de-DE')));
    }

    /**
     * @return array<string, mixed>
     */
    private function percentage(float $percentage, ?float $min = null, ?float $max = null): array
    {
        return [
            'logisticSurchargeCalculation' => 'percentage',
            'logisticSurchargePercentage' => $percentage,
            'logisticSurchargeMinAmount' => $min,
            'logisticSurchargeMaxAmount' => $max,
        ];
    }

    /**
     * @param array<string, mixed> $config keys below ActOrderSurcharges.config
     */
    private function process(Cart $cart, array $config, string $locale): Cart
    {
        $values = [
            'ActOrderSurcharges.config.logisticSurchargeActive' => true,
            'ActOrderSurcharges.config.logisticSurchargeAmount' => 10.0,
        ];
        foreach ($config as $key => $value) {
            $values['ActOrderSurcharges.config.' . $key] = $value;
        }

        $processor = new LogisticSurchargeProcessor(new StaticSystemConfigService($values), $this->calculator(), $this->translator());
        $context = Generator::generateSalesChannelContext(languageInfo: new LanguageInfo('Test', $locale));
        $processor->process(new CartDataCollection(), $cart, $cart, $context, new CartBehavior());

        return $cart;
    }

    private function surchargeTotal(Cart $cart): ?float
    {
        return $cart->getLineItems()->get(LogisticSurchargeLineItem::TYPE)?->getPrice()?->getTotalPrice();
    }

    private function label(Cart $cart): ?string
    {
        return $cart->getLineItems()->get(LogisticSurchargeLineItem::TYPE)?->getLabel();
    }

    /**
     * Positive prices become products, negative ones promotions.
     *
     * @param list<float> $grossPrices
     */
    private function cart(array $grossPrices): Cart
    {
        $cart = new Cart('test');
        foreach ($grossPrices as $gross) {
            $type = $gross < 0 ? LineItem::PROMOTION_LINE_ITEM_TYPE : LineItem::PRODUCT_LINE_ITEM_TYPE;
            $item = new LineItem(Uuid::randomHex(), $type);
            $item->setPrice(new CalculatedPrice(
                $gross,
                $gross,
                new CalculatedTaxCollection([new CalculatedTax(round($gross * 19 / 119, 2), 19.0, $gross)]),
                new TaxRuleCollection([new TaxRule(19.0)])
            ));
            $cart->add($item);
        }

        return $cart;
    }

    private function translator(): TranslatorInterface
    {
        $snippets = [
            LogisticSurchargeLineItem::SNIPPET_KEY => 'Logistikpauschale',
            LogisticSurchargeLineItem::SNIPPET_KEY_PERCENTAGE => 'Logistikpauschale (%percentage% %)',
        ];

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => strtr($snippets[$id] ?? $id, $parameters)
        );

        return $translator;
    }

    private function calculator(): QuantityPriceCalculator
    {
        $taxCalculator = new TaxCalculator();
        $rounding = new CashRounding();

        return new QuantityPriceCalculator(
            new GrossPriceCalculator($taxCalculator, $rounding),
            new NetPriceCalculator($taxCalculator, $rounding)
        );
    }
}
