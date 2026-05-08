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
    wp_localize_script( 'fc-panel', 'fcPanel', [
        'ajaxurl'   => admin_url( 'admin-ajax.php' ),
        'nonce'     => wp_create_nonce( 'fc_panel_nonce' ),
        'siteurl'   => home_url(),
        'schedules' => fc_get_schedules(),
        'isAdmin'   => current_user_can( 'manage_options' ),
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

    if ( ! user_can( $user, 'fc_ver_pedidos' ) ) {
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

    $args = [
        'post_type'      => 'pedido',
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    $meta_conditions = [];

    if ( $status !== 'all' && in_array( $status, $valid, true ) ) {
        $meta_conditions[] = [
            'key'   => '_fc_pedido_status',
            'value' => $status,
        ];
    }

    if ( $fecha ) {
        $meta_conditions[] = [
            'key'   => '_fc_pedido_fecha',
            'value' => $fecha,
        ];
    }

    if ( ! empty( $meta_conditions ) ) {
        $args['meta_query'] = array_merge( [ 'relation' => 'AND' ], $meta_conditions );
    }

    $pedidos_query = get_posts( $args );
    $pedidos       = [];

    foreach ( $pedidos_query as $p ) {
        $historial = maybe_unserialize( get_post_meta( $p->ID, '_fc_pedido_historial', true ) );
        $historial = is_array( $historial ) ? $historial : [];
        $last      = ! empty( $historial ) ? end( $historial ) : null;

        $pedidos[] = [
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

    wp_send_json_success( [ 'pedidos' => $pedidos ] );
}

// ─────────────────────────────────────────────
// AJAX: Crear pedido
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_crear_pedido', 'fc_ajax_crear_pedido' );
function fc_ajax_crear_pedido() {
    fc_panel_verify_nonce();
    fc_panel_require_cap();

    $numero = fc_generar_numero_pedido();
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

    wp_delete_post( $pedido_id, true );
    wp_send_json_success( [ 'message' => 'Pedido eliminado.' ] );
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
