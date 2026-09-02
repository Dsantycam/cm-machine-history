/**
 * CM Machine History — autocompletado en formularios Forminator
 *
 * v0.8.1 — al escribir el código de máquina se consulta la ficha y se rellenan
 *          marca, modelo, serial, contacto, horómetro, empresa y ciudad.
 * v2.0   — el mismo relleno se puede disparar desde la URL: al abrir el formato
 *          desde una tarea, el enlace trae ?cmh_machine=CODIGO y el formulario
 *          llega listo, sin que el técnico escriba nada.
 */
(function ($) {
    'use strict';

    if (typeof CMHFront === 'undefined') return;

    var ajaxurl = CMHFront.ajaxurl;
    var configs = CMHFront.formConfigs || {};

    // Campos de máquina que podemos rellenar, mapeados a posibles textos de etiqueta.
    // v1.0.1 — las claves deben ser específicas: el match es por CONTENIDO de la
    // etiqueta, así que una palabra suelta como 'horas' capturaba también
    // «¿Cuántas horas estuvo detenida la máquina?» y le metía el horómetro.
    var labelMap = {
        brand:             ['marca', 'brand', 'fabricante'],
        model:             ['modelo', 'model', 'tipo de equipo', 'tipo de máquina', 'tipo de maquina'],
        serial:            ['serial', 'serie', 'número de serie', 'no. serie', 'n° serie', 'no serie'],
        contact:           ['contacto', 'contact', 'encargado', 'operador', 'responsable'],
        current_hourmeter: ['horómetro', 'horometro', 'hourmeter', 'odómetro', 'odometro', 'km'],
        company_name:      ['empresa', 'company', 'cliente', 'razón social', 'razon social'],
        city_name:         ['ciudad', 'sucursal', 'sede', 'ubicación', 'ubicacion', 'city'],
    };

    // v1.0.1 — Etiquetas que NUNCA se autocompletan aunque coincida alguna clave.
    // Son campos que llena el técnico: tiempos de parada, horas trabajadas, etc.
    var labelBlocklist = [
        'detenid', 'parada', 'parado', 'inactiv', 'trabajad', 'duración', 'duracion',
        'cuánto', 'cuanto', 'cuántas', 'cuantas', 'cuántos', 'cuantos', 'firma',
    ];

    function isBlocked(labelText) {
        return labelBlocklist.some(function (kw) {
            return labelText.indexOf(kw) !== -1;
        });
    }

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
            if (isBlocked(labelText)) return;

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

    /** Pinta el aviso verde/rojo bajo el campo de máquina. */
    function showHint($hint, ok, machineOrCode) {
        if (ok) {
            var m = machineOrCode;
            $hint.css({ color: '#00a32a', background: '#e7f7ed' }).html(
                '<strong>✓ ' + (m.brand || '') + ' ' + (m.model || '') + '</strong> — ' +
                (m.company_name || '') + (m.city_name ? ' / ' + m.city_name : '') +
                (m.serial ? '<br><small>Serial: ' + m.serial + '</small>' : '')
            ).show();
        } else {
            $hint.css({ color: '#d63638', background: '#fdeaea' })
                .text('Máquina no encontrada: ' + machineOrCode).show();
        }
    }

    /**
     * Consulta la máquina y rellena el formulario.
     * Es el único camino de relleno: lo usan tanto el autocompletado al escribir
     * como el prellenado desde la URL.
     */
    function lookupAndFill(code, $form, cfg, $hint) {
        $.get(ajaxurl, { action: 'cmh_get_machine', code: code })
            .done(function (resp) {
                if (!resp || !resp.success) {
                    if ($hint) showHint($hint, false, code);
                    return;
                }
                if ($hint) showHint($hint, true, resp.data);
                fillByLabels($form, resp.data);
                fillContactBySlug($form, resp.data, cfg && cfg.contact ? cfg.contact : null);
            })
            .fail(function () { if ($hint) $hint.hide(); });
    }

    function makeHint($input) {
        return $('<div class="cmh-machine-hint"></div>').css({
            fontSize: '12px', margin: '4px 0 0', padding: '5px 10px',
            borderRadius: '4px', display: 'none', lineHeight: '1.5',
        }).insertAfter($input);
    }

    function attachAutocomplete($input, cfg) {
        if ($input.data('cmhBound')) return;
        $input.data('cmhBound', true);

        var $form = $input.closest('form');
        var $hint = makeHint($input);
        var timer;

        $input.on('input change', function () {
            clearTimeout(timer);
            var code = $.trim($(this).val()).toUpperCase();
            if (!code || code.length < 3) { $hint.hide(); return; }

            // Ya consultamos este código: no repetir la llamada. Cubre el caso del
            // prellenado desde la URL —que dispara 'change' para que la lógica
            // condicional de Forminator se entere— y también al salir del campo
            // sin haberlo editado.
            if ($input.data('cmhLastCode') === code) return;

            timer = setTimeout(function () {
                $input.data('cmhLastCode', code);
                lookupAndFill(code, $form, cfg, $hint);
            }, 600);
        });
    }

    // ─── v2.0 — Prellenado desde la URL ───────────────────────────────────────

    function queryParam(name) {
        var m = new RegExp('[?&]' + name + '=([^&#]*)').exec(window.location.search);
        return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : '';
    }

    /**
     * v2.0 — Aplica los valores que el servidor ya resolvió para este formato.
     *
     * `CMHFront.prefill` llega como { form_id: { slug: valor } } según el mapeo
     * configurado en «Máquinas → Formatos». El navegador no decide nada: solo
     * escribe. Si el formulario está en la página se busca dentro de su contenedor
     * para no pisar campos de otro formulario que comparta nombres de slug.
     */
    function applyServerPrefill() {
        var prefill = CMHFront.prefill || {};
        var applied = false;

        Object.keys(prefill).forEach(function (formId) {
            var $scope = $('#forminator-module-' + formId);
            if (!$scope.length) $scope = $(document);

            Object.keys(prefill[formId]).forEach(function (slug) {
                var $f = $scope.find('[name="' + slug + '"]');
                if (!$f.length) $f = $('[name="' + slug + '"]');
                if (!$f.length || $f.data('cmhPrefilled')) return;

                // No se pisa lo que el usuario ya escribió.
                if ($f.val()) { $f.data('cmhPrefilled', true); return; }

                $f.data('cmhPrefilled', true).val(prefill[formId][slug]).trigger('change');
                applied = true;
            });
        });
        return applied;
    }

    /**
     * Escribe el código en el campo de máquina y dispara el relleno completo.
     * Devuelve true si encontró el campo.
     */
    function prefillMachine(code) {
        var found = false;

        Object.keys(fieldMap).forEach(function (fieldName) {
            $('[name="' + fieldName + '"]').each(function () {
                var $input = $(this);
                if ($input.data('cmhMachinePrefilled')) { found = true; return; }
                $input.data('cmhMachinePrefilled', true);
                found = true;

                attachAutocomplete($input, fieldMap[fieldName]);

                // Se marca como ya consultado ANTES de disparar 'change': el evento
                // se lanza para que la lógica condicional de Forminator reaccione,
                // pero no debe provocar una segunda consulta de la misma máquina.
                $input.data('cmhLastCode', code);
                $input.val(code).trigger('change');

                // El relleno se dispara de una, sin esperar el debounce de escritura.
                lookupAndFill(
                    code,
                    $input.closest('form'),
                    fieldMap[fieldName],
                    $input.next('.cmh-machine-hint')
                );
            });
        });

        applyServerPrefill();
        return found;
    }

    /**
     * Forminator a veces pinta el formulario después del DOM ready (paginación,
     * lógica condicional, carga diferida), así que no basta con intentarlo una
     * vez: se reintenta unos segundos y se deja de insistir en cuanto aparece.
     */
    function prefillWhenReady(code) {
        if (prefillMachine(code)) return;

        var tries = 0;
        var timer = setInterval(function () {
            tries++;
            if (prefillMachine(code) || tries >= 25) clearInterval(timer);  // ~10 s
        }, 400);
    }

    $(function () {
        Object.keys(fieldMap).forEach(function (fieldName) {
            $('[name="' + fieldName + '"]').each(function () {
                attachAutocomplete($(this), fieldMap[fieldName]);
            });
        });

        var code = $.trim(queryParam('cmh_machine')).toUpperCase();
        if (code) prefillWhenReady(code);
    });

})(jQuery);
