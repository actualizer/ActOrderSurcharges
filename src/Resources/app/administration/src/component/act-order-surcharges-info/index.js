import template from './act-order-surcharges-info.html.twig';
import './act-order-surcharges-info.scss';

const { Component } = Shopware;

const HOW_TO_ITEM_COUNT = 5;

Component.register('act-order-surcharges-info', {
    template,

    computed: {
        howToItems() {
            return Array.from(
                { length: HOW_TO_ITEM_COUNT },
                (_, index) => `act-order-surcharges.settings.info.howTo.item${index + 1}`,
            );
        },
    },
});
