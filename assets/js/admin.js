/**
 * JS de la pantalla de ajustes de BillMySales.
 *
 * Reemplaza el <script> inline que existía antes (v3.7.0). Se carga con
 * wp_enqueue_script() únicamente en la página del plugin (ver
 * admin_enqueue_scripts en BillMySales.php), y usa los valores pasados
 * por wp_localize_script() en window.WconAdmin: nextIndex, showLabel,
 * hideLabel.
 *
 * Cubre dos comportamientos independientes que pueden o no estar
 * presentes en la pantalla actual, según la pestaña:
 *   1) Mostrar/ocultar el secreto (pestaña "Configuración").
 *   2) Agregar/eliminar filas de campos personalizados (pestaña
 *      "Campos personalizados").
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        wconInitSecretToggle();
        wconInitCustomFieldsRepeater();
    });

    /**
     * Botón de mostrar/ocultar el secreto en la pestaña "Configuración".
     * No hace nada si esos elementos no existen en la pestaña actual.
     */
    function wconInitSecretToggle() {
        var button = document.querySelector('.wp-hide-pw');
        var input = document.getElementById('wcon-secret-field');

        if (!button || !input) {
            return;
        }

        button.addEventListener('click', function () {
            var isHidden = button.getAttribute('data-toggle') === '0';
            var icon = button.querySelector('.dashicons');
            var labels = window.WconAdmin || {};

            if (isHidden) {
                input.type = 'text';
                if (icon) {
                    icon.classList.remove('dashicons-visibility');
                    icon.classList.add('dashicons-hidden');
                }
                button.setAttribute('data-toggle', '1');
                button.setAttribute('aria-label', labels.hideLabel || 'Ocultar clave');
            } else {
                input.type = 'password';
                if (icon) {
                    icon.classList.remove('dashicons-hidden');
                    icon.classList.add('dashicons-visibility');
                }
                button.setAttribute('data-toggle', '0');
                button.setAttribute('aria-label', labels.showLabel || 'Mostrar clave');
            }
        });
    }

    /**
     * Agregar/eliminar filas de campos personalizados en la pestaña
     * "Campos personalizados". No hace nada si esos elementos no existen
     * en la pestaña actual.
     */
    function wconInitCustomFieldsRepeater() {
        var container = document.getElementById('wcon-fields');
        var addButton = document.getElementById('wcon-add-field');
        var template = document.getElementById('wcon-field-template');

        if (!container || !addButton || !template) {
            return;
        }

        var labels = window.WconAdmin || {};
        var nextIndex = parseInt(labels.nextIndex, 10);
        if (isNaN(nextIndex)) {
            nextIndex = container.querySelectorAll('.wcon-field').length;
        }

        addButton.addEventListener('click', function () {
            var html = template.innerHTML.replace(/__INDEX__/g, nextIndex);
            var wrapper = document.createElement('div');
            wrapper.innerHTML = html;
            container.appendChild(wrapper.firstElementChild);
            nextIndex++;
        });

        container.addEventListener('click', function (event) {
            if (event.target.classList.contains('wcon-remove-field')) {
                event.preventDefault();
                event.target.closest('.wcon-field').remove();
            }
        });
    }
})();
