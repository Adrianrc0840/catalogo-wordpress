jQuery(function ($) {
    let tamanoIndex = $('#fc-tamanos-list .fc-tamano-row').length;

    // ── Añadir tamaño ──
    $('#fc-add-tamano').on('click', function () {
        const template = $('#fc-tamano-template').html().replace(/\{\{INDEX\}\}/g, tamanoIndex);
        const $row = $(template);
        $row.attr('data-tamano-index', tamanoIndex);
        $('#fc-tamanos-list').append($row);
        tamanoIndex++;
    });

    // ── Añadir color (delegado) ──
    $(document).on('click', '.fc-add-color-btn', function () {
        const $tamanoRow = $(this).closest('.fc-tamano-row');
        const tamanoIdx  = $tamanoRow.data('tamano-index');
        const colorIdx   = $tamanoRow.find('.fc-color-row').length;

        const template = $('#fc-color-row-template').html()
            .replace(/\{\{TAMANO_INDEX\}\}/g, tamanoIdx)
            .replace(/\{\{COLOR_INDEX\}\}/g,  colorIdx);

        $tamanoRow.find('.fc-tamano-colores-list').append(template);
    });

    // ── Eliminar fila de tamaño ──
    $(document).on('click', '.fc-remove-row', function () {
        $(this).closest('.fc-tamano-row').remove();
    });

    // ── Eliminar fila de color ──
    $(document).on('click', '.fc-remove-color-row', function () {
        $(this).closest('.fc-color-row').remove();
    });

    // ── Quitar foto ──
    $(document).on('click', '.fc-remove-img-btn', function () {
        const $row = $(this).closest('.fc-color-row, .fc-tamano-row');
        $row.find('.fc-imagen-id').first().val('');
        $row.find('.fc-imagen-url').first().val('');
        $row.find('.fc-preview-img').first().attr('src', '').hide();
        $row.find('.fc-upload-btn').first().text('Subir foto');
        $(this).remove();
    });

    // ── Media uploader ──
    $(document).on('click', '.fc-upload-btn', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const $row = $btn.closest('.fc-color-row, .fc-tamano-row');

        const frame = wp.media({
            title: 'Seleccionar foto',
            button: { text: 'Usar esta foto' },
            multiple: false,
            library: { type: 'image' },
        });

        frame.on('select', function () {
            const attachment = frame.state().get('selection').first().toJSON();
            $row.find('.fc-imagen-id').first().val(attachment.id);
            $row.find('.fc-imagen-url').first().val(attachment.url);
            $row.find('.fc-preview-img').first().attr('src', attachment.url).show();
            $btn.text('Cambiar foto');

            if (!$btn.siblings('.fc-remove-img-btn').length) {
                $btn.after('<button type="button" class="button fc-remove-img-btn">Quitar</button>');
            }
        });

        frame.open();
    });
});
