# ActOrderSurcharges - Shopware Plugin

A Shopware 6 plugin that automatically adds configurable surcharges to the shopping cart, including logistic fees and cash on delivery charges with dynamic payment method detection.

## Features

- ✅ Automatic logistic surcharge for all orders
- ✅ Cash on delivery (COD) fee based on payment method selection
- ✅ Dynamic surcharge calculation with proper tax handling
- ✅ Automatic cart cleanup when items are removed
- ✅ Payment method change detection and fee adjustment
- ✅ Configurable surcharge amounts through admin panel
- ✅ Multi-language support (German & English)
- ✅ Compatible with Shopware 6.6.10 - 6.7.x

## Requirements

- Shopware 6.6.10 or higher (up to 6.7.x)
- PHP 8.3 or higher

## Installation

1. Download or clone this plugin into your `custom/plugins/` directory
2. Install and activate the plugin via CLI:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate ActOrderSurcharges
   bin/console cache:clear
   ```

## Configuration

1. Go to Admin Panel → Settings → System → Plugins
2. Find "Actualize: Order Surcharges" and click on the three dots
3. Click "Config" to access plugin settings

### Configuration Options

#### Logistic Surcharge
- **Active**: Enable/disable the logistic surcharge
- **Logistic Surcharge Amount**: Fixed amount added to all orders (default: 4.95 €)

#### Cash on Delivery Fee
- **Active**: Enable/disable COD fees
- **COD Fee Amount**: Fee amount for cash on delivery payments (default: 5.95 €)

#### Tax Settings
- **Default Tax Rate**: Tax rate used when no other rate can be determined (default: 19.0%)

## How it works

### Logistic Surcharge
1. **Automatic Addition**: Added to cart when at least one regular product is present
2. **Cart Cleanup**: Automatically removed when cart becomes empty
3. **Tax Calculation**: Uses appropriate tax rate based on cart contents or configuration

### Cash on Delivery Fee
1. **Payment Detection**: Monitors payment method selection for COD variants
2. **Dynamic Addition**: Fee added when COD payment method is selected
3. **Automatic Removal**: Fee removed when different payment method is chosen
4. **Language Support**: Detects various COD payment method names (Nachnahme, Cash on Delivery, COD)

### Tax Handling
- Inherits tax rate from existing cart products
- Falls back to context tax rules
- Uses configured default tax rate as final fallback
- Proper tax calculation with Shopware's tax system

## Technical Details

### Architecture
- Uses `CartProcessorInterface` for clean cart integration
- `SalesChannelContextSwitchEvent` for payment method change detection
- Line items with unique IDs (`logistic-surcharge`, `cod-fee`)
- Proper price definitions with tax calculations

### Payment Method Detection
Detects COD payments by checking payment method names for:
- `nachnahme` (German)
- `cash on delivery` (English)
- `cod` (abbreviation)

## File Structure

```
ActOrderSurcharges/
├── composer.json
├── README.md
├── LICENSE
├── src/
│   ├── ActOrderSurcharges.php
│   ├── Resources/
│   │   ├── config/
│   │   │   ├── config.xml
│   │   │   └── services.xml
│   │   ├── snippet/
│   │   │   ├── de_DE/
│   │   │   │   └── storefront.de-DE.json
│   │   │   └── en_GB/
│   │   │       └── storefront.en-GB.json
│   │   └── public/
│   │       └── storefront/
│   │           └── img/
│   ├── Cart/
│   │   ├── LogisticSurchargeProcessor.php
│   │   ├── CodFeeProcessor.php
│   │   └── LineItem/
│   │       ├── LogisticSurchargeLineItem.php
│   │       └── CodFeeLineItem.php
│   └── Subscriber/
│       └── PaymentMethodChangedSubscriber.php
```

## Development

### Building/Testing
After making changes:
```bash
bin/console cache:clear
bin/console theme:compile
```

### Debugging
- Check cart contents in browser developer tools
- Monitor Shopware logs for surcharge-related events
- Test payment method changes in checkout process

## Usage Examples

### Standard Shopping Cart
1. Customer adds products to cart
2. Logistic surcharge automatically added
3. Total price includes surcharge + tax

### Cash on Delivery Order
1. Customer proceeds to checkout
2. Selects COD payment method
3. COD fee automatically added to cart
4. Changes to different payment → COD fee removed

### Empty Cart Handling
1. Customer removes all products
2. All surcharges automatically removed
3. Clean cart state maintained

## Compatibility

- **Shopware Version**: 6.6.10 - 6.7.x
- **PHP Version**: 8.3+
- **Payment Methods**: Compatible with all standard Shopware payment providers
- **Tax Systems**: Works with all Shopware tax configurations

## Support

For issues and feature requests, please use the GitHub issue tracker.

## License

This plugin is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Credits

Developed by Actualize

---

Made with ❤️ for the Shopware Community
