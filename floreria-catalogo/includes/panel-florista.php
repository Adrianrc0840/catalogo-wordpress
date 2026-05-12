<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────
// Virtual page for florist panel
// ─────────────────────────────────────────────
add_action( 'init', 'fc_panel_rewrite' );
function fc_panel_rewrite() {
    add_rewrite_rule( '^panel-florista/?$', 'index.php?fc_panel_florista=1', 'top' );
}

add_filter( 'query_vars', 'fc_panel_query_vars' );
function fc_panel_query_vars( $vars ) {
    $vars[] = 'fc_panel_florista';
    return $vars;
}

add_filter( 'template_include', 'fc_panel_template_include' );
function fc_panel_template_include( $template ) {
    if ( get_query_var( 'fc_panel_florista' ) ) {
        $custom = FC_PATH . 'templates/panel-florista.php';
        if ( file_exists( $custom ) ) {
            return $custom;
        }
    }
    return $template;
}

// ─────────────────────────────────────────────
// Enqueue panel assets on the panel page
// ─────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'fc_enqueue_panel' );
function fc_enqueue_panel() {
    if ( ! get_query_var( 'fc_panel_florista' ) ) return;

    wp_enqueue_style( 'fc-panel', FC_URL . 'assets/css/panel.css', [], FC_VERSION );
    wp_enqueue_script( 'fc-panel', FC_URL . 'assets/js/panel.js', [], FC_VERSION, true );
    $tz    = new DateTimeZone( 'America/Tijuana' );
    $today = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );

    wp_localize_script( 'fc-panel', 'fcPanel', [
        'ajaxurl'          => admin_url( 'admin-ajax.php' ),
        'nonce'            => wp_create_nonce( 'fc_panel_nonce' ),
        'siteurl'          => home_url(),
        'schedules'        => fc_get_schedules(),
        'fechasEspeciales' => fc_get_fechas_especiales(),
        'isAdmin'          => current_user_can( 'manage_options' ),
        'today'            => $today,
    ] );
}

// ─────────────────────────────────────────────
// Helper: verify panel nonce
// ─────────────────────────────────────────────
function fc_panel_verify_nonce() {
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'fc_panel_nonce' ) ) {
        wp_send_json_error( [ 'message' => 'Nonce inválido.' ], 403 );
    }
}

function fc_panel_require_cap() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    }
    if ( ! current_user_can( 'fc_ver_pedidos' ) && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    }
}

// ─────────────────────────────────────────────
// AJAX: Login
// ─────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_fc_panel_login', 'fc_ajax_panel_login' );
add_action( 'wp_ajax_fc_panel_login',        'fc_ajax_panel_login' );
function fc_ajax_panel_login() {
    fc_panel_verify_nonce();

    $username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '';
    $password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';

    if ( empty( $username ) || empty( $password ) ) {
        wp_send_json_error( [ 'message' => 'Usuario y contraseña requeridos.' ] );
    }

    $user = wp_authenticate( $username, $password );

    if ( is_wp_error( $user ) ) {
        wp_send_json_error( [ 'message' => 'Credenciales incorrectas.' ] );
    }

    if ( ! user_can( $user, 'fc_ver_pedidos' ) && ! user_can( $user, 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'No tienes permiso para acceder al panel.' ] );
    }

    wp_set_current_user( $user->ID );
    wp_set_auth_cookie( $user->ID, true );

    wp_send_json_success( [ 'message' => 'Sesión iniciada.', 'reload' => true ] );
}

// ─────────────────────────────────────────────
// AJAX: Logout
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_logout', 'fc_ajax_panel_logout' );
function fc_ajax_panel_logout() {
    fc_panel_verify_nonce();
    wp_logout();
    wp_send_json_success( [ 'reload' => true ] );
}

// ─────────────────────────────────────────────
// AJAX: Get pedidos
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_get_pedidos', 'fc_ajax_get_pedidos' );
function fc_ajax_get_pedidos() {
    fc_panel_verify_nonce();
    fc_panel_require_cap();

    $status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'all';
    $fecha  = isset( $_POST['fecha'] )  ? sanitize_text_field( wp_unslash( $_POST['fecha'] ) ) : '';
    $valid  = array_keys( fc_pedido_status_labels() );

    $tz    = new DateTimeZone( 'America/Tijuana' );
    $today = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );

    $args = [
        'post_type'      => 'pedido',
        'post_status'    => 'publish',
        'posts_per_page' => 200,
        'meta_key'       => '_fc_pedido_fecha',
        'orderby'        => 'meta_value',
        'order'          => 'ASC',
    ];

    $meta_conditions = [];

    if ( $status !== 'all' && in_array( $status, $valid, true ) ) {
        $meta_conditions[] = [
            'key'   => '_fc_pedido_status',
            'value' => $status,
        ];
    }

    if ( $fecha ) {
        // Fecha específica seleccionada → solo ese día (incluyendo días pasados)
        $meta_conditions[] = [
            'key'     => '_fc_pedido_fecha',
            'value'   => $fecha,
            'compare' => '=',
        ];
    } else {
        // Sin fecha → mostrar desde hoy en adelante
        $meta_conditions[] = [
            'key'     => '_fc_pedido_fecha',
            'value'   => $today,
            'compare' => '>=',
        ];
    }

    $args['meta_query'] = array_merge( [ 'relation' => 'AND' ], $meta_conditions );

    $pedidos_query = get_posts( $args );
    $pedidos       = array_map( 'fc_build_pedido_data', $pedidos_query );

    wp_send_json_success( [ 'pedidos' => $pedidos ] );
}

// ─────────────────────────────────────────────
// Helper: build pedido data array from WP_Post
// ─────────────────────────────────────────────
function fc_build_pedido_data( $p ) {
    $historial = maybe_unserialize( get_post_meta( $p->ID, '_fc_pedido_historial', true ) );
    $historial = is_array( $historial ) ? $historial : [];
    $last      = ! empty( $historial ) ? end( $historial ) : null;

    return [
        'id'                => $p->ID,
        'numero'            => get_post_meta( $p->ID, '_fc_pedido_numero',           true ),
        'token'             => get_post_meta( $p->ID, '_fc_pedido_token',            true ),
        'status'            => get_post_meta( $p->ID, '_fc_pedido_status',           true ),
        'tipo'              => get_post_meta( $p->ID, '_fc_pedido_tipo',             true ),
        'fecha'             => get_post_meta( $p->ID, '_fc_pedido_fecha',            true ),
        'horario'           => get_post_meta( $p->ID, '_fc_pedido_horario',          true ),
        'direccion'         => get_post_meta( $p->ID, '_fc_pedido_direccion',        true ),
        'hora_recoleccion'  => get_post_meta( $p->ID, '_fc_pedido_hora_recoleccion', true ),
        'cliente_nombre'    => get_post_meta( $p->ID, '_fc_pedido_cliente_nombre',   true ),
        'cliente_telefono'  => get_post_meta( $p->ID, '_fc_pedido_cliente_telefono', true ),
        'destinatario'      => get_post_meta( $p->ID, '_fc_pedido_destinatario',     true ),
        'mensaje_tarjeta'   => get_post_meta( $p->ID, '_fc_pedido_mensaje_tarjeta',  true ),
        'nota'              => get_post_meta( $p->ID, '_fc_pedido_nota',             true ),
        'arreglo_id'        => (int) get_post_meta( $p->ID, '_fc_pedido_arreglo_id',    true ),
        'arreglo_nombre'    => get_post_meta( $p->ID, '_fc_pedido_arreglo_nombre',   true ),
        'arreglo_thumb'     => fc_get_pedido_arreglo_thumb( $p->ID ),
        'tamano'            => get_post_meta( $p->ID, '_fc_pedido_tamano',           true ),
        'color'             => get_post_meta( $p->ID, '_fc_pedido_color',            true ),
        'nota_floreria'     => get_post_meta( $p->ID, '_fc_pedido_nota_floreria',    true ),
        'historial'         => $historial,
        'last_change'       => $last,
        'fecha_registro'    => get_the_date( 'd/m/Y H:i', $p ),
    ];
}

// ─────────────────────────────────────────────
// AJAX: Buscar pedidos (búsqueda global)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_search_pedidos', 'fc_ajax_search_pedidos' );
function fc_ajax_search_pedidos() {
    fc_panel_verify_nonce();
    fc_panel_require_cap();

    $term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

    if ( strlen( $term ) < 2 ) {
        wp_send_json_success( [ 'pedidos' => [] ] );
    }

    $search_fields = [
        '_fc_pedido_numero',
        '_fc_pedido_cliente_nombre',
        '_fc_pedido_cliente_telefono',
        '_fc_pedido_destinatario',
        '_fc_pedido_mensaje_tarjeta',
        '_fc_pedido_nota',
        '_fc_pedido_arreglo_nombre',
        '_fc_pedido_direccion',
        '_fc_pedido_color',
        '_fc_pedido_tamano',
    ];

    $meta_query = [ 'relation' => 'OR' ];
    foreach ( $search_fields as $field ) {
        $meta_query[] = [
            'key'     => $field,
            'value'   => $term,
            'compare' => 'LIKE',
        ];
    }

    $results = get_posts( [
        'post_type'      => 'pedido',
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => $meta_query,
    ] );

    // Ordenar por fecha de entrega ASC en PHP (seguro con OR meta_query)
    usort( $results, function ( $a, $b ) {
        $fa = get_post_meta( $a->ID, '_fc_pedido_fecha', true );
        $fb = get_post_meta( $b->ID, '_fc_pedido_fecha', true );
        return strcmp( $fa, $fb );
    } );

    $pedidos = array_map( 'fc_build_pedido_data', $results );

    wp_send_json_success( [ 'pedidos' => $pedidos ] );
}

// ─────────────────────────────────────────────
// AJAX: Crear pedido
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_crear_pedido', 'fc_ajax_crear_pedido' );
function fc_ajax_crear_pedido() {
    fc_panel_verify_nonce();
    fc_panel_require_cap();

    $fecha_entrega = sanitize_text_field( wp_unslash( $_POST['fecha'] ?? '' ) );
    $numero        = fc_generar_numero_pedido( $fecha_entrega );

    $token  = fc_generar_token();

    $current_user = wp_get_current_user();

    $post_id = wp_insert_post( [
        'post_type'   => 'pedido',
        'post_status' => 'publish',
        'post_title'  => $numero,
    ] );

    if ( is_wp_error( $post_id ) ) {
        wp_send_json_error( [ 'message' => 'Error al crear el pedido.' ] );
    }

    $fields = [
        '_fc_pedido_numero'           => $numero,
        '_fc_pedido_token'            => $token,
        '_fc_pedido_status'           => 'recibido',
        '_fc_pedido_tipo'             => sanitize_key( $_POST['tipo'] ?? 'envio' ),
        '_fc_pedido_fecha'            => sanitize_text_field( $_POST['fecha'] ?? '' ),
        '_fc_pedido_horario'          => sanitize_text_field( $_POST['horario'] ?? '' ),
        '_fc_pedido_direccion'        => sanitize_text_field( $_POST['direccion'] ?? '' ),
        '_fc_pedido_hora_recoleccion' => sanitize_text_field( $_POST['hora_recoleccion'] ?? '' ),
        '_fc_pedido_cliente_nombre'   => sanitize_text_field( $_POST['cliente_nombre'] ?? '' ),
        '_fc_pedido_cliente_telefono' => sanitize_text_field( $_POST['cliente_telefono'] ?? '' ),
        '_fc_pedido_destinatario'     => sanitize_text_field( $_POST['destinatario'] ?? '' ),
        '_fc_pedido_mensaje_tarjeta'  => sanitize_textarea_field( $_POST['mensaje_tarjeta'] ?? '' ),
        '_fc_pedido_nota'             => sanitize_textarea_field( $_POST['nota'] ?? '' ),
        '_fc_pedido_arreglo_id'       => (int) ( $_POST['arreglo_id'] ?? 0 ),
        '_fc_pedido_arreglo_nombre'   => sanitize_text_field( $_POST['arreglo_nombre'] ?? '' ),
        '_fc_pedido_tamano'           => sanitize_text_field( $_POST['tamano'] ?? '' ),
        '_fc_pedido_color'            => sanitize_text_field( $_POST['color'] ?? '' ),
        '_fc_pedido_registrado_por'   => get_current_user_id(),
    ];

    foreach ( $fields as $key => $value ) {
        update_post_meta( $post_id, $key, $value );
    }

    $historial = [ [
        'status'    => 'recibido',
        'user_id'   => get_current_user_id(),
        'user_name' => $current_user->display_name,
        'timestamp' => current_time( 'mysql' ),
    ] ];
    update_post_meta( $post_id, '_fc_pedido_historial', maybe_serialize( $historial ) );

    $client_url = home_url( '/pedido/' . $numero );

    wp_send_json_success( [
        'message'    => 'Pedido creado correctamente.',
        'numero'     => $numero,
        'token'      => $token,
        'client_url' => $client_url,
        'pedido_id'  => $post_id,
    ] );
}

// ─────────────────────────────────────────────
// AJAX: Actualizar status
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_actualizar_status', 'fc_ajax_actualizar_status' );
function fc_ajax_actualizar_status() {
    fc_panel_verify_nonce();
    fc_panel_require_cap();

    $pedido_id  = (int) ( $_POST['pedido_id'] ?? 0 );
    $new_status = sanitize_key( $_POST['status'] ?? '' );
    $valid      = array_keys( fc_pedido_status_labels() );

    if ( ! $pedido_id || ! in_array( $new_status, $valid, true ) ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos.' ] );
    }

    if ( get_post_type( $pedido_id ) !== 'pedido' ) {
        wp_send_json_error( [ 'message' => 'Pedido no encontrado.' ] );
    }

    $current_user = wp_get_current_user();
    update_post_meta( $pedido_id, '_fc_pedido_status', $new_status );

    $historial   = maybe_unserialize( get_post_meta( $pedido_id, '_fc_pedido_historial', true ) );
    $historial   = is_array( $historial ) ? $historial : [];
    $entry = [
        'status'    => $new_status,
        'user_id'   => get_current_user_id(),
        'user_name' => $current_user->display_name,
        'timestamp' => current_time( 'mysql' ),
    ];
    $historial[] = $entry;
    update_post_meta( $pedido_id, '_fc_pedido_historial', maybe_serialize( $historial ) );

    wp_send_json_success( [
        'message'    => 'Estado actualizado.',
        'new_status' => $new_status,
        'label'      => fc_pedido_status_label( $new_status ),
        'last_change' => $entry,
    ] );
}

// ─────────────────────────────────────────────
// AJAX: Actualizar nota floreria
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_actualizar_nota', 'fc_ajax_actualizar_nota' );
function fc_ajax_actualizar_nota() {
    fc_panel_verify_nonce();
    fc_panel_require_cap();

    $pedido_id = (int) ( $_POST['pedido_id'] ?? 0 );
    $nota      = sanitize_textarea_field( wp_unslash( $_POST['nota'] ?? '' ) );

    if ( ! $pedido_id || get_post_type( $pedido_id ) !== 'pedido' ) {
        wp_send_json_error( [ 'message' => 'Pedido no encontrado.' ] );
    }

    update_post_meta( $pedido_id, '_fc_pedido_nota_floreria', $nota );
    wp_send_json_success( [ 'message' => 'Nota guardada.' ] );
}

// ─────────────────────────────────────────────
// AJAX: Obtener un arreglo por ID (para editar pedido)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_get_arreglo', 'fc_ajax_get_arreglo' );
function fc_ajax_get_arreglo() {
    fc_panel_verify_nonce();
    fc_panel_require_cap();

    $aid = (int) ( $_POST['arreglo_id'] ?? 0 );
    if ( ! $aid || get_post_type( $aid ) !== 'arreglo' ) {
        wp_send_json_error( [ 'message' => 'Arreglo no encontrado.' ] );
    }

    $tamanos = get_post_meta( $aid, '_fc_tamanos', true );
    if ( ! is_array( $tamanos ) ) $tamanos = [];

    wp_send_json_success( [
        'id'      => $aid,
        'title'   => get_the_title( $aid ),
        'tamanos' => $tamanos,
    ] );
}

// ─────────────────────────────────────────────
// AJAX: Actualizar datos del pedido
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_actualizar_pedido', 'fc_ajax_actualizar_pedido_datos' );
function fc_ajax_actualizar_pedido_datos() {
    fc_panel_verify_nonce();
    fc_panel_require_cap();

    $pedido_id = (int) ( $_POST['pedido_id'] ?? 0 );
    if ( ! $pedido_id || get_post_type( $pedido_id ) !== 'pedido' ) {
        wp_send_json_error( [ 'message' => 'Pedido no encontrado.' ] );
    }

    $fields = [
        '_fc_pedido_tipo'             => sanitize_key( $_POST['tipo'] ?? 'envio' ),
        '_fc_pedido_fecha'            => sanitize_text_field( $_POST['fecha'] ?? '' ),
        '_fc_pedido_horario'          => sanitize_text_field( $_POST['horario'] ?? '' ),
        '_fc_pedido_direccion'        => sanitize_text_field( $_POST['direccion'] ?? '' ),
        '_fc_pedido_hora_recoleccion' => sanitize_text_field( $_POST['hora_recoleccion'] ?? '' ),
        '_fc_pedido_cliente_nombre'   => sanitize_text_field( $_POST['cliente_nombre'] ?? '' ),
        '_fc_pedido_cliente_telefono' => sanitize_text_field( $_POST['cliente_telefono'] ?? '' ),
        '_fc_pedido_destinatario'     => sanitize_text_field( $_POST['destinatario'] ?? '' ),
        '_fc_pedido_mensaje_tarjeta'  => sanitize_textarea_field( $_POST['mensaje_tarjeta'] ?? '' ),
        '_fc_pedido_nota'             => sanitize_textarea_field( $_POST['nota'] ?? '' ),
        '_fc_pedido_arreglo_id'       => (int) ( $_POST['arreglo_id'] ?? 0 ),
        '_fc_pedido_arreglo_nombre'   => sanitize_text_field( $_POST['arreglo_nombre'] ?? '' ),
        '_fc_pedido_tamano'           => sanitize_text_field( $_POST['tamano'] ?? '' ),
        '_fc_pedido_color'            => sanitize_text_field( $_POST['color'] ?? '' ),
    ];

    foreach ( $fields as $key => $value ) {
        update_post_meta( $pedido_id, $key, $value );
    }

    wp_send_json_success( [ 'message' => 'Pedido actualizado correctamente.' ] );
}

// ─────────────────────────────────────────────
// AJAX: Eliminar pedido (solo admin)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_eliminar_pedido', 'fc_ajax_eliminar_pedido' );
function fc_ajax_eliminar_pedido() {
    fc_panel_verify_nonce();

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    }

    $pedido_id = (int) ( $_POST['pedido_id'] ?? 0 );
    if ( ! $pedido_id || get_post_type( $pedido_id ) !== 'pedido' ) {
        wp_send_json_error( [ 'message' => 'Pedido no encontrado.' ] );
    }

    wp_trash_post( $pedido_id );
    wp_send_json_success( [ 'message' => 'Pedido movido a la papelera.' ] );
}

// ─────────────────────────────────────────────
// AJAX: Buscar arreglos (autocomplete)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_buscar_arreglos', 'fc_ajax_buscar_arreglos' );
function fc_ajax_buscar_arreglos() {
    fc_panel_verify_nonce();
    fc_panel_require_cap();

    $term = sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) );

    if ( strlen( $term ) < 2 ) {
        wp_send_json_success( [ 'arreglos' => [] ] );
    }

    $query = new WP_Query( [
        'post_type'      => 'arreglo',
        'post_status'    => 'publish',
        'posts_per_page' => 10,
        's'              => $term,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );

    $arreglos = [];
    if ( $query->have_posts() ) {
        foreach ( $query->posts as $post ) {
            $tamanos = get_post_meta( $post->ID, '_fc_tamanos', true );
            if ( ! is_array( $tamanos ) ) $tamanos = [];

            $arreglos[] = [
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'tamanos' => $tamanos,
            ];
        }
    }
    wp_reset_postdata();

    wp_send_json_success( [ 'arreglos' => $arreglos ] );
}

// ─────────────────────────────────────────────
// AJAX: Obtener pedidos en papelera (solo admin)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_get_papelera', 'fc_ajax_get_papelera' );
function fc_ajax_get_papelera() {
    fc_panel_verify_nonce();
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    }

    $posts = get_posts( [
        'post_type'      => 'pedido',
        'post_status'    => 'trash',
        'posts_per_page' => 200,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );

    $pedidos = array_map( 'fc_build_pedido_data', $posts );
    wp_send_json_success( [ 'pedidos' => $pedidos ] );
}

// ─────────────────────────────────────────────
// AJAX: Restaurar pedido desde papelera (solo admin)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_restaurar_pedido', 'fc_ajax_restaurar_pedido' );
function fc_ajax_restaurar_pedido() {
    fc_panel_verify_nonce();
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    }

    $pedido_id = (int) ( $_POST['pedido_id'] ?? 0 );
    if ( ! $pedido_id ) {
        wp_send_json_error( [ 'message' => 'ID inválido.' ] );
    }

    wp_untrash_post( $pedido_id );
    wp_update_post( [ 'ID' => $pedido_id, 'post_status' => 'publish' ] );
    wp_send_json_success( [ 'message' => 'Pedido restaurado.' ] );
}

// ─────────────────────────────────────────────
// AJAX: Eliminar pedido permanentemente (solo admin)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_eliminar_permanente', 'fc_ajax_eliminar_permanente' );
function fc_ajax_eliminar_permanente() {
    fc_panel_verify_nonce();
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    }

    $pedido_id = (int) ( $_POST['pedido_id'] ?? 0 );
    if ( ! $pedido_id ) {
        wp_send_json_error( [ 'message' => 'ID inválido.' ] );
    }

    wp_delete_post( $pedido_id, true );
    wp_send_json_success( [ 'message' => 'Pedido eliminado permanentemente.' ] );
}

// ─────────────────────────────────────────────
// Print / PDF page  (?fc_print_pedido=ID)
// ─────────────────────────────────────────────
add_action( 'template_redirect', 'fc_print_pedido_page' );
function fc_print_pedido_page() {
    $pedido_id = isset( $_GET['fc_print_pedido'] ) ? intval( $_GET['fc_print_pedido'] ) : 0;
    if ( ! $pedido_id ) return;

    // Access: must be logged in with panel or admin capability
    if ( ! is_user_logged_in() ||
         ( ! current_user_can( 'fc_ver_pedidos' ) && ! current_user_can( 'manage_options' ) ) ) {
        wp_die( 'Acceso denegado.', 'Sin permiso', [ 'response' => 403 ] );
    }

    $post = get_post( $pedido_id );
    if ( ! $post || $post->post_type !== 'pedido' ) {
        wp_die( 'Pedido no encontrado.' );
    }

    // ── Read meta ──
    $numero      = get_post_meta( $pedido_id, '_fc_pedido_numero',           true );
    $status      = get_post_meta( $pedido_id, '_fc_pedido_status',           true );
    $tipo        = get_post_meta( $pedido_id, '_fc_pedido_tipo',             true );
    $fecha       = get_post_meta( $pedido_id, '_fc_pedido_fecha',            true );
    $horario     = get_post_meta( $pedido_id, '_fc_pedido_horario',          true );
    $direccion   = get_post_meta( $pedido_id, '_fc_pedido_direccion',        true );
    $hora_rec    = get_post_meta( $pedido_id, '_fc_pedido_hora_recoleccion', true );
    $cliente     = get_post_meta( $pedido_id, '_fc_pedido_cliente_nombre',   true );
    $telefono    = get_post_meta( $pedido_id, '_fc_pedido_cliente_telefono', true );
    $destinat    = get_post_meta( $pedido_id, '_fc_pedido_destinatario',     true );
    $tarjeta     = get_post_meta( $pedido_id, '_fc_pedido_mensaje_tarjeta',  true );
    $nota        = get_post_meta( $pedido_id, '_fc_pedido_nota',             true );
    $nota_fl     = get_post_meta( $pedido_id, '_fc_pedido_nota_floreria',    true );
    $arreglo     = get_post_meta( $pedido_id, '_fc_pedido_arreglo_nombre',   true );
    $tamano      = get_post_meta( $pedido_id, '_fc_pedido_tamano',           true );
    $color       = get_post_meta( $pedido_id, '_fc_pedido_color',            true );
    $thumb       = fc_get_pedido_arreglo_thumb( $pedido_id );
    $shop_name   = 'Florería Monarca';
    $status_lbl  = fc_pedido_status_label( $status );

    // Delivery line
    $tipo_label  = $tipo === 'recoleccion' ? 'Recolección en tienda' : 'Envío a domicilio';
    $entrega_det = $tipo === 'recoleccion' ? $hora_rec : $horario;

    // Format fecha
    $fecha_fmt = '';
    if ( $fecha ) {
        $dt = DateTime::createFromFormat( 'Y-m-d', $fecha );
        if ( $dt ) {
            $meses = [ '', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
                       'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' ];
            $fecha_fmt = $dt->format( 'j' ) . ' de ' . $meses[ (int) $dt->format( 'n' ) ] . ' de ' . $dt->format( 'Y' );
        }
    }

    header( 'Content-Type: text/html; charset=UTF-8' );
    ?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Pedido <?php echo esc_html( $numero ); ?> – <?php echo esc_html( $shop_name ); ?></title>
<style>
  /* ── Forzar colores e imágenes en impresión/PDF ── */
  *, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }

  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 13px;
    color: #1a1a1a;
    background: #fff;
    padding: 24px;
    max-width: 800px;
    margin: 0 auto;
  }

  /* ── Botones (ocultos al imprimir) ── */
  .fc-print-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-bottom: 20px;
  }
  .fc-print-btn {
    padding: 8px 18px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
  }
  .fc-print-btn.primary  { background: #9d174d; color: #fff; }
  .fc-print-btn.secondary { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }

  /* ── Documento ── */
  .fc-doc {
    border: 1px solid #d1d5db;
    border-radius: 10px;
    overflow: hidden;
  }

  /* Encabezado */
  .fc-doc-header {
    background: #9d174d;
    color: #fff;
    padding: 20px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .fc-doc-header h1 {
    font-size: 20px;
    font-weight: 700;
    letter-spacing: -.3px;
  }
  .fc-doc-header .fc-doc-subtitle {
    font-size: 10px;
    opacity: .7;
    margin-top: 3px;
    text-transform: uppercase;
    letter-spacing: .6px;
  }
  .fc-doc-header-right { text-align: right; }
  .fc-doc-header-right .fc-num {
    font-size: 17px;
    font-weight: 700;
    font-family: 'Courier New', monospace;
  }
  .fc-doc-header-right .fc-fecha-reg {
    font-size: 10px;
    opacity: .65;
    margin-top: 3px;
  }

  /* ── Cuerpo: foto izquierda (prominente) | datos derecha ── */
  .fc-doc-body {
    width: 100%;
    border-collapse: collapse;
  }

  /* Columna foto — izquierda, ancha, con fondo rosado suave */
  .fc-photo-col {
    width: 250px;
    background: #fdf2f8;
    vertical-align: middle;
    text-align: center;
    padding: 28px 22px;
    border-right: 1px solid #f3e8f0;
  }
  .fc-photo-col img {
    width: 200px;
    height: 200px;
    object-fit: cover;
    border-radius: 12px;
    border: 2px solid #f9a8d4;
    display: block;
    margin: 0 auto;
  }
  .fc-no-photo {
    width: 200px;
    height: 200px;
    border-radius: 12px;
    border: 2px dashed #f9a8d4;
    display: table-cell;
    vertical-align: middle;
    color: #c084a8;
    font-size: 11px;
    text-align: center;
    background: #fff;
  }
  .fc-photo-name {
    margin-top: 12px;
    font-size: 13px;
    font-weight: 700;
    color: #9d174d;
    word-break: break-word;
  }
  .fc-photo-detail {
    margin-top: 4px;
    font-size: 11px;
    color: #9ca3af;
    word-break: break-word;
  }

  /* Columna info — derecha */
  .fc-info-col {
    padding: 22px 24px;
    vertical-align: top;
  }

  .fc-section-title {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #9d174d;
    margin-bottom: 9px;
    padding-bottom: 5px;
    border-bottom: 1px solid #fce7f3;
  }

  .fc-row {
    display: flex;
    gap: 8px;
    margin-bottom: 7px;
    line-height: 1.4;
  }
  .fc-row-label {
    font-weight: 600;
    color: #6b7280;
    min-width: 82px;
    flex-shrink: 0;
    font-size: 11px;
  }
  .fc-row-value {
    color: #111827;
    font-size: 12px;
    word-break: break-word;
    overflow-wrap: break-word;
    min-width: 0;
    flex: 1;
  }
  .fc-row-value.italic { font-style: italic; color: #374151; }

  .fc-divider {
    border: none;
    border-top: 1px dashed #f3e8f0;
    margin: 11px 0;
  }

  /* Sección acuse de recibo */
  .fc-receipt {
    border-top: 2px solid #9d174d;
    padding: 18px 26px 22px;
    background: #f8fafc;
  }
  .fc-receipt-title {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .9px;
    color: #9d174d;
    margin-bottom: 16px;
  }
  /* Tabla para los campos del acuse (máxima compatibilidad en print) */
  .fc-receipt-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 16px;
  }
  .fc-receipt-table td {
    width: 50%;
    padding-right: 24px;
    vertical-align: bottom;
  }
  .fc-receipt-table td:last-child { padding-right: 0; }
  .fc-receipt-field-label {
    font-size: 9.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #6b7280;
    display: block;
    margin-bottom: 5px;
  }
  .fc-receipt-line {
    display: block;
    border-bottom: 1.5px solid #9ca3af;
    height: 26px;
    width: 100%;
  }
  .fc-receipt-sig-label {
    font-size: 9.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #6b7280;
    display: block;
    margin-bottom: 6px;
  }
  .fc-receipt-sig-box {
    border: 1.5px solid #9ca3af;
    border-radius: 5px;
    height: 78px;
    width: 100%;
    background: #fff;
    display: block;
  }

  /* Pie */
  .fc-doc-footer {
    padding: 10px 26px;
    background: #9d174d;
    color: rgba(255,255,255,.55);
    font-size: 9.5px;
    display: flex;
    justify-content: space-between;
  }

  /* ── Impresión / PDF ── */
  @media print {
    body { padding: 0; max-width: 100%; }
    .fc-print-actions { display: none !important; }
    .fc-doc { border-radius: 0; border: none; }
    @page { margin: 8mm 10mm; size: A4 portrait; }
  }
</style>
</head>
<body>

<div class="fc-print-actions">
    <button class="fc-print-btn secondary" onclick="window.close()">✕ Cerrar</button>
    <button class="fc-print-btn primary" onclick="window.print()">🖨 Imprimir / Guardar PDF</button>
</div>

<div class="fc-doc">

    <!-- Encabezado -->
    <div class="fc-doc-header">
        <div>
            <h1><?php echo esc_html( $shop_name ); ?></h1>
            <div class="fc-doc-subtitle">Comprobante de pedido</div>
        </div>
        <div class="fc-doc-header-right">
            <div class="fc-num"><?php echo esc_html( $numero ); ?></div>
            <div class="fc-fecha-reg">Registrado el <?php echo esc_html( get_the_date( 'd/m/Y H:i', $post ) ); ?></div>
        </div>
    </div>

    <!-- Cuerpo: foto izquierda | datos derecha -->
    <table class="fc-doc-body">
    <tr>

        <!-- ── Foto (izquierda, prominente) ── -->
        <td class="fc-photo-col">
            <?php if ( $thumb ) : ?>
                <img src="<?php echo esc_url( $thumb ); ?>" alt="Foto del arreglo" />
            <?php else : ?>
                <div class="fc-no-photo">Sin foto disponible</div>
            <?php endif; ?>
            <div class="fc-photo-name"><?php echo esc_html( $arreglo ); ?></div>
            <div class="fc-photo-detail">
                <?php
                $det = [];
                if ( $tamano ) $det[] = esc_html( $tamano );
                if ( $color && strpos( $color, '--' ) !== 0 ) $det[] = esc_html( $color );
                echo implode( ' · ', $det );
                ?>
            </div>
        </td>

        <!-- ── Datos (derecha) ── -->
        <td class="fc-info-col">

            <!-- Entrega -->
            <div class="fc-section-title">Entrega</div>
            <div class="fc-row">
                <span class="fc-row-label">Tipo</span>
                <span class="fc-row-value"><?php echo esc_html( $tipo_label ); ?></span>
            </div>
            <?php if ( $fecha_fmt ) : ?>
            <div class="fc-row">
                <span class="fc-row-label">Fecha</span>
                <span class="fc-row-value"><?php echo esc_html( $fecha_fmt ); ?></span>
            </div>
            <?php endif; ?>
            <?php if ( $entrega_det ) : ?>
            <div class="fc-row">
                <span class="fc-row-label"><?php echo $tipo === 'recoleccion' ? 'Hora' : 'Horario'; ?></span>
                <span class="fc-row-value"><?php echo esc_html( $entrega_det ); ?></span>
            </div>
            <?php endif; ?>
            <?php if ( $tipo === 'envio' && $direccion ) : ?>
            <div class="fc-row">
                <span class="fc-row-label">Dirección</span>
                <span class="fc-row-value"><?php echo esc_html( $direccion ); ?></span>
            </div>
            <?php endif; ?>

            <hr class="fc-divider" />

            <!-- Cliente -->
            <div class="fc-section-title">Cliente</div>
            <div class="fc-row">
                <span class="fc-row-label">Nombre</span>
                <span class="fc-row-value"><?php echo esc_html( $cliente ); ?></span>
            </div>
            <?php if ( $telefono ) : ?>
            <div class="fc-row">
                <span class="fc-row-label">Teléfono</span>
                <span class="fc-row-value"><?php echo esc_html( $telefono ); ?></span>
            </div>
            <?php endif; ?>
            <?php if ( $destinat ) : ?>
            <div class="fc-row">
                <span class="fc-row-label">Destinatario</span>
                <span class="fc-row-value"><?php echo esc_html( $destinat ); ?></span>
            </div>
            <?php endif; ?>
            <?php if ( $tarjeta ) : ?>
            <div class="fc-row">
                <span class="fc-row-label">Tarjeta</span>
                <span class="fc-row-value italic">"<?php echo esc_html( $tarjeta ); ?>"</span>
            </div>
            <?php endif; ?>
            <?php if ( $nota ) : ?>
            <hr class="fc-divider" />
            <div class="fc-section-title">Nota especial</div>
            <div class="fc-row">
                <span class="fc-row-value"><?php echo esc_html( $nota ); ?></span>
            </div>
            <?php endif; ?>
            <?php if ( $nota_fl ) : ?>
            <div class="fc-row" style="margin-top:4px;">
                <span class="fc-row-label">Florería</span>
                <span class="fc-row-value italic"><?php echo esc_html( $nota_fl ); ?></span>
            </div>
            <?php endif; ?>

        </td>
    </tr>
    </table><!-- .fc-doc-body -->

    <!-- Acuse de recibo -->
    <div class="fc-receipt">
        <div class="fc-receipt-title">✔ Acuse de recibo</div>
        <table class="fc-receipt-table">
            <tr>
                <td>
                    <span class="fc-receipt-field-label">Recibido por</span>
                    <span class="fc-receipt-line"></span>
                </td>
                <td>
                    <span class="fc-receipt-field-label">Hora de recepción</span>
                    <span class="fc-receipt-line"></span>
                </td>
            </tr>
        </table>
        <span class="fc-receipt-sig-label">Firma de quien recibe</span>
        <span class="fc-receipt-sig-box"></span>
    </div>

    <!-- Pie de página -->
    <div class="fc-doc-footer">
        <span><?php echo esc_html( $shop_name ); ?></span>
        <span>Pedido <?php echo esc_html( $numero ); ?> · <?php echo esc_html( $fecha_fmt ); ?></span>
    </div>

</div><!-- .fc-doc -->

<script>
// Abrir diálogo de impresión al cargar (solo si no viene de botón cerrar)
if (!sessionStorage.getItem('fc_print_closed')) {
    window.addEventListener('load', function() {
        setTimeout(function() { window.print(); }, 400);
    });
}
</script>
</body>
</html>
<?php
    exit;
}
