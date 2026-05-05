jQuery(function ($) {
    let rowIndex = $('#fc-tamanos-list .fc-tamano-row').length;

    // Añadir nuevo tamaño
    $('#fc-add-tamano').on('click', function () {
        const template = $('#fc-tamano-template').html().replace(/\{\{INDEX\}\}/g, rowIndex);
        $('#fc-tamanos-list').append(template);
        rowIndex++;
    });

    // Eliminar fila
    $(document).on('click', '.fc-remove-row', function () {
        $(this).closest('.fc-tamano-row').remove();
    });

    // Quitar foto
    $(document).on('click', '.fc-remove-img-btn', function () {
        const $row = $(this).closest('.fc-tamano-row');
        $row.find('.fc-imagen-id').val('');
        $row.find('.fc-imagen-url').val('');
        $row.find('.fc-preview-img').attr('src', '').hide();
        $row.find('.fc-upload-btn').text('Subir foto');
        $(this).remove();
    });

    // Media uploader de WordPress
    $(document).on('click', '.fc-upload-btn', function (e) {
        e.preventDefault();
        const $btn = $(this);
        const $row = $btn.closest('.fc-tamano-row');

        const frame = wp.media({
            title: 'Seleccionar foto del tamaño',
            button: { text: 'Usar esta foto' },
            multiple: false,
            library: { type: 'image' },
        });

        frame.on('select', function () {
            const attachment = frame.state().get('selection').first().toJSON();
            $row.find('.fc-imagen-id').val(attachment.id);
            $row.find('.fc-imagen-url').val(attachment.url);
            $row.find('.fc-preview-img').attr('src', attachment.url).show();
            $btn.text('Cambiar foto');

            if (!$row.find('.fc-remove-img-btn').length) {
                $btn.after('<button type="button" class="button fc-remove-img-btn">Quitar</button>');
            }
        });

        frame.open();
    });
});
