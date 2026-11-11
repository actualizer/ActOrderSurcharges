import './sw-system-config.scss';

const { Component } = Shopware;

const CONFIG_PREFIX = 'ActOrderSurcharges.config.';
const CALCULATION_KEY = `${CONFIG_PREFIX}logisticSurchargeCalculation`;

const FIELDS_BY_CALCULATION = {
    fixed: [
        `${CONFIG_PREFIX}logisticSurchargeAmount`,
    ],
    percentage: [
        `${CONFIG_PREFIX}logisticSurchargePercentage`,
        `${CONFIG_PREFIX}logisticSurchargeMinAmount`,
        `${CONFIG_PREFIX}logisticSurchargeMaxAmount`,
        `${CONFIG_PREFIX}logisticSurchargeShowPercentage`,
    ],
};

Component.override('sw-system-config', {
    methods: {
        getInheritWrapperBind(element) {
            const bind = this.$super('getInheritWrapperBind', element);

            if (!element?.name?.startsWith(CONFIG_PREFIX) || !this.isHiddenByLogisticCalculation(element.name)) {
                return bind;
            }

            return { ...bind, class: 'act-order-surcharges-config__field--hidden' };
        },

        isHiddenByLogisticCalculation(name) {
            // A sales channel without its own value inherits the global one.
            const calculation = this.actualConfigData?.[this.currentSalesChannelId]?.[CALCULATION_KEY]
                ?? this.actualConfigData?.null?.[CALCULATION_KEY]
                ?? 'fixed';

            return Object.entries(FIELDS_BY_CALCULATION)
                .some(([key, fields]) => key !== calculation && fields.includes(name));
        },
    },
});
