define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    var initializedSelectors = {};
    var defaultSelector = 'select[name="groups[ia][fields][enable_discount][value]"], #neurocheckoutconnector_ia_enable_discount';

    function bindSelector(selector) {
        if (initializedSelectors[selector]) {
            return;
        }
        initializedSelectors[selector] = true;

        var isReverting = false;

        function rememberValue($field) {
            $field.data('ncPreviousValue', String($field.val() || '0'));
        }

        function clearOpenFlag($field) {
            $field.data('ncConfirmOpen', false);
        }

        function confirmWithFallback(onConfirm, onCancel) {
            var nativeMessage = $t('Autoriser les remises IA automatiques') + '\n\n' + $t(
                'En activant cette option, NeuroCheckout pourra ajouter des coupons dedies dans les relances de panier abandonne. Les remises resteront limitees par vos seuils configures et par "Reduction maximale IA (%)". Activez cette option uniquement si cette automatisation est validee en interne.'
            );

            if (!window.require) {
                if (window.confirm(nativeMessage)) {
                    onConfirm();
                } else {
                    onCancel();
                }
                return;
            }

            window.require(
                ['Magento_Ui/js/modal/confirm'],
                function (confirmation) {
                    confirmation({
                        title: $t('Autoriser les remises IA automatiques'),
                        content: $t(
                            'En activant cette option, NeuroCheckout pourra ajouter des coupons dedies dans les relances de panier abandonne. Les remises resteront limitees par vos seuils configures et par "Reduction maximale IA (%)". Activez cette option uniquement si cette automatisation est validee en interne.'
                        ),
                        modalClass: 'nc-allow-discount-confirm',
                        buttons: [{
                            text: $t('Autoriser les remises IA'),
                            class: 'action-primary action-accept',
                            click: function (event) {
                                this.closeModal(event, true);
                            }
                        }, {
                            text: $t('Annuler'),
                            class: 'action-secondary action-dismiss',
                            click: function (event) {
                                this.closeModal(event);
                            }
                        }],
                        actions: {
                            confirm: onConfirm,
                            cancel: onCancel
                        }
                    });
                },
                function () {
                    if (window.confirm(nativeMessage)) {
                        onConfirm();
                    } else {
                        onCancel();
                    }
                }
            );
        }

        function openConfirm($field, previousValue) {
            $field.data('ncConfirmOpen', true);

            confirmWithFallback(
                function () {
                    rememberValue($field);
                    clearOpenFlag($field);
                },
                function () {
                    isReverting = true;
                    $field.val(previousValue || '0').trigger('change');
                    isReverting = false;
                    rememberValue($field);
                    clearOpenFlag($field);
                }
            );
        }

        function initializeFields() {
            $(selector).each(function () {
                var $field = $(this);
                if ($field.data('ncAllowDiscountInit')) {
                    return;
                }
                $field.data('ncAllowDiscountInit', true);
                rememberValue($field);
                clearOpenFlag($field);
            });
        }

        $(document).on('focusin.ncAllowDiscountConfirm mousedown.ncAllowDiscountConfirm keydown.ncAllowDiscountConfirm', selector, function () {
            rememberValue($(this));
        });

        $(document).on('change.ncAllowDiscountConfirm', selector, function () {
            if (isReverting) {
                return;
            }

            var $field = $(this);
            var newValue = String($field.val() || '0');
            var previousValue = String($field.data('ncPreviousValue') || '0');

            if (newValue !== '1' || previousValue === '1') {
                rememberValue($field);
                return;
            }

            if ($field.data('ncConfirmOpen')) {
                return;
            }

            openConfirm($field, previousValue);
        });

        $(initializeFields);
        $(document).ajaxComplete(initializeFields);
    }

    return function (config) {
        var selector = config && config.selector ? config.selector : defaultSelector;
        bindSelector(selector);
    };
});
