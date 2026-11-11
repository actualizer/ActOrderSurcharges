# 1.3.1

- Fixed: on Shopware 6.7.0 and 6.7.1 every cart calculation failed, because a class the plugin relies on only exists from 6.7.2.0.

# 1.3.0

- The logistic surcharge can be calculated as a percentage of the goods value (products minus discounts and vouchers, excluding shipping costs), with an optional minimum and maximum amount.
- Optionally shows the percentage in the line item name, e.g. "Logistic surcharge (5%)".
- Existing installations keep the fixed amount; nothing changes until "Calculation" is switched.

# 1.2.9

- Tested with Shopware 6.7.16.0.
- Added a changelog in English and German.

Earlier versions: see the [tags on GitHub](https://github.com/actualizer/ActOrderSurcharges/tags).
