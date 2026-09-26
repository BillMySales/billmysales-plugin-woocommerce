/**
 * BillMySales settings page: show/hide the secret, and add/remove checkout
 * fields. Labels come from wp_localize_script() (window.BillMySalesAdmin).
 */
(function () {
    'use strict';

    /**
     * Translated labels (wp_localize_script()).
     *
     * @type {{showSecret: string, hideSecret: string}}
     */
    var labels = window.BillMySalesAdmin || {};

    /**
     * The button next to the secret shows or hides it.
     *
     * @return {void}
     */
    function initSecretToggle() {
        var button = document.getElementById('billmysales-secret-toggle');
        var input = document.getElementById('billmysales-secret');
        if (!button || !input) {
            return;
        }
        button.addEventListener('click', function () {
            var show = input.type === 'password';
            var icon = button.querySelector('.dashicons');
            input.type = show ? 'text' : 'password';
            if (icon) {
                icon.classList.toggle('dashicons-visibility', !show);
                icon.classList.toggle('dashicons-hidden', show);
            }
            button.setAttribute('aria-label', show ? labels.hideSecret : labels.showSecret);
        });
    }

    /**
     * The checkout fields tab: "+ Add field" clones the row template and
     * "Remove this field" removes its row.
     *
     * @return {void}
     */
    function initFields() {
        var container = document.getElementById('billmysales-fields');
        var addButton = document.getElementById('billmysales-add-field');
        var template = document.getElementById('billmysales-field-template');
        if (!container || !addButton || !template) {
            return;
        }
        var nextIndex = parseInt(container.getAttribute('data-next-index'), 10) || 0;
        addButton.addEventListener('click', function () {
            var wrapper = document.createElement('div');
            wrapper.innerHTML = template.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
            container.appendChild(wrapper.firstElementChild);
        });
        container.addEventListener('click', function (event) {
            if (event.target.classList.contains('billmysales-remove-field')) {
                event.preventDefault();
                event.target.closest('.billmysales-field').remove();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initSecretToggle();
        initFields();
    });
})();
