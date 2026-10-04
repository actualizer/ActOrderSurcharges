<?php declare(strict_types=1);

namespace Act\OrderSurcharges\Tests\Unit\Cart;

use Act\OrderSurcharges\Cart\CodFeeProcessor;
use Act\OrderSurcharges\Cart\LineItem\CodFeeLineItem;
use Act\OrderSurcharges\Cart\LineItem\LogisticSurchargeLineItem;
use Act\OrderSurcharges\Cart\LogisticSurchargeProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Generator;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Surcharge of 10.00 gross in every case.
 */
final class SurchargeTaxTest extends TestCase
{
    /**
     * @param list<array{float, float}> $products gross price and tax rate per product, in cart order
     * @param array<string, float> $expectedTaxes tax amount per tax rate
     */
    #[DataProvider('carts')]
    public function testCodFeeTaxFollowsTheGoodsInTheCart(array $products, array $expectedTaxes): void
    {
        $cart = $this->cart($products);

        $processor = new CodFeeProcessor($this->config(), $this->calculator(), $this->createStub(TranslatorInterface::class));
        $processor->process(new CartDataCollection(), $cart, $cart, $this->context(), new CartBehavior());

        $this->assertSurcharge($cart, CodFeeLineItem::TYPE, $expectedTaxes);
    }

    /**
     * @param list<array{float, float}> $products
     * @param array<string, float> $expectedTaxes
     */
    #[DataProvider('carts')]
    public function testLogisticSurchargeTaxFollowsTheGoodsInTheCart(array $products, array $expectedTaxes): void
    {
        $cart = $this->cart($products);

        $processor = new LogisticSurchargeProcessor($this->config(), $this->calculator(), $this->createStub(TranslatorInterface::class));
        $processor->process(new CartDataCollection(), $cart, $cart, $this->context(), new CartBehavior());

        $this->assertSurcharge($cart, LogisticSurchargeLineItem::TYPE, $expectedTaxes);
    }

    /**
     * @return iterable<string, array{list<array{float, float}>, array<string, float>}>
     */
    public static function carts(): iterable
    {
        // 10.00 * 19 / 119
        yield 'only 19 %' => [[[119.0, 19.0]], ['19' => 1.6]];
        // 10.00 * 7 / 107
        yield 'only 7 %' => [[[107.0, 7.0]], ['7' => 0.65]];
        // half each: 5.00 * 19 / 119 and 5.00 * 7 / 107
        yield 'half 19 %, half 7 %' => [[[119.0, 19.0], [119.0, 7.0]], ['19' => 0.8, '7' => 0.33]];
        yield 'half 7 %, half 19 %' => [[[119.0, 7.0], [119.0, 19.0]], ['19' => 0.8, '7' => 0.33]];
        // two thirds 19 %: 6.6667 * 19 / 119, one third 7 %: 3.3333 * 7 / 107
        yield 'two thirds 19 %' => [[[238.0, 19.0], [119.0, 7.0]], ['19' => 1.06, '7' => 0.22]];
    }

    public function testGoodsWithoutCalculatedTaxFallBackToTheirTaxRule(): void
    {
        $cart = new Cart('test');
        $product = new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE);
        $product->setPrice(new CalculatedPrice(107.0, 107.0, new CalculatedTaxCollection(), new TaxRuleCollection([new TaxRule(7.0)])));
        $cart->add($product);

        $processor = new CodFeeProcessor($this->config(), $this->calculator(), $this->createStub(TranslatorInterface::class));
        $processor->process(new CartDataCollection(), $cart, $cart, $this->context(), new CartBehavior());

        $this->assertSurcharge($cart, CodFeeLineItem::TYPE, ['7' => 0.65]);
    }

    /**
     * @param array<string, float> $expectedTaxes
     */
    private function assertSurcharge(Cart $cart, string $type, array $expectedTaxes): void
    {
        $price = $cart->getLineItems()->get($type)?->getPrice();
        self::assertNotNull($price);
        self::assertSame(10.0, $price->getTotalPrice());

        $taxes = [];
        foreach ($price->getCalculatedTaxes() as $tax) {
            $taxes[(string) $tax->getTaxRate()] = $tax->getTax();
        }
        ksort($taxes);
        ksort($expectedTaxes);

        self::assertSame($expectedTaxes, $taxes);
    }

    /**
     * @param list<array{float, float}> $products
     */
    private function cart(array $products): Cart
    {
        $cart = new Cart('test');
        foreach ($products as [$gross, $rate]) {
            $tax = round($gross * $rate / (100 + $rate), 2);
            $product = new LineItem(Uuid::randomHex(), LineItem::PRODUCT_LINE_ITEM_TYPE);
            $product->setPrice(new CalculatedPrice(
                $gross,
                $gross,
                new CalculatedTaxCollection([new CalculatedTax($tax, $rate, $gross)]),
                new TaxRuleCollection([new TaxRule($rate)])
            ));
            $cart->add($product);
        }

        return $cart;
    }

    private function config(): StaticSystemConfigService
    {
        return new StaticSystemConfigService([
            'ActOrderSurcharges.config.codFeeActive' => true,
            'ActOrderSurcharges.config.codFeeAmount' => 10.0,
            'ActOrderSurcharges.config.logisticSurchargeActive' => true,
            'ActOrderSurcharges.config.logisticSurchargeAmount' => 10.0,
        ]);
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

    private function context(): SalesChannelContext
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId(Uuid::randomHex());
        $paymentMethod->setName('Nachnahme');

        return Generator::generateSalesChannelContext(paymentMethod: $paymentMethod);
    }
}
