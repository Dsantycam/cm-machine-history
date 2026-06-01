/**
 * CM Machine History — autocompletado en formularios Forminator v0.8.1
 */
(function ($) {
    'use strict';

    if (typeof CMHFront === 'undefined') return;

    var ajaxurl = CMHFront.ajaxurl;
    var configs = CMHFront.formConfigs || {};

    // Campos de máquina que podemos rellenar, mapeados a posibles textos de etiqueta
    var labelMap = {
        brand:             ['marca', 'brand', 'fabricante'],
        model:             ['modelo', 'model', 'tipo de equipo', 'tipo'],
        serial:            ['serial', 'serie', 'número de serie', 'no. serie', 'n° serie', 'no serie'],
        contact:           ['contacto', 'contact', 'encargado', 'operador', 'responsable'],
        current_hourmeter: ['horómetro', 'horometro', 'hourmeter', 'horas', 'km', 'odómetro'],
        company_name:      ['empresa', 'company', 'cliente'],
        city_name:         ['ciudad', 'sucursal', 'sede', 'ubicación', 'city'],
    };

    // Campo machine_field → contact_field (del config, como respaldo explícito)
    var fieldMap = {};
    Object.keys(configs).forEach(function (formId) {
        var cfg = configs[formId];
        if (cfg.machine_field) {
            fieldMap[cfg.machine_field] = {
                contact: cfg.contact_field || null,
            };
        }
    });

    /**
     * Intenta rellenar un campo por texto de etiqueta.
     * Busca en .forminator-row y .forminator-field-container para ser compatible
     * con distintas versiones de Forminator.
     */
    function fillByLabels($form, machineData) {
        // Selectores de fila/bloque en Forminator
        var rowSel = '.forminator-row, .forminator-field-container, .forminator-col';

        $form.find(rowSel).each(function () {
            var $row      = $(this);
            var labelText = $row.find('label, .forminator-label').first().text().trim().toLowerCase();
            if (!labelText) return;

            Object.keys(labelMap).forEach(function (prop) {
                var val = machineData[prop];
                if (!val) return;

                var matches = labelMap[prop].some(function (kw) {
                    return labelText.indexOf(kw) !== -1;
                });

                if (matches) {
                    var $inp = $row.find('input[type="text"], input[type="number"], input:not([type]), textarea').first();
                    if ($inp.length && !$inp.val()) {
                        $inp.val(val).trigger('change input');
                    }
                }
            });
        });
    }

    /**
     * Rellena el campo de contacto por slug configurado (respaldo directo).
     */
    function fillContactBySlug($form, machineData, contactFieldName) {
        if (!contactFieldName || !machineData.contact) return;
        var $f = $form.find('[name="' + contactFieldName + '"]');
        if ($f.length && !$f.val()) {
            $f.val(machineData.contact).trigger('change input');
        }
    }

    function attachAutocomplete($input, cfg) {
        var $form  = $input.closest('form');
        var timer;
        var $hint  = $('<div class="cmh-machine-hint"></div>').css({
            fontSize: '12px', margin: '4px 0 0', padding: '5px 10px',
            borderRadius: '4px', display: 'none', lineHeight: '1.5',
        }).insertAfter($input);

        $input.on('input change', function () {
            clearTimeout(timer);
            var code = $.trim($(this).val()).toUpperCase();
            if (!code || code.length < 3) { $hint.hide(); return; }

            timer = setTimeout(function () {
                $.get(ajaxurl, { action: 'cmh_get_machine', code: code })
                    .done(function (resp) {
                        if (!resp.success) {
                            $hint.css({ color: '#d63638', background: '#fdeaea' })
                                .text('Máquina no encontrada: ' + code).show();
                            return;
                        }

                        var m    = resp.data;
                        var info = [m.brand, m.model, m.serial ? '(' + m.serial + ')' : '', m.company_name, m.city_name]
                            .filter(Boolean).join(' · ');

                        $hint.css({ color: '#00a32a', background: '#e7f7ed' }).html(
                            '<strong>✓ ' + (m.brand || '') + ' ' + (m.model || '') + '</strong> — ' +
                            (m.company_name || '') + (m.city_name ? ' / ' + m.city_name : '') +
                            (m.serial ? '<br><small>Serial: ' + m.serial + '</small>' : '')
                        ).show();

                        // Rellenar por etiqueta (marca, modelo, serial, etc.)
                        fillByLabels($form, m);

                        // Rellenar contacto por slug configurado como respaldo
                        fillContactBySlug($form, m, cfg.contact || null);
                    })
                    .fail(function () { $hint.hide(); });
            }, 600);
        });
    }

    $(function () {
        Object.keys(fieldMap).forEach(function (fieldName) {
            $('[name="' + fieldName + '"]').each(function () {
                attachAutocomplete($(this), fieldMap[fieldName]);
            });
        });
    });

})(jQuery);
