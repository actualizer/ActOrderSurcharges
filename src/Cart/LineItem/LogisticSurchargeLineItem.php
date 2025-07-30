<?php declare(strict_types=1);

namespace Act\OrderSurcharges\Cart\LineItem;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Struct\ArrayStruct;

class LogisticSurchargeLineItem extends LineItem
{
    public const TYPE = 'logistic-surcharge';

    public function __construct(string $id, int $quantity = 1)
    {
        parent::__construct($id, self::TYPE, null, $quantity);

        $this->setLabel('actualize.order.surcharges.logistic-surcharge');
        $this->setGood(false);
        $this->setStackable(false);
        $this->setRemovable(false);
        
        // Add custom fields
        $this->setPayloadValue('customFields', [
            'icon' => 'icon-shipping-box',
            'isLogisticSurcharge' => true
        ]);
        
        $this->setPayloadValue('isLogisticSurcharge', true);
        $this->setPayloadValue('hideDeliveryTime', true);
        
        // Add extensions
        $this->addExtension('logisticSurcharge', new ArrayStruct());
        $this->addExtension('cssClass', new ArrayStruct(['value' => 'cart-item-logistic-surcharge']));
    }
}