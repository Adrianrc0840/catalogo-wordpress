<?php
/**
 * Punto de Venta (PDV) — Backend: rewrite, enqueue, AJAX handlers
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────
// Rewrite URL: /pdv/
// ─────────────────────────────────────────────
add_action( 'init', 'fc_pdv_rewrite' );
function fc_pdv_rewrite() {
    add_rewrite_rule( '^pdv/?$', 'index.php?fc_pdv=1', 'top' );
}

add_filter( 'query_vars', 'fc_pdv_query_vars' );
function fc_pdv_query_vars( $vars ) {
    $vars[] = 'fc_pdv';
    return $vars;
}

add_filter( 'template_include', 'fc_pdv_template_include' );
function fc_pdv_template_include( $template ) {
    if ( get_query_var( 'fc_pdv' ) ) {
        $custom = FC_PATH . 'templates/pdv.php';
        if ( file_exists( $custom ) ) return $custom;
    }
    return $template;
}

add_action( 'send_headers', 'fc_pdv_nocache_headers' );
function fc_pdv_nocache_headers() {
    if ( get_query_var( 'fc_pdv' ) ) {
        nocache_headers();
        header( 'X-LiteSpeed-Cache-Control: no-cache' );
    }
}

// ─────────────────────────────────────────────
// Enqueue PDV assets
// ─────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'fc_enqueue_pdv' );
function fc_enqueue_pdv() {
    if ( ! get_query_var( 'fc_pdv' ) ) return;

    $gmaps_key = get_option( 'fc_gmaps_key', '' );
    $deps      = [];

    if ( $gmaps_key ) {
        wp_enqueue_script(
            'google-places',
            'https://maps.googleapis.com/maps/api/js?key=' . urlencode( $gmaps_key ) . '&libraries=places',
            [], null, true
        );
        $deps[] = 'google-places';
    }

    wp_enqueue_style(  'fc-pdv', FC_URL . 'assets/css/pdv.css', [], FC_VERSION );
    wp_enqueue_script( 'fc-pdv', FC_URL . 'assets/js/pdv.js',  $deps,  FC_VERSION, true );

    $tz    = new DateTimeZone( 'America/Tijuana' );
    $today = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );

    wp_localize_script( 'fc-pdv', 'fcPdv', [
        'ajaxurl'          => admin_url( 'admin-ajax.php' ),
        'nonce'            => wp_create_nonce( 'fc_pdv_nonce' ),
        'siteurl'          => home_url(),
        'today'            => $today,
        'schedules'        => fc_get_schedules(),
        'fechasEspeciales' => fc_get_fechas_especiales(),
        'gmapsKey'         => $gmaps_key,
    ] );
}

// ─────────────────────────────────────────────
// Helpers de autenticación PDV (solo admin)
// ─────────────────────────────────────────────
function fc_pdv_verify_nonce() {
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'fc_pdv_nonce' ) ) {
        wp_send_json_error( [ 'message' => 'Nonce inválido.' ], 403 );
    }
}

function fc_pdv_require_admin() {
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    }
}

// ─────────────────────────────────────────────
// AJAX: Check auth
// ─────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_fc_pdv_check_auth', 'fc_ajax_pdv_check_auth' );
add_action( 'wp_ajax_fc_pdv_check_auth',        'fc_ajax_pdv_check_auth' );
function fc_ajax_pdv_check_auth() {
    wp_send_json_success( [
        'logged_in' => is_user_logged_in(),
        'is_admin'  => is_user_logged_in() && current_user_can( 'manage_options' ),
    ] );
}

// ─────────────────────────────────────────────
// AJAX: Login
// ─────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_fc_pdv_login', 'fc_ajax_pdv_login' );
add_action( 'wp_ajax_fc_pdv_login',        'fc_ajax_pdv_login' );
function fc_ajax_pdv_login() {
    $username = sanitize_user( wp_unslash( $_POST['username'] ?? '' ) );
    $password = wp_unslash( $_POST['password'] ?? '' );

    if ( ! $username || ! $password ) {
        wp_send_json_error( [ 'message' => 'Usuario y contraseña requeridos.' ] );
    }

    $user = wp_authenticate( $username, $password );
    if ( is_wp_error( $user ) ) {
        wp_send_json_error( [ 'message' => 'Usuario o contraseña incorrectos.' ] );
    }
    if ( ! user_can( $user, 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Solo los administradores pueden acceder al PDV.' ] );
    }

    wp_set_current_user( $user->ID );
    wp_set_auth_cookie( $user->ID, true );

    wp_send_json_success( [
        'message' => 'Sesión iniciada.',
        'nonce'   => wp_create_nonce( 'fc_pdv_nonce' ),
    ] );
}

// ─────────────────────────────────────────────
// AJAX: Logout
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_pdv_logout', 'fc_ajax_pdv_logout' );
function fc_ajax_pdv_logout() {
    wp_logout();
    wp_send_json_success( [ 'message' => 'Sesión cerrada.' ] );
}

// ─────────────────────────────────────────────
// Helper: construir datos completos de un arreglo para el PDV
// ─────────────────────────────────────────────
function fc_pdv_build_arreglo_data( $p ) {
    $tamanos_raw = get_post_meta( $p->ID, '_fc_tamanos', true );
    if ( ! is_array( $tamanos_raw ) ) $tamanos_raw = [];

    $thumb = '';
    $tamanos = [];

    foreach ( $tamanos_raw as $t ) {
        if ( empty( $t['nombre'] ) ) continue; // ignorar entradas vacías

        $imagen_url = $t['imagen_url'] ?? '';

        // Determinar la foto de portada del catálogo
        if ( ! empty( $t['foto_catalogo'] ) && $t['foto_catalogo'] === '1' && $imagen_url ) {
            $thumb = $imagen_url;
        }
        if ( ! $thumb && $imagen_url ) {
            $thumb = $imagen_url; // primera foto disponible como fallback
        }

        // Colores de este tamaño
        $colores = [];
        if ( ! empty( $t['colores'] ) && is_array( $t['colores'] ) ) {
            foreach ( $t['colores'] as $c ) {
                if ( empty( $c['nombre'] ) ) continue;
                $colores[] = [
                    'nombre'     => $c['nombre'],
                    'hex'        => $c['hex']        ?? '#c8185a',
                    'imagen_url' => $c['imagen_url'] ?? '',
                ];
            }
        }

        $tamanos[] = [
            'label'      => $t['nombre'],
            'precio'     => (float) ( $t['precio'] ?? 0 ),
            'imagen_url' => $imagen_url,
            'colores'    => $colores,
        ];
    }

    // Fallback de foto al thumbnail de WP si no hay ninguna
    if ( ! $thumb ) {
        $thumb = get_the_post_thumbnail_url( $p->ID, 'medium' ) ?: '';
    }

    return [
        'id'          => $p->ID,
        'nombre'      => $p->post_title,
        'descripcion' => get_post_meta( $p->ID, '_fc_descripcion', true ) ?: '',
        'thumb'       => $thumb,
        'tamanos'     => $tamanos,
    ];
}

// ─────────────────────────────────────────────
// AJAX: Obtener catálogo completo (categorías + arreglos)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_pdv_get_catalogo', 'fc_ajax_pdv_get_catalogo' );
function fc_ajax_pdv_get_catalogo() {
    fc_pdv_verify_nonce();
    fc_pdv_require_admin();

    $categorias = get_terms( [
        'taxonomy'   => 'categoria_arreglo',
        'hide_empty' => true,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ] );

    $result = [];

    foreach ( $categorias as $cat ) {
        $posts = get_posts( [
            'post_type'      => 'arreglo',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'tax_query'      => [ [
                'taxonomy' => 'categoria_arreglo',
                'field'    => 'term_id',
                'terms'    => $cat->term_id,
            ] ],
        ] );

        $arreglos = array_map( 'fc_pdv_build_arreglo_data', $posts );

        if ( $arreglos ) {
            $result[] = [
                'id'       => $cat->term_id,
                'nombre'   => $cat->name,
                'arreglos' => $arreglos,
            ];
        }
    }

    // Arreglos sin categoría
    $sin_cat = get_posts( [
        'post_type'      => 'arreglo',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'tax_query'      => [ [
            'taxonomy' => 'categoria_arreglo',
            'operator' => 'NOT EXISTS',
        ] ],
    ] );

    if ( $sin_cat ) {
        $result[] = [
            'id'       => 0,
            'nombre'   => 'Sin categoría',
            'arreglos' => array_map( 'fc_pdv_build_arreglo_data', $sin_cat ),
        ];
    }

    header( 'Content-Type: application/json; charset=utf-8' );
    echo json_encode( [ 'success' => true, 'data' => [ 'categorias' => $result ] ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    wp_die();
}

// ─────────────────────────────────────────────
// AJAX: Crear venta PDV
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_pdv_crear_venta', 'fc_ajax_pdv_crear_venta' );
function fc_ajax_pdv_crear_venta() {
    fc_pdv_verify_nonce();
    fc_pdv_require_admin();

    $fecha_entrega = sanitize_text_field( wp_unslash( $_POST['fecha']    ?? '' ) );
    $numero        = fc_generar_numero_pedido( $fecha_entrega );
    $token         = fc_generar_token();

    $post_id = wp_insert_post( [
        'post_type'   => 'pedido',
        'post_status' => 'publish',
        'post_title'  => $numero,
    ] );

    if ( is_wp_error( $post_id ) ) {
        wp_send_json_error( [ 'message' => 'Error al crear el pedido.' ] );
    }

    // Parse items
    $items_json_raw = wp_unslash( $_POST['items_json'] ?? '' );
    $items_raw      = json_decode( $items_json_raw, true );
    $items_clean    = [];
    if ( is_array( $items_raw ) ) {
        foreach ( $items_raw as $item ) {
            $items_clean[] = [
                'arreglo_id'             => (int)                  ( $item['arreglo_id']             ?? 0  ),
                'arreglo_nombre'         => sanitize_text_field(     $item['arreglo_nombre']         ?? '' ),
                'imagen_url'             => esc_url_raw(             $item['imagen_url']             ?? '' ),
                'fotos_extra'            => [],
                'tamano'                 => sanitize_text_field(     $item['tamano']                 ?? '' ),
                'color'                  => sanitize_text_field(     $item['color']                  ?? '' ),
                'precio'                 => (float)                ( $item['precio']                 ?? 0  ),
                'destinatario'           => sanitize_text_field(     $item['destinatario']           ?? '' ),
                'destinatario_telefono'  => sanitize_text_field(     $item['destinatario_telefono']  ?? '' ),
                'destinatario_telefono2' => sanitize_text_field(     $item['destinatario_telefono2'] ?? '' ),
                'mensaje_tarjeta'        => sanitize_textarea_field( $item['mensaje_tarjeta']        ?? '' ),
            ];
        }
    }
    $first = $items_clean[0] ?? [];

    // Caja activa
    $cajas    = fc_get_cajas_abiertas();
    $caja_id  = ! empty( $cajas ) ? $cajas[0]->ID : 0;

    $tz = new DateTimeZone( 'America/Tijuana' );
    $ts = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

    $fields = [
        '_fc_pedido_numero'                  => $numero,
        '_fc_pedido_token'                   => $token,
        '_fc_pedido_status'                  => 'aceptado',
        '_fc_pedido_tipo'                    => sanitize_key(          $_POST['tipo']             ?? 'recoleccion' ),
        '_fc_pedido_fecha'                   => $fecha_entrega,
        '_fc_pedido_horario'                 => sanitize_text_field(   $_POST['horario']          ?? '' ),
        '_fc_pedido_direccion'               => sanitize_text_field(   $_POST['direccion']        ?? '' ),
        '_fc_pedido_hora_recoleccion'        => sanitize_text_field(   $_POST['hora_recoleccion'] ?? '' ),
        '_fc_pedido_canal'                   => 'pdv',
        '_fc_pedido_canal_nombre'            => sanitize_text_field(   $_POST['canal_nombre']     ?? '' ),
        '_fc_pedido_canal_contacto'          => sanitize_text_field(   $_POST['canal_contacto']   ?? '' ),
        '_fc_pedido_nota'                    => sanitize_textarea_field( $_POST['nota']           ?? '' ),
        '_fc_pedido_registrado_por'          => get_current_user_id(),
        '_fc_pedido_monto'                   => (float) ( $_POST['monto_total']   ?? 0 ),
        '_fc_pedido_forma_pago'              => sanitize_key( $_POST['forma_pago'] ?? 'efectivo' ),
        '_fc_pedido_caja_id'                 => $caja_id,
        '_fc_pedido_fecha_venta'             => $ts,
        // Legacy single-item
        '_fc_pedido_arreglo_id'              => $first['arreglo_id']            ?? 0,
        '_fc_pedido_arreglo_nombre'          => $first['arreglo_nombre']        ?? '',
        '_fc_pedido_tamano'                  => $first['tamano']                ?? '',
        '_fc_pedido_color'                   => $first['color']                 ?? '',
        '_fc_pedido_destinatario'            => $first['destinatario']          ?? '',
        '_fc_pedido_destinatario_telefono'   => $first['destinatario_telefono'] ?? '',
        '_fc_pedido_mensaje_tarjeta'         => $first['mensaje_tarjeta']       ?? '',
    ];

    foreach ( $fields as $key => $value ) {
        update_post_meta( $post_id, $key, $value );
    }

    if ( ! empty( $items_clean ) ) {
        update_post_meta( $post_id, '_fc_pedido_items', json_encode( $items_clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
    }

    $current_user = wp_get_current_user();
    update_post_meta( $post_id, '_fc_pedido_historial', maybe_serialize( [ [
        'status'    => 'aceptado',
        'user_id'   => get_current_user_id(),
        'user_name' => $current_user->display_name,
        'timestamp' => $ts,
    ] ] ) );

    wp_send_json_success( [
        'message'    => 'Venta registrada.',
        'numero'     => $numero,
        'token'      => $token,
        'client_url' => home_url( '/pedido/' . $token ),
        'pedido_id'  => $post_id,
    ] );
}

// ─────────────────────────────────────────────
// AJAX: Obtener cajas (abiertas + historial)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_pdv_get_cajas', 'fc_ajax_pdv_get_cajas' );
function fc_ajax_pdv_get_cajas() {
    fc_pdv_verify_nonce();
    fc_pdv_require_admin();

    $abiertas = array_map( fn( $c ) => fc_build_caja_data( $c->ID ), fc_get_cajas_abiertas() );

    $cerradas_posts = get_posts( [
        'post_type'      => 'fc_caja',
        'post_status'    => 'publish',
        'posts_per_page' => 30,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [ [
            'key'   => '_fc_caja_status',
            'value' => 'cerrada',
        ] ],
    ] );
    $cerradas = array_map( fn( $c ) => fc_build_caja_data( $c->ID ), $cerradas_posts );

    wp_send_json_success( [
        'abiertas' => $abiertas,
        'cerradas' => $cerradas,
    ] );
}

// ─────────────────────────────────────────────
// AJAX: Abrir caja
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_pdv_abrir_caja', 'fc_ajax_pdv_abrir_caja' );
function fc_ajax_pdv_abrir_caja() {
    fc_pdv_verify_nonce();
    fc_pdv_require_admin();

    $saldo_inicial = (float) ( $_POST['saldo_inicial'] ?? 0 );
    $tz  = new DateTimeZone( 'America/Tijuana' );
    $now = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

    $post_id = wp_insert_post( [
        'post_type'   => 'fc_caja',
        'post_status' => 'publish',
        'post_title'  => 'Caja ' . ( new DateTime( 'now', $tz ) )->format( 'd/m/Y H:i' ),
    ] );

    if ( is_wp_error( $post_id ) ) {
        wp_send_json_error( [ 'message' => 'Error al abrir la caja.' ] );
    }

    update_post_meta( $post_id, '_fc_caja_status',         'abierta' );
    update_post_meta( $post_id, '_fc_caja_apertura',        $now );
    update_post_meta( $post_id, '_fc_caja_saldo_inicial',   $saldo_inicial );
    update_post_meta( $post_id, '_fc_caja_movimientos',     '[]' );

    wp_send_json_success( [
        'message' => 'Caja abierta.',
        'caja'    => fc_build_caja_data( $post_id ),
    ] );
}

// ─────────────────────────────────────────────
// AJAX: Cerrar caja
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_pdv_cerrar_caja', 'fc_ajax_pdv_cerrar_caja' );
function fc_ajax_pdv_cerrar_caja() {
    fc_pdv_verify_nonce();
    fc_pdv_require_admin();

    $caja_id     = (int) ( $_POST['caja_id'] ?? 0 );
    $saldo_final = (float) ( $_POST['saldo_final'] ?? 0 );

    if ( ! $caja_id || get_post_type( $caja_id ) !== 'fc_caja' ) {
        wp_send_json_error( [ 'message' => 'Caja no encontrada.' ] );
    }

    $tz  = new DateTimeZone( 'America/Tijuana' );
    $now = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

    update_post_meta( $caja_id, '_fc_caja_status',      'cerrada' );
    update_post_meta( $caja_id, '_fc_caja_cierre',       $now );
    update_post_meta( $caja_id, '_fc_caja_saldo_final',  $saldo_final );

    wp_send_json_success( [
        'message' => 'Caja cerrada.',
        'caja'    => fc_build_caja_data( $caja_id ),
    ] );
}

// ─────────────────────────────────────────────
// AJAX: Agregar movimiento a la caja (entrada o salida)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_pdv_movimiento_caja', 'fc_ajax_pdv_movimiento_caja' );
function fc_ajax_pdv_movimiento_caja() {
    fc_pdv_verify_nonce();
    fc_pdv_require_admin();

    $caja_id     = (int) ( $_POST['caja_id'] ?? 0 );
    $tipo        = sanitize_key( $_POST['tipo'] ?? '' );
    $monto       = (float) ( $_POST['monto'] ?? 0 );
    $descripcion = sanitize_text_field( wp_unslash( $_POST['descripcion'] ?? '' ) );

    if ( ! $caja_id || ! in_array( $tipo, [ 'entrada', 'salida' ], true ) || $monto <= 0 ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos.' ] );
    }
    if ( get_post_type( $caja_id ) !== 'fc_caja' ) {
        wp_send_json_error( [ 'message' => 'Caja no encontrada.' ] );
    }

    $tz  = new DateTimeZone( 'America/Tijuana' );
    $now = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

    $mov_raw = get_post_meta( $caja_id, '_fc_caja_movimientos', true );
    $movs    = is_string( $mov_raw ) ? json_decode( $mov_raw, true ) : [];
    $movs    = is_array( $movs ) ? $movs : [];

    $movs[] = [
        'tipo'        => $tipo,
        'monto'       => $monto,
        'descripcion' => $descripcion,
        'timestamp'   => $now,
        'user_name'   => wp_get_current_user()->display_name,
    ];

    update_post_meta( $caja_id, '_fc_caja_movimientos', json_encode( $movs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

    wp_send_json_success( [
        'message' => ucfirst( $tipo ) . ' registrada.',
        'caja'    => fc_build_caja_data( $caja_id ),
    ] );
}

// ─────────────────────────────────────────────
// AJAX: Informes de ventas PDV por rango de fechas
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_pdv_get_informes', 'fc_ajax_pdv_get_informes' );
function fc_ajax_pdv_get_informes() {
    fc_pdv_verify_nonce();
    fc_pdv_require_admin();

    $desde = sanitize_text_field( wp_unslash( $_POST['desde'] ?? '' ) );
    $hasta = sanitize_text_field( wp_unslash( $_POST['hasta'] ?? '' ) );

    if ( ! $desde ) {
        $tz    = new DateTimeZone( 'America/Tijuana' );
        $desde = ( new DateTime( 'first day of this month', $tz ) )->format( 'Y-m-d' );
        $hasta = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );
    }

    // Query pedidos PDV en el rango de fechas de venta
    $posts = get_posts( [
        'post_type'      => 'pedido',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'date_query'     => [ [
            'after'     => $desde . ' 00:00:00',
            'before'    => $hasta . ' 23:59:59',
            'inclusive' => true,
            'column'    => 'post_date',
        ] ],
        'meta_query'     => [ [
            'key'   => '_fc_pedido_canal',
            'value' => 'pdv',
        ] ],
    ] );

    // Agrupar por fecha de venta
    $por_dia = [];
    foreach ( $posts as $p ) {
        $ts    = get_post_meta( $p->ID, '_fc_pedido_fecha_venta', true ) ?: $p->post_date;
        $dia   = substr( $ts, 0, 10 ); // YYYY-MM-DD
        $monto = (float) get_post_meta( $p->ID, '_fc_pedido_monto',     true );
        $forma = get_post_meta( $p->ID, '_fc_pedido_forma_pago', true );

        if ( ! isset( $por_dia[ $dia ] ) ) {
            $por_dia[ $dia ] = [ 'fecha' => $dia, 'total' => 0, 'efectivo' => 0, 'tarjeta' => 0, 'otro' => 0, 'count' => 0 ];
        }
        $por_dia[ $dia ]['total']    += $monto;
        $por_dia[ $dia ]['count']    += 1;
        if ( $forma === 'efectivo' )     $por_dia[ $dia ]['efectivo'] += $monto;
        elseif ( $forma === 'tarjeta' )  $por_dia[ $dia ]['tarjeta']  += $monto;
        else                             $por_dia[ $dia ]['otro']      += $monto;
    }

    krsort( $por_dia ); // más reciente primero

    // Totales generales
    $totales = [ 'total' => 0, 'efectivo' => 0, 'tarjeta' => 0, 'otro' => 0, 'count' => 0 ];
    foreach ( $por_dia as $d ) {
        $totales['total']    += $d['total'];
        $totales['efectivo'] += $d['efectivo'];
        $totales['tarjeta']  += $d['tarjeta'];
        $totales['otro']     += $d['otro'];
        $totales['count']    += $d['count'];
    }

    header( 'Content-Type: application/json; charset=utf-8' );
    echo json_encode( [
        'success' => true,
        'data'    => [
            'dias'    => array_values( $por_dia ),
            'totales' => $totales,
            'desde'   => $desde,
            'hasta'   => $hasta,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    wp_die();
}
