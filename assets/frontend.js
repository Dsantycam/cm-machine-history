/**
 * CM Machine History — autocompletado en formularios Forminator v0.8.1
 * Escucha el campo de código de máquina, consulta el endpoint AJAX y rellena
 * el campo de contacto si está vacío; muestra un indicador visual de búsqueda.
 */
(function ($) {
    'use strict';

    if (typeof CMHFront === 'undefined') return;

    var ajaxurl = CMHFront.ajaxurl;
    var configs = CMHFront.formConfigs || {};

    // Construir mapa fieldName → contactFieldName a partir de la config PHP
    var fieldMap = {};
    Object.keys(configs).forEach(function (formId) {
        var cfg = configs[formId];
        if (cfg.machine_field) {
            fieldMap[cfg.machine_field] = cfg.contact_field || null;
        }
    });

    function attachAutocomplete($input, contactFieldName) {
        var $form  = $input.closest('form');
        var timer;
        var $hint  = $('<div class="cmh-machine-hint"></div>').css({
            fontSize: '12px', margin: '4px 0 0', padding: '4px 8px',
            borderRadius: '4px', display: 'none'
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
                        var info = [m.brand, m.model, m.company_name, m.city_name]
                            .filter(Boolean).join(' · ');
                        $hint.css({ color: '#00a32a', background: '#e7f7ed' })
                            .text('✓ ' + info).show();

                        if (contactFieldName && m.contact) {
                            var $contact = $form.find('[name="' + contactFieldName + '"]');
                            if ($contact.length && !$contact.val()) {
                                $contact.val(m.contact).trigger('change');
                            }
                        }
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
