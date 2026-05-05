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

    $posts = get_posts( [ 'post_type' => 'arreglo', 'posts_per_page' => -1, 'post_status' => 'any' ] );

    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="arreglos-' . date( 'Y-m-d' ) . '.csv"' );
    header( 'Pragma: no-cache' );

    $out = fopen( 'php://output', 'w' );
    fputs( $out, "\xEF\xBB\xBF" ); // BOM para Excel

    fputcsv( $out, [
        'ID', 'Nombre', 'Categoria', 'Descripcion', 'Agotado',
        'Tamano_1', 'Precio_1',
        'Tamano_2', 'Precio_2',
        'Tamano_3', 'Precio_3',
        'Tamano_4', 'Precio_4',
        'Tamano_5', 'Precio_5',
    ] );

    foreach ( $posts as $post ) {
        $tamanos = get_post_meta( $post->ID, '_fc_tamanos', true ) ?: [];
        $agotado = get_post_meta( $post->ID, '_fc_agotado', true ) === '1' ? '1' : '0';
        $desc    = get_post_meta( $post->ID, '_fc_descripcion', true );
        $cats    = get_the_terms( $post->ID, 'categoria_arreglo' );
        $cat     = ( ! empty( $cats ) && ! is_wp_error( $cats ) ) ? $cats[0]->name : '';

        $row = [ $post->ID, $post->post_title, $cat, $desc, $agotado ];

        for ( $i = 0; $i < 5; $i++ ) {
            $row[] = $tamanos[ $i ]['nombre'] ?? '';
            $row[] = $tamanos[ $i ]['precio'] ?? '';
        }

        fputcsv( $out, $row );
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

        // Categoría — busca o crea
        if ( ! empty( $row[2] ) ) {
            $term = get_term_by( 'name', $row[2], 'categoria_arreglo' );
            if ( ! $term ) {
                $new = wp_insert_term( sanitize_text_field( $row[2] ), 'categoria_arreglo' );
                if ( ! is_wp_error( $new ) ) $term = get_term( $new['term_id'], 'categoria_arreglo' );
            }
            if ( $term && ! is_wp_error( $term ) ) {
                wp_set_post_terms( $post_id, [ $term->term_id ], 'categoria_arreglo' );
            }
        }

        // Descripción y disponibilidad
        update_post_meta( $post_id, '_fc_descripcion', sanitize_textarea_field( $row[3] ?? '' ) );
        update_post_meta( $post_id, '_fc_agotado',    ( $row[4] ?? '0' ) === '1' ? '1' : '0' );

        // Tamaños — actualiza nombre y precio, conserva fotos existentes
        $existentes  = get_post_meta( $post_id, '_fc_tamanos', true ) ?: [];
        $nuevos      = [];

        for ( $i = 0; $i < 5; $i++ ) {
            $nombre = sanitize_text_field( $row[ 5 + $i * 2 ] ?? '' );
            $precio = floatval( $row[ 6 + $i * 2 ] ?? 0 );
            if ( $nombre === '' ) continue;

            $nuevos[] = [
                'nombre'     => $nombre,
                'precio'     => $precio,
                'imagen_id'  => $existentes[ $i ]['imagen_id']  ?? 0,   // foto intacta
                'imagen_url' => $existentes[ $i ]['imagen_url'] ?? '',   // foto intacta
            ];
        }

        update_post_meta( $post_id, '_fc_tamanos', $nuevos );
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
                <p style="font-size:12px;color:#888;">Las fotos <strong>no</strong> se exportan, solo texto y precios.</p>
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
            <table class="widefat striped" style="font-size:12px;">
                <thead>
                    <tr>
                        <th>ID</th><th>Nombre</th><th>Categoria</th><th>Descripcion</th>
                        <th>Agotado</th><th>Tamano_1</th><th>Precio_1</th><th>Tamano_2</th><th>Precio_2</th><th>...</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>42</td><td>Ramo Rosas</td><td>Rosas</td><td>Descripción aquí</td>
                        <td>0</td><td>Chico</td><td>299</td><td>Grande</td><td>499</td><td>...</td>
                    </tr>
                </tbody>
            </table>
            <p style="font-size:12px;color:#888;margin:12px 0 0;">
                <strong>ID:</strong> No modificar &nbsp;|&nbsp;
                <strong>Agotado:</strong> 0 = Disponible, 1 = Agotado &nbsp;|&nbsp;
                Soporta hasta 5 tamaños por arreglo.
            </p>
        </div>
    </div>
    <?php
}
