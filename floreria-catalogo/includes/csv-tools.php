<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Submenú ──
add_action( 'admin_menu', 'fc_add_csv_page' );
function fc_add_csv_page() {
    add_submenu_page(
        'edit.php?post_type=arreglo',
        'Exportar / Importar',
        'Exportar / Importar',
        'manage_options',
        'fc-csv',
        'fc_render_csv_page'
    );
}

// ── Exportar ──
add_action( 'admin_init', 'fc_handle_export' );
function fc_handle_export() {
    if ( ! isset( $_GET['fc_export'] ) || $_GET['fc_export'] !== '1' ) return;
    if ( ! check_admin_referer( 'fc_export' ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    $posts = get_posts( [ 'post_type' => 'arreglo', 'posts_per_page' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC' ] );

    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="arreglos-' . date( 'Y-m-d' ) . '.csv"' );
    header( 'Pragma: no-cache' );

    $out = fopen( 'php://output', 'w' );
    fputs( $out, "\xEF\xBB\xBF" ); // BOM para Excel

    fputcsv( $out, [
        'Arreglo_ID', 'Nombre', 'Categoria', 'Descripcion', 'Agotado', 'Especial',
        'Tamanos (nombre:precio separados por |)',
        'Colores (nombre:hex separados por |)',
    ] );

    foreach ( $posts as $post ) {
        $tamanos = get_post_meta( $post->ID, '_fc_tamanos', true ) ?: [];
        $colores = get_post_meta( $post->ID, '_fc_colores',  true ) ?: [];
        $agotado = get_post_meta( $post->ID, '_fc_agotado',  true ) === '1' ? '1' : '0';
        $especial = get_post_meta( $post->ID, '_fc_especial', true ) === '1' ? '1' : '0';
        $desc    = get_post_meta( $post->ID, '_fc_descripcion', true );
        $cats    = get_the_terms( $post->ID, 'categoria_arreglo' );
        $cat     = ( ! empty( $cats ) && ! is_wp_error( $cats ) ) ? implode( ', ', wp_list_pluck( $cats, 'name' ) ) : '';

        // Empacar tamaños: "6 flores:299|12 flores:450"
        $tamanos_str = implode( '|', array_map( function( $t ) {
            return ( $t['nombre'] ?? '' ) . ':' . ( $t['precio'] ?? '0' );
        }, $tamanos ) );

        // Empacar colores: "Rojo:#FF0000|Rosa:#FF69B4"
        $colores_str = implode( '|', array_map( function( $c ) {
            return ( $c['nombre'] ?? '' ) . ':' . ( $c['hex'] ?? '#000000' );
        }, $colores ) );

        fputcsv( $out, [
            $post->ID,
            $post->post_title,
            $cat,
            $desc,
            $agotado,
            $especial,
            $tamanos_str,
            $colores_str,
        ] );
    }

    fclose( $out );
    exit;
}

// ── Importar ──
add_action( 'admin_init', 'fc_handle_import' );
function fc_handle_import() {
    if ( ! isset( $_POST['fc_import_submit'] ) ) return;
    if ( ! check_admin_referer( 'fc_import' ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! isset( $_FILES['fc_csv_file'] ) || $_FILES['fc_csv_file']['error'] !== UPLOAD_ERR_OK ) return;

    $file   = $_FILES['fc_csv_file']['tmp_name'];
    $handle = fopen( $file, 'r' );
    if ( ! $handle ) return;

    // Saltar BOM si existe
    $bom = fread( $handle, 3 );
    if ( $bom !== "\xEF\xBB\xBF" ) rewind( $handle );

    fgetcsv( $handle ); // saltar encabezados

    $updated = 0;
    $errores = [];

    while ( ( $row = fgetcsv( $handle ) ) !== false ) {
        if ( empty( $row[0] ) ) continue;

        $post_id = intval( $row[0] );
        $post    = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'arreglo' ) {
            $errores[] = "ID $post_id no encontrado, se omitió.";
            continue;
        }

        // Nombre
        wp_update_post( [ 'ID' => $post_id, 'post_title' => sanitize_text_field( $row[1] ?? '' ) ] );

        // Categorías — múltiples separadas por coma
        if ( ! empty( $row[2] ) ) {
            $term_ids = [];
            foreach ( array_map( 'trim', explode( ',', $row[2] ) ) as $cat_name ) {
                if ( $cat_name === '' ) continue;
                $term = get_term_by( 'name', $cat_name, 'categoria_arreglo' );
                if ( ! $term ) {
                    $new  = wp_insert_term( sanitize_text_field( $cat_name ), 'categoria_arreglo' );
                    $term = ! is_wp_error( $new ) ? get_term( $new['term_id'], 'categoria_arreglo' ) : null;
                }
                if ( $term && ! is_wp_error( $term ) ) $term_ids[] = $term->term_id;
            }
            if ( ! empty( $term_ids ) ) wp_set_post_terms( $post_id, $term_ids, 'categoria_arreglo' );
        }

        // Descripción, agotado, especial
        update_post_meta( $post_id, '_fc_descripcion', sanitize_textarea_field( $row[3] ?? '' ) );
        update_post_meta( $post_id, '_fc_agotado',    ( $row[4] ?? '0' ) === '1' ? '1' : '0' );
        update_post_meta( $post_id, '_fc_especial',   ( $row[5] ?? '0' ) === '1' ? '1' : '0' );

        // Tamaños — "6 flores:299|12 flores:450"
        // Indexar existentes por nombre para conservar fotos aunque cambie la posición
        $existentes_tam     = get_post_meta( $post_id, '_fc_tamanos', true ) ?: [];
        $existentes_tam_map = [];
        foreach ( $existentes_tam as $t ) {
            if ( ! empty( $t['nombre'] ) ) $existentes_tam_map[ $t['nombre'] ] = $t;
        }
        $nuevos_tam = [];

        if ( ! empty( $row[6] ) ) {
            foreach ( explode( '|', $row[6] ) as $parte ) {
                $partes = explode( ':', trim( $parte ), 2 );
                $nombre = sanitize_text_field( $partes[0] ?? '' );
                $precio = floatval( $partes[1] ?? 0 );
                if ( $nombre === '' ) continue;

                // Buscar foto por nombre exacto, si no existe queda vacía
                $existente   = $existentes_tam_map[ $nombre ] ?? [];
                $nuevos_tam[] = [
                    'nombre'     => $nombre,
                    'precio'     => $precio,
                    'imagen_id'  => $existente['imagen_id']  ?? 0,
                    'imagen_url' => $existente['imagen_url'] ?? '',
                ];
            }
        }
        update_post_meta( $post_id, '_fc_tamanos', $nuevos_tam );

        // Colores — "Rojo:#FF0000|Rosa:#FF69B4"
        // Indexar existentes por nombre para conservar fotos aunque cambie la posición
        $existentes_col     = get_post_meta( $post_id, '_fc_colores', true ) ?: [];
        $existentes_col_map = [];
        foreach ( $existentes_col as $c ) {
            if ( ! empty( $c['nombre'] ) ) $existentes_col_map[ $c['nombre'] ] = $c;
        }
        $nuevos_col = [];

        if ( ! empty( $row[7] ) ) {
            foreach ( explode( '|', $row[7] ) as $parte ) {
                $partes = explode( ':', trim( $parte ), 2 );
                $nombre = sanitize_text_field( $partes[0] ?? '' );
                $hex    = sanitize_hex_color( $partes[1] ?? '' ) ?: '#000000';
                if ( $nombre === '' ) continue;

                $existente    = $existentes_col_map[ $nombre ] ?? [];
                $nuevos_col[] = [
                    'nombre'     => $nombre,
                    'hex'        => $hex,
                    'imagen_id'  => $existente['imagen_id']  ?? 0,
                    'imagen_url' => $existente['imagen_url'] ?? '',
                ];
            }
        }
        update_post_meta( $post_id, '_fc_colores', $nuevos_col );

        $updated++;
    }

    fclose( $handle );

    set_transient( 'fc_import_result', [ 'updated' => $updated, 'errores' => $errores ], 60 );
    wp_safe_redirect( admin_url( 'edit.php?post_type=arreglo&page=fc-csv&fc_imported=1' ) );
    exit;
}

// ── Página ──
function fc_render_csv_page() {
    $result = get_transient( 'fc_import_result' );
    if ( $result ) delete_transient( 'fc_import_result' );
    ?>
    <div class="wrap">
        <h1>Exportar / Importar Arreglos</h1>

        <?php if ( $result ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><strong><?php echo $result['updated']; ?> arreglo(s) actualizados correctamente.</strong></p>
            <?php foreach ( $result['errores'] as $e ) : ?>
            <p style="color:#c00;"><?php echo esc_html( $e ); ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:900px;margin-top:24px;">

            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:24px;">
                <h2 style="margin-top:0;">&#11015; Exportar CSV</h2>
                <p>Descarga todos tus arreglos en un archivo CSV para editarlos en Excel o Google Sheets.</p>
                <p style="font-size:12px;color:#888;">Las fotos <strong>no</strong> se exportan, solo texto, precios y colores.</p>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'edit.php?post_type=arreglo&fc_export=1' ), 'fc_export' ) ); ?>"
                   class="button button-primary">Descargar CSV</a>
            </div>

            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:24px;">
                <h2 style="margin-top:0;">&#11014; Importar CSV</h2>
                <p>Sube el CSV modificado para actualizar los arreglos. <strong>Las fotos no se tocan.</strong></p>
                <p style="font-size:12px;color:#c44;font-weight:600;">&#9888; Solo actualiza arreglos existentes por ID. No crea nuevos.</p>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'fc_import' ); ?>
                    <input type="file" name="fc_csv_file" accept=".csv" style="display:block;margin-bottom:12px;" required />
                    <input type="submit" name="fc_import_submit" class="button button-primary" value="Subir e Importar" />
                </form>
            </div>

        </div>

        <div style="max-width:900px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:24px;margin-top:24px;">
            <h3 style="margin-top:0;">&#128196; Estructura del CSV</h3>
            <p style="font-size:13px;color:#555;margin-bottom:16px;">
                Los tamaños y colores se guardan en una sola celda, separados por <code>|</code>.<br>
                <strong>Tamaños:</strong> <code>nombre:precio|nombre:precio</code> &nbsp;→&nbsp; <code>6 flores:299|12 flores:450|24 flores:800</code><br>
                <strong>Colores:</strong> <code>nombre:#hex|nombre:#hex</code> &nbsp;→&nbsp; <code>Rojo:#FF0000|Rosa:#FF69B4</code>
            </p>
            <table class="widefat striped" style="font-size:12px;">
                <thead>
                    <tr>
                        <th>ID</th><th>Nombre</th><th>Categoria</th><th>Descripcion</th>
                        <th>Agotado</th><th>Especial</th><th>Tamaños</th><th>Colores</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>42</td>
                        <td>Pink Touch</td>
                        <td>Gerberas, Ramos</td>
                        <td>Descripción aquí</td>
                        <td>0</td>
                        <td>0</td>
                        <td>6 flores:299|12 flores:450</td>
                        <td>Rojo:#FF0000|Rosa:#FF69B4</td>
                    </tr>
                </tbody>
            </table>
            <p style="font-size:12px;color:#888;margin:12px 0 0;">
                <strong>ID:</strong> No modificar &nbsp;|&nbsp;
                <strong>Agotado / Especial:</strong> 0 = No, 1 = Sí &nbsp;|&nbsp;
                Sin límite de tamaños ni colores por arreglo.
            </p>
        </div>
    </div>
    <?php
}
