<?php declare(strict_types=1);

namespace Act\OrderSurcharges\Cart\LineItem;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Struct\ArrayStruct;

class CodFeeLineItem extends LineItem
{
    public const TYPE = 'cod-fee';
    public const SNIPPET_KEY = 'actualize.order.surcharges.cod-fee';

    public function __construct(string $id, int $quantity = 1)
    {
        parent::__construct($id, self::TYPE, null, $quantity);
        $this->setGood(false);
        $this->setStackable(false);
        $this->setRemovable(false);
        
        // Add custom fields
        $this->setPayloadValue('customFields', [
            'icon' => 'icon-money',
            'iconLabel' => 'actualize.order.surcharges.cod-fee-icon-label',
            'isCodFee' => true
        ]);
        
        $this->setPayloadValue('isCodFee', true);
        $this->setPayloadValue('hideDeliveryTime', true);
        
        // Add extensions
        $this->addExtension('codFee', new ArrayStruct());
        $this->addExtension('cssClass', new ArrayStruct(['value' => 'cart-item-cod-fee']));
    }
}