<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────
// Deshabilitar caché para /panel-florista/ (lo más temprano posible)
// ─────────────────────────────────────────────
add_action( 'plugins_loaded', 'fc_panel_disable_cache', 1 );
function fc_panel_disable_cache() {
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    if ( strpos( $uri, '/panel-florista' ) === false ) return;

    // Constantes que respetan la mayoría de plugins de caché (WP Super Cache, W3TC, WP Rocket…)
    defined( 'DONOTCACHEPAGE' )   || define( 'DONOTCACHEPAGE',   true );
    defined( 'DONOTCACHEDB' )     || define( 'DONOTCACHEDB',     true );
    defined( 'DONOTMINIFY' )      || define( 'DONOTMINIFY',      true );
    defined( 'DONOTCACHEOBJECT' ) || define( 'DONOTCACHEOBJECT', true );

    // Cabeceras HTTP de no-caché (para LiteSpeed, Nginx FastCGI cache, etc.)
    if ( ! headers_sent() ) {
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'X-LiteSpeed-Cache-Control: no-cache' );
    }
}

// ─────────────────────────────────────────────
// Virtual page for florist panel
// ─────────────────────────────────────────────
// Token de autologin firmado (sin almacenamiento en servidor)
// Formato: base64url( user_id : expiry : hmac )
// ─────────────────────────────────────────────
function fc_generate_autologin_token( $user_id ) {
    $expiry  = time() + 90; // válido 90 segundos
    $payload = (int) $user_id . ':' . $expiry;
    $sig     = hash_hmac( 'sha256', $payload, wp_salt( 'secure_auth' ) );
    // base64url (sin +, /, =) para que sea seguro en URL
    return rtrim( strtr( base64_encode( $payload . ':' . $sig ), '+/', '-_' ), '=' );
}

function fc_verify_autologin_token( $token ) {
    if ( ! $token ) return false;
    $decoded = base64_decode( strtr( $token, '-_', '+/' ) );
    if ( ! $decoded ) return false;
    $parts = explode( ':', $decoded, 3 );
    if ( count( $parts ) !== 3 ) return false;
    [ $user_id, $expiry, $sig ] = $parts;
    if ( (int) $expiry < time() ) return false; // expirado
    $payload  = (int) $user_id . ':' . (int) $expiry;
    $expected = hash_hmac( 'sha256', $payload, wp_salt( 'secure_auth' ) );
    if ( ! hash_equals( $expected, $sig ) ) return false;
    return (int) $user_id;
}

// ─────────────────────────────────────────────
// Autologin via admin-ajax.php (nunca cacheado por plugins de caché)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_fc_autologin', 'fc_ajax_autologin' );
add_action( 'wp_ajax_fc_autologin',        'fc_ajax_autologin' );
function fc_ajax_autologin() {
    $raw_token = isset( $_GET['token'] ) ? wp_unslash( $_GET['token'] ) : '';
    $panel_url = home_url( '/panel-florista/' );

    // El token está firmado con HMAC-SHA256 + wp_salt(): si es válido, el permiso ya
    // fue verificado en fc_ajax_panel_login(). Aquí solo ponemos el cookie.
    $user_id = fc_verify_autologin_token( $raw_token );
    if ( $user_id ) {
        $user = get_user_by( 'id', $user_id );
        if ( $user ) {
            wp_set_current_user( $user->ID );
            wp_set_auth_cookie( $user->ID, true );
        }
    }

    wp_safe_redirect( $panel_url );
    exit;
}

// ─────────────────────────────────────────────
// AJAX: Verificar si el usuario ya tiene sesión activa con acceso al panel
// Se llama desde JS cuando el caché sirve el login pero el usuario ya inició sesión
// ─────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_fc_panel_check_auth', 'fc_ajax_panel_check_auth' );
add_action( 'wp_ajax_fc_panel_check_auth',        'fc_ajax_panel_check_auth' );
function fc_ajax_panel_check_auth() {
    $user = wp_get_current_user();
    wp_send_json_success( [
        'logged_in'  => is_user_logged_in(),
        'has_access' => fc_user_can_access_panel( $user ),
    ] );
}

// ─────────────────────────────────────────────
// AJAX: Devolver nonce fresco (sin login requerido)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_fc_get_nonce', 'fc_ajax_get_nonce' );
add_action( 'wp_ajax_fc_get_nonce',        'fc_ajax_get_nonce' );
function fc_ajax_get_nonce() {
    wp_send_json_success( [ 'nonce' => wp_create_nonce( 'fc_panel_nonce' ) ] );
}

// Reforzar no-caché en la capa de cabeceras HTTP de WordPress
add_action( 'send_headers', 'fc_panel_send_nocache_headers' );
function fc_panel_send_nocache_headers() {
    if ( get_query_var( 'fc_panel_florista' ) ) {
        nocache_headers();
        header( 'X-LiteSpeed-Cache-Control: no-cache' );
    }
}

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

    $gmaps_key = get_option( 'fc_gmaps_key', '' );
    $panel_deps = [];

    if ( $gmaps_key ) {
        wp_enqueue_script(
            'google-places',
            'https://maps.googleapis.com/maps/api/js?key=' . urlencode( $gmaps_key ) . '&libraries=places',
            [],
            null,
            true
        );
        $panel_deps[] = 'google-places';
    }

    // Solo cargar el gestor de medios para usuarios con acceso al panel
    if ( is_user_logged_in() && fc_user_can_access_panel( wp_get_current_user() ) ) {
        wp_enqueue_media();
    }

    wp_enqueue_style( 'fc-panel', FC_URL . 'assets/css/panel.css', [], FC_VERSION );
    wp_enqueue_script( 'fc-panel', FC_URL . 'assets/js/panel.js', $panel_deps, FC_VERSION, true );

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
        'gmapsKey'         => $gmaps_key,
    ] );
}

// ─────────────────────────────────────────────
// Verificar si un usuario tiene el rol florista leyendo directamente de wp_usermeta
// (evita depender de $wp_roles que puede inicializarse antes del hook init)
// ─────────────────────────────────────────────
function fc_user_is_florista( $user ) {
    if ( ! $user || ! $user->exists() ) return false;
    global $wpdb;
    // Leer capabilities directamente de la meta del usuario (más confiable que $user->roles)
    $caps = get_user_meta( $user->ID, $wpdb->prefix . 'capabilities', true );
    return is_array( $caps ) && ! empty( $caps['florista'] );
}

// Helper: función central para saber si un usuario puede acceder al panel
function fc_user_can_access_panel( $user ) {
    if ( ! $user || ! $user->exists() ) return false;
    return fc_user_is_florista( $user ) || user_can( $user, 'manage_options' );
}

// Garantizar caps de panel para floristas en cualquier current_user_can() check
add_filter( 'user_has_cap', 'fc_florista_force_caps', 10, 4 );
function fc_florista_force_caps( $allcaps, $caps, $args, $user ) {
    if ( fc_user_is_florista( $user ) ) {
        $allcaps['fc_ver_pedidos']        = true;
        $allcaps['fc_actualizar_pedidos'] = true;
    }
    return $allcaps;
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
    if ( ! fc_user_can_access_panel( wp_get_current_user() ) ) {
        wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    }
}

// ─────────────────────────────────────────────
// AJAX: Login
// ─────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_fc_panel_login', 'fc_ajax_panel_login' );
add_action( 'wp_ajax_fc_panel_login',        'fc_ajax_panel_login' );
function fc_ajax_panel_login() {
    // No se verifica nonce aquí: las credenciales son el mecanismo de autenticación.
    $username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '';
    $password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';

    if ( empty( $username ) || empty( $password ) ) {
        wp_send_json_error( [ 'message' => 'Usuario y contraseña requeridos.' ] );
    }

    $user = wp_authenticate( $username, $password );

    if ( is_wp_error( $user ) ) {
        wp_send_json_error( [ 'message' => 'Credenciales incorrectas.' ] );
    }

    // Verificar acceso usando lectura directa de usermeta (más confiable que $user->roles)
    if ( ! fc_user_can_access_panel( $user ) ) {
        wp_send_json_error( [ 'message' => 'No tienes permiso para acceder al panel.' ] );
    }

    // Token firmado criptográficamente (no necesita BD ni caché compartida)
    // El JS hará POST directamente a /panel-florista/ con este token.
    // POST nunca es cacheado → wp_set_auth_cookie() siempre funciona.
    $token = fc_generate_autologin_token( $user->ID );

    wp_send_json_success( [
        'message' => 'Sesión iniciada.',
        'token'   => $token,
    ] );
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

    if ( $status === 'pendiente' ) {
        // Solo admins pueden ver pedidos pendientes
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
        }
        $meta_conditions[] = [
            'key'   => '_fc_pedido_status',
            'value' => 'pendiente',
        ];
        // Filtro de fecha opcional para pendientes
        if ( $fecha ) {
            $meta_conditions[] = [
                'key'     => '_fc_pedido_fecha',
                'value'   => $fecha,
                'compare' => '=',
            ];
        }
        // Sin fecha → mostrar TODOS los pendientes (sin restricción de fecha)
        unset( $args['meta_key'] );
        $args['orderby'] = 'date';
        $args['order']   = 'DESC';
    } elseif ( $status !== 'all' && in_array( $status, $valid, true ) ) {
        $meta_conditions[] = [
            'key'   => '_fc_pedido_status',
            'value' => $status,
        ];
    } else {
        // Exclude pedidos pendientes from the florista panel (they are admin-only)
        $meta_conditions[] = [
            'key'     => '_fc_pedido_status',
            'value'   => 'pendiente',
            'compare' => '!=',
        ];
    }

    if ( $status !== 'pendiente' ) {
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
    }

    $args['meta_query'] = array_merge( [ 'relation' => 'AND' ], $meta_conditions );

    $pedidos_query = get_posts( $args );
    $pedidos       = array_map( 'fc_build_pedido_data', $pedidos_query );

    // Ordenar por número de pedido (FL-YYYYMMDD-NNN): orden alfabético = orden cronológico + secuencial
    if ( $status !== 'pendiente' ) {
        usort( $pedidos, function( $a, $b ) {
            return strcmp( $a['numero'] ?? '', $b['numero'] ?? '' );
        } );
    }

    wp_send_json_success( [ 'pedidos' => $pedidos ] );
}

// ─────────────────────────────────────────────
// Upload foto adicional para un item de pedido
// ─────────────────────────────────────────────
function fc_ajax_upload_foto() {
    fc_panel_verify_nonce();

    $pedido_id = intval( $_POST['pedido_id'] ?? 0 );
    $item_idx  = intval( $_POST['item_idx']  ?? 0 );

    if ( ! $pedido_id ) wp_send_json_error( [ 'message' => 'Pedido no válido.' ] );
    if ( empty( $_FILES['foto']['name'] ) ) wp_send_json_error( [ 'message' => 'No se recibió archivo.' ] );

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $att_id = media_handle_upload( 'foto', 0 );
    if ( is_wp_error( $att_id ) ) {
        wp_send_json_error( [ 'message' => $att_id->get_error_message() ] );
    }

    $url = wp_get_attachment_url( $att_id );

    // Guardar en el JSON del pedido
    $items_raw = get_post_meta( $pedido_id, '_fc_pedido_items', true );
    $items     = $items_raw ? json_decode( $items_raw, true ) : [];
    if ( is_array( $items ) && isset( $items[ $item_idx ] ) ) {
        if ( ! isset( $items[ $item_idx ]['fotos_extra'] ) || ! is_array( $items[ $item_idx ]['fotos_extra'] ) ) {
            $items[ $item_idx ]['fotos_extra'] = [];
        }
        $items[ $item_idx ]['fotos_extra'][] = esc_url_raw( $url );
        update_post_meta( $pedido_id, '_fc_pedido_items', wp_json_encode( $items ) );
    }

    wp_send_json_success( [ 'url' => $url ] );
}

// ─────────────────────────────────────────────
// Guardar URL de foto (ya en la librería de medios) en fotos_extra de un item
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_save_foto_url', 'fc_ajax_save_foto_url' );
function fc_ajax_save_foto_url() {
    fc_panel_verify_nonce();
    fc_panel_require_cap();

    $pedido_id = intval( $_POST['pedido_id'] ?? 0 );
    $item_idx  = intval( $_POST['item_idx']  ?? 0 );
    $url       = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );

    if ( ! $pedido_id || ! $url ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos.' ] );
    }

    $post = get_post( $pedido_id );
    if ( ! $post || $post->post_type !== 'pedido' ) {
        wp_send_json_error( [ 'message' => 'Pedido no encontrado.' ] );
    }

    $items_raw = get_post_meta( $pedido_id, '_fc_pedido_items', true );
    $items     = $items_raw ? json_decode( $items_raw, true ) : [];
    if ( is_array( $items ) && isset( $items[ $item_idx ] ) ) {
        if ( ! isset( $items[ $item_idx ]['fotos_extra'] ) || ! is_array( $items[ $item_idx ]['fotos_extra'] ) ) {
            $items[ $item_idx ]['fotos_extra'] = [];
        }
        $items[ $item_idx ]['fotos_extra'][] = $url;
        update_post_meta( $pedido_id, '_fc_pedido_items', wp_json_encode( $items ) );
    }

    wp_send_json_success( [ 'url' => $url ] );
}

// ─────────────────────────────────────────────
// Helper: build pedido data array from WP_Post
// ─────────────────────────────────────────────
function fc_build_pedido_data( $p ) {
    $historial = maybe_unserialize( get_post_meta( $p->ID, '_fc_pedido_historial', true ) );
    $historial = is_array( $historial ) ? $historial : [];
    $last      = ! empty( $historial ) ? end( $historial ) : null;

    // ── Items (multi-arreglo) ──
    $items_raw = get_post_meta( $p->ID, '_fc_pedido_items', true );
    $items     = [];
    if ( $items_raw ) {
        $decoded = json_decode( $items_raw, true );
        if ( is_array( $decoded ) ) {
            foreach ( $decoded as $item ) {
                // Resolve imagen_url: use stored value, else derive from arreglo meta
                $img = $item['imagen_url'] ?? '';
                if ( ! $img && ! empty( $item['arreglo_id'] ) ) {
                    $img = fc_get_arreglo_thumb_by_tamano_color(
                        (int) $item['arreglo_id'],
                        $item['tamano'] ?? '',
                        $item['color']  ?? ''
                    );
                }
                $fotos_extra_raw = is_array( $item['fotos_extra'] ?? null ) ? $item['fotos_extra'] : [];
                $items[] = [
                    'arreglo_id'            => (int) ( $item['arreglo_id'] ?? 0 ),
                    'arreglo_nombre'        => sanitize_text_field( $item['arreglo_nombre'] ?? '' ),
                    'imagen_url'            => esc_url_raw( $img ),
                    'fotos_extra'           => array_values( array_filter( array_map( 'esc_url_raw', $fotos_extra_raw ) ) ),
                    'tamano'                => sanitize_text_field( $item['tamano'] ?? '' ),
                    'color'                 => sanitize_text_field( $item['color']  ?? '' ),
                    'destinatario'          => sanitize_text_field( $item['destinatario'] ?? '' ),
                    'destinatario_telefono' => sanitize_text_field( $item['destinatario_telefono']  ?? '' ),
                    'destinatario_telefono2'=> sanitize_text_field( $item['destinatario_telefono2'] ?? '' ),
                    'mensaje_tarjeta'       => sanitize_textarea_field( $item['mensaje_tarjeta'] ?? '' ),
                ];
            }
        }
    }

    // Fallback: legacy single-item meta → wrap in array for backward compat
    if ( empty( $items ) ) {
        $arreglo_id = (int) get_post_meta( $p->ID, '_fc_pedido_arreglo_id', true );
        $items[] = [
            'arreglo_id'            => $arreglo_id,
            'arreglo_nombre'        => get_post_meta( $p->ID, '_fc_pedido_arreglo_nombre',          true ),
            'imagen_url'            => fc_get_pedido_arreglo_thumb( $p->ID ),
            'tamano'                => get_post_meta( $p->ID, '_fc_pedido_tamano',                  true ),
            'color'                 => get_post_meta( $p->ID, '_fc_pedido_color',                   true ),
            'destinatario'           => get_post_meta( $p->ID, '_fc_pedido_destinatario',            true ),
            'destinatario_telefono'  => get_post_meta( $p->ID, '_fc_pedido_destinatario_telefono',   true ),
            'destinatario_telefono2' => '',
            'mensaje_tarjeta'        => get_post_meta( $p->ID, '_fc_pedido_mensaje_tarjeta',         true ),
        ];
    }

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
        'nota'              => get_post_meta( $p->ID, '_fc_pedido_nota',             true ),
        'nota_floreria'     => get_post_meta( $p->ID, '_fc_pedido_nota_floreria',    true ),
        'canal'             => get_post_meta( $p->ID, '_fc_pedido_canal',            true ),
        'canal_nombre'      => get_post_meta( $p->ID, '_fc_pedido_canal_nombre',     true ),
        'canal_contacto'    => get_post_meta( $p->ID, '_fc_pedido_canal_contacto',   true ),
        'items'             => $items,
        // Legacy top-level fields kept for backward compat (print, single-pedido template)
        'arreglo_id'        => (int) get_post_meta( $p->ID, '_fc_pedido_arreglo_id',    true ),
        'arreglo_nombre'    => get_post_meta( $p->ID, '_fc_pedido_arreglo_nombre',   true ),
        'arreglo_thumb'     => fc_get_pedido_arreglo_thumb( $p->ID ),
        'tamano'            => get_post_meta( $p->ID, '_fc_pedido_tamano',           true ),
        'color'             => get_post_meta( $p->ID, '_fc_pedido_color',            true ),
        'destinatario'      => get_post_meta( $p->ID, '_fc_pedido_destinatario',     true ),
        'destinatario_telefono' => get_post_meta( $p->ID, '_fc_pedido_destinatario_telefono', true ),
        'mensaje_tarjeta'   => get_post_meta( $p->ID, '_fc_pedido_mensaje_tarjeta',  true ),
        'cliente_nombre'    => get_post_meta( $p->ID, '_fc_pedido_cliente_nombre',   true ),
        'cliente_telefono'  => get_post_meta( $p->ID, '_fc_pedido_cliente_telefono', true ),
        'pdf_url'           => get_post_meta( $p->ID, '_fc_pedido_pdf_url',          true ),
        'historial'         => $historial,
        'last_change'       => $last,
        'fecha_registro'    => (function( $p ) {
            $tz_tj  = new DateTimeZone( 'America/Tijuana' );
            $reg_dt = new DateTime( get_post_field( 'post_date_gmt', $p->ID ), new DateTimeZone( 'UTC' ) );
            $reg_dt->setTimezone( $tz_tj );
            return $reg_dt->format( 'd/m/Y H:i' );
        })( $p ),
    ];
}

// ─────────────────────────────────────────────
// Helper: get arreglo thumbnail by tamaño + color names
// ─────────────────────────────────────────────
function fc_get_arreglo_thumb_by_tamano_color( $arreglo_id, $tamano_nombre, $color_nombre ) {
    if ( ! $arreglo_id ) return '';
    $tamanos = get_post_meta( $arreglo_id, '_fc_tamanos', true );
    if ( ! is_array( $tamanos ) ) return '';

    $tamano_clean = trim( preg_replace( '/\s*\(\$[^)]+\)$/', '', $tamano_nombre ) );
    $img = '';

    foreach ( $tamanos as $t ) {
        if ( $tamano_clean && trim( $t['nombre'] ?? '' ) !== $tamano_clean ) continue;
        // Color match
        if ( $color_nombre && ! empty( $t['colores'] ) ) {
            foreach ( $t['colores'] as $c ) {
                if ( ( $c['nombre'] ?? '' ) === $color_nombre && ! empty( $c['imagen_url'] ) ) {
                    return $c['imagen_url'];
                }
            }
        }
        // Tamaño image
        if ( ! empty( $t['imagen_url'] ) ) { $img = $t['imagen_url']; break; }
    }
    // Featured image fallback
    if ( ! $img ) {
        $feat = get_post_thumbnail_id( $arreglo_id );
        if ( $feat ) {
            $src = wp_get_attachment_image_src( $feat, 'medium' );
            $img = $src ? $src[0] : '';
        }
    }
    return $img;
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
// AJAX: Crear pedido pendiente desde WhatsApp (público, sin login)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_fc_crear_pedido_whatsapp', 'fc_ajax_crear_pedido_whatsapp' );
add_action( 'wp_ajax_fc_crear_pedido_whatsapp',        'fc_ajax_crear_pedido_whatsapp' );
function fc_ajax_crear_pedido_whatsapp() {
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'fc_whatsapp_pedido' ) ) {
        wp_send_json_error( [ 'message' => 'Nonce inválido.' ], 403 );
    }

    $fecha_entrega = sanitize_text_field( wp_unslash( $_POST['fecha'] ?? '' ) );
    $numero        = fc_generar_numero_pendiente( $fecha_entrega ); // Número temporal P-

    $post_id = wp_insert_post( [
        'post_type'   => 'pedido',
        'post_status' => 'publish',
        'post_title'  => $numero,
    ] );

    if ( is_wp_error( $post_id ) ) {
        wp_send_json_error( [ 'message' => 'Error al crear el pedido.' ] );
    }

    // Parse items JSON
    $items_json_raw = wp_unslash( $_POST['items_json'] ?? '' );
    $items_raw      = json_decode( $items_json_raw, true );
    $items_clean    = [];
    if ( is_array( $items_raw ) ) {
        foreach ( $items_raw as $item ) {
            $items_clean[] = [
                'arreglo_id'             => (int)                    ( $item['arreglo_id']             ?? 0  ),
                'arreglo_nombre'         => sanitize_text_field(       $item['arreglo_nombre']         ?? '' ),
                'imagen_url'             => esc_url_raw(               $item['imagen_url']             ?? '' ),
                'fotos_extra'            => [],
                'tamano'                 => sanitize_text_field(       $item['tamano']                 ?? '' ),
                'color'                  => sanitize_text_field(       $item['color']                  ?? '' ),
                'destinatario'           => sanitize_text_field(       $item['destinatario']           ?? '' ),
                'destinatario_telefono'  => sanitize_text_field(       $item['destinatario_telefono']  ?? '' ),
                'destinatario_telefono2' => sanitize_text_field(       $item['destinatario_telefono2'] ?? '' ),
                'mensaje_tarjeta'        => sanitize_textarea_field(   $item['mensaje_tarjeta']        ?? '' ),
            ];
        }
    }
    $first = $items_clean[0] ?? [];

    $fields = [
        '_fc_pedido_numero'                  => $numero,
        '_fc_pedido_token'                   => '',
        '_fc_pedido_status'                  => 'pendiente',
        '_fc_pedido_tipo'                    => sanitize_key(          $_POST['tipo']             ?? 'envio' ),
        '_fc_pedido_fecha'                   => $fecha_entrega,
        '_fc_pedido_horario'                 => sanitize_text_field(   $_POST['horario']          ?? '' ),
        '_fc_pedido_direccion'               => sanitize_text_field(   $_POST['direccion']        ?? '' ),
        '_fc_pedido_hora_recoleccion'        => sanitize_text_field(   $_POST['hora_recoleccion'] ?? '' ),
        '_fc_pedido_canal'                   => 'whatsapp',
        '_fc_pedido_canal_nombre'            => '',
        '_fc_pedido_canal_contacto'          => '',
        '_fc_pedido_nota'                    => sanitize_textarea_field( $_POST['nota']           ?? '' ),
        // Legacy single-item fields for backward compat
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
        update_post_meta( $post_id, '_fc_pedido_items', wp_json_encode( $items_clean ) );
    }

    update_post_meta( $post_id, '_fc_pedido_historial', maybe_serialize( [] ) );

    wp_send_json_success( [ 'message' => 'Pendiente creado.', 'numero' => $numero ] );
}

// ─────────────────────────────────────────────
// AJAX: Crear pedido
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_crear_pedido', 'fc_ajax_crear_pedido' );
function fc_ajax_crear_pedido() {
    fc_panel_verify_nonce();
    fc_panel_require_cap();

    $modo         = sanitize_key( $_POST['modo'] ?? '' );
    $es_pendiente = ( $modo === 'pendiente' );

    $fecha_entrega = sanitize_text_field( wp_unslash( $_POST['fecha'] ?? '' ) );
    // Pendientes reciben número temporal P-; los aceptados reciben FL- de inmediato
    $numero        = $es_pendiente ? fc_generar_numero_pendiente( $fecha_entrega ) : fc_generar_numero_pedido( $fecha_entrega );
    $token         = $es_pendiente ? '' : fc_generar_token();

    $current_user = wp_get_current_user();

    $post_id = wp_insert_post( [
        'post_type'   => 'pedido',
        'post_status' => 'publish',
        'post_title'  => $numero,
    ] );

    if ( is_wp_error( $post_id ) ) {
        wp_send_json_error( [ 'message' => 'Error al crear el pedido.' ] );
    }

    // Parse and sanitize items
    $items_json_raw = wp_unslash( $_POST['items_json'] ?? '' );
    $items_raw      = json_decode( $items_json_raw, true );
    $items_clean    = [];
    if ( is_array( $items_raw ) ) {
        foreach ( $items_raw as $item ) {
            $items_clean[] = [
                'arreglo_id'             => (int)    ( $item['arreglo_id']             ?? 0  ),
                'arreglo_nombre'         => sanitize_text_field(    $item['arreglo_nombre']         ?? '' ),
                'imagen_url'             => esc_url_raw(            $item['imagen_url']             ?? '' ),
                'fotos_extra'            => array_values( array_filter( array_map( 'esc_url_raw',
                    is_array( $item['fotos_extra'] ?? null ) ? $item['fotos_extra'] : []
                ) ) ),
                'tamano'                 => sanitize_text_field(    $item['tamano']                 ?? '' ),
                'color'                  => sanitize_text_field(    $item['color']                  ?? '' ),
                'destinatario'           => sanitize_text_field(    $item['destinatario']           ?? '' ),
                'destinatario_telefono'  => sanitize_text_field(    $item['destinatario_telefono']  ?? '' ),
                'destinatario_telefono2' => sanitize_text_field(    $item['destinatario_telefono2'] ?? '' ),
                'mensaje_tarjeta'        => sanitize_textarea_field( $item['mensaje_tarjeta']       ?? '' ),
            ];
        }
    }
    $first = $items_clean[0] ?? [];

    $fields = [
        '_fc_pedido_numero'           => $numero,
        '_fc_pedido_token'            => $token,
        '_fc_pedido_status'           => $es_pendiente ? 'pendiente' : 'aceptado',
        '_fc_pedido_tipo'             => sanitize_key( $_POST['tipo'] ?? 'envio' ),
        '_fc_pedido_fecha'            => sanitize_text_field( $_POST['fecha'] ?? '' ),
        '_fc_pedido_horario'          => sanitize_text_field( $_POST['horario'] ?? '' ),
        '_fc_pedido_direccion'        => sanitize_text_field( $_POST['direccion'] ?? '' ),
        '_fc_pedido_hora_recoleccion' => sanitize_text_field( $_POST['hora_recoleccion'] ?? '' ),
        '_fc_pedido_canal'            => sanitize_key( $_POST['canal'] ?? '' ),
        '_fc_pedido_canal_nombre'     => sanitize_text_field( $_POST['canal_nombre']    ?? '' ),
        '_fc_pedido_canal_contacto'   => sanitize_text_field( $_POST['canal_contacto']  ?? '' ),
        '_fc_pedido_nota'             => sanitize_textarea_field( $_POST['nota'] ?? '' ),
        '_fc_pedido_registrado_por'   => get_current_user_id(),
        '_fc_pedido_pdf_url'          => esc_url_raw( $_POST['pdf_url'] ?? '' ),
        // Legacy single-item (first item) for backward compat
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

    // Multi-item JSON
    if ( ! empty( $items_clean ) ) {
        update_post_meta( $post_id, '_fc_pedido_items', wp_json_encode( $items_clean ) );
    }

    $historial = $es_pendiente ? [] : [ [
        'status'    => 'aceptado',
        'user_id'   => get_current_user_id(),
        'user_name' => $current_user->display_name,
        'timestamp' => current_time( 'mysql' ),
    ] ];
    update_post_meta( $post_id, '_fc_pedido_historial', maybe_serialize( $historial ) );

    if ( $es_pendiente ) {
        wp_send_json_success( [
            'message'   => 'Pedido guardado como pendiente.',
            'numero'    => $numero,
            'pedido_id' => $post_id,
            'pendiente' => true,
        ] );
    } else {
        $client_url = home_url( '/pedido/' . $token );
        wp_send_json_success( [
            'message'    => 'Pedido creado correctamente.',
            'numero'     => $numero,
            'token'      => $token,
            'client_url' => $client_url,
            'pedido_id'  => $post_id,
        ] );
    }
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

    $current_user   = wp_get_current_user();
    $old_status     = get_post_meta( $pedido_id, '_fc_pedido_status', true );
    $nuevo_numero   = null;
    $nuevo_token    = null;
    $nuevo_link     = null;

    // Si era pendiente (P-) y se está aceptando → asignar FL- y token definitivos
    if ( $old_status === 'pendiente' && $new_status === 'aceptado' ) {
        $fecha_entrega = get_post_meta( $pedido_id, '_fc_pedido_fecha', true );
        $nuevo_numero  = fc_generar_numero_pedido( $fecha_entrega );
        $nuevo_token   = fc_generar_token();
        $nuevo_link    = home_url( '/pedido/' . $nuevo_token );

        update_post_meta( $pedido_id, '_fc_pedido_numero', $nuevo_numero );
        update_post_meta( $pedido_id, '_fc_pedido_token',  $nuevo_token  );
        wp_update_post( [ 'ID' => $pedido_id, 'post_title' => $nuevo_numero ] );
    }

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
        'message'     => 'Estado actualizado.',
        'new_status'  => $new_status,
        'label'       => fc_pedido_status_label( $new_status ),
        'last_change' => $entry,
        'nuevo_numero' => $nuevo_numero,
        'nuevo_token'  => $nuevo_token,
        'nuevo_link'   => $nuevo_link,
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

    // Parse and sanitize items
    $items_json_raw = wp_unslash( $_POST['items_json'] ?? '' );
    $items_raw      = json_decode( $items_json_raw, true );
    $items_clean    = [];
    if ( is_array( $items_raw ) ) {
        foreach ( $items_raw as $item ) {
            $items_clean[] = [
                'arreglo_id'             => (int)    ( $item['arreglo_id']             ?? 0  ),
                'arreglo_nombre'         => sanitize_text_field(    $item['arreglo_nombre']         ?? '' ),
                'imagen_url'             => esc_url_raw(            $item['imagen_url']             ?? '' ),
                'fotos_extra'            => array_values( array_filter( array_map( 'esc_url_raw',
                    is_array( $item['fotos_extra'] ?? null ) ? $item['fotos_extra'] : []
                ) ) ),
                'tamano'                 => sanitize_text_field(    $item['tamano']                 ?? '' ),
                'color'                  => sanitize_text_field(    $item['color']                  ?? '' ),
                'destinatario'           => sanitize_text_field(    $item['destinatario']           ?? '' ),
                'destinatario_telefono'  => sanitize_text_field(    $item['destinatario_telefono']  ?? '' ),
                'destinatario_telefono2' => sanitize_text_field(    $item['destinatario_telefono2'] ?? '' ),
                'mensaje_tarjeta'        => sanitize_textarea_field( $item['mensaje_tarjeta']       ?? '' ),
            ];
        }
    }
    $first = $items_clean[0] ?? [];

    $fields = [
        '_fc_pedido_tipo'             => sanitize_key( $_POST['tipo'] ?? 'envio' ),
        '_fc_pedido_fecha'            => sanitize_text_field( $_POST['fecha'] ?? '' ),
        '_fc_pedido_horario'          => sanitize_text_field( $_POST['horario'] ?? '' ),
        '_fc_pedido_direccion'        => sanitize_text_field( $_POST['direccion'] ?? '' ),
        '_fc_pedido_hora_recoleccion' => sanitize_text_field( $_POST['hora_recoleccion'] ?? '' ),
        '_fc_pedido_canal'            => sanitize_key( $_POST['canal'] ?? '' ),
        '_fc_pedido_canal_nombre'     => sanitize_text_field( $_POST['canal_nombre']    ?? '' ),
        '_fc_pedido_canal_contacto'   => sanitize_text_field( $_POST['canal_contacto']  ?? '' ),
        '_fc_pedido_nota'             => sanitize_textarea_field( $_POST['nota'] ?? '' ),
        '_fc_pedido_pdf_url'          => esc_url_raw( $_POST['pdf_url'] ?? '' ),
        // Legacy single-item for backward compat
        '_fc_pedido_arreglo_id'              => $first['arreglo_id']            ?? 0,
        '_fc_pedido_arreglo_nombre'          => $first['arreglo_nombre']        ?? '',
        '_fc_pedido_tamano'                  => $first['tamano']                ?? '',
        '_fc_pedido_color'                   => $first['color']                 ?? '',
        '_fc_pedido_destinatario'            => $first['destinatario']          ?? '',
        '_fc_pedido_destinatario_telefono'   => $first['destinatario_telefono'] ?? '',
        '_fc_pedido_mensaje_tarjeta'         => $first['mensaje_tarjeta']       ?? '',
    ];

    foreach ( $fields as $key => $value ) {
        update_post_meta( $pedido_id, $key, $value );
    }

    // Multi-item JSON
    if ( ! empty( $items_clean ) ) {
        update_post_meta( $pedido_id, '_fc_pedido_items', wp_json_encode( $items_clean ) );
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
// AJAX: Contar pedidos pendientes (solo admin, para badge del tab)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_panel_count_pendientes', 'fc_ajax_count_pendientes' );
function fc_ajax_count_pendientes() {
    fc_panel_verify_nonce();
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    }
    wp_send_json_success( [ 'count' => fc_count_pedidos_pendientes() ] );
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
add_action( 'wp_ajax_fc_panel_upload_foto', 'fc_ajax_upload_foto' );
add_action( 'wp_ajax_fc_panel_upload_pdf',  'fc_ajax_upload_pdf'  );

// ─────────────────────────────────────────────
// AJAX: Upload PDF for a pedido
// ─────────────────────────────────────────────
function fc_ajax_upload_pdf() {
    fc_panel_verify_nonce();
    fc_panel_require_cap();

    if ( empty( $_FILES['pdf'] ) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( [ 'message' => 'No se recibió el archivo.' ] );
    }

    // Only allow PDF
    $ftype = wp_check_filetype( $_FILES['pdf']['name'] );
    if ( $ftype['ext'] !== 'pdf' ) {
        wp_send_json_error( [ 'message' => 'Solo se permiten archivos PDF.' ] );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $_FILES['pdf']['name'] = sanitize_file_name( $_FILES['pdf']['name'] );
    $attachment_id = media_handle_upload( 'pdf', 0 );

    if ( is_wp_error( $attachment_id ) ) {
        wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
    }

    $url = wp_get_attachment_url( $attachment_id );
    wp_send_json_success( [ 'url' => $url, 'attachment_id' => $attachment_id ] );
}

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
// POST login handler — /panel-florista/ con fc_al_token en el body
// Nunca cacheado (es un POST). Establece la cookie y redirige a GET /panel-florista/
// ─────────────────────────────────────────────
add_action( 'template_redirect', 'fc_handle_panel_post_login', 1 );
function fc_handle_panel_post_login() {
    if ( ! get_query_var( 'fc_panel_florista' ) ) return;
    if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) return;
    if ( empty( $_POST['fc_al_token'] ) ) return;

    $token   = sanitize_text_field( wp_unslash( $_POST['fc_al_token'] ) );
    $user_id = fc_verify_autologin_token( $token );

    if ( $user_id ) {
        $user = get_user_by( 'id', $user_id );
        if ( $user ) {
            wp_set_current_user( $user->ID );
            wp_set_auth_cookie( $user->ID, true );
        }
    }

    // Redirigir a GET con parámetro de cache-busting.
    // La mayoría de plugins de caché (WP Super Cache, W3TC, LiteSpeed…) no cachean
    // URLs con query strings, así que esto garantiza que PHP procese la petición
    // y evalúe la cookie recién establecida.
    wp_safe_redirect( home_url( '/panel-florista/?nc=' . time() ) );
    exit;
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
    $canal           = get_post_meta( $pedido_id, '_fc_pedido_canal',                 true );
    $canal_nombre    = get_post_meta( $pedido_id, '_fc_pedido_canal_nombre',          true );
    $canal_contacto  = get_post_meta( $pedido_id, '_fc_pedido_canal_contacto',        true );
    $canal_labels    = [ 'whatsapp' => 'WhatsApp', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'otro' => 'Otro' ];
    $canal_label     = $canal ? ( $canal_labels[ $canal ] ?? ucfirst( $canal ) ) : '';
    $canal_detalle   = implode( ' · ', array_filter( [ $canal_nombre, $canal_contacto ] ) );
    $nota        = get_post_meta( $pedido_id, '_fc_pedido_nota',             true );
    $nota_fl     = get_post_meta( $pedido_id, '_fc_pedido_nota_floreria',    true );
    $shop_name   = 'Florería Monarca';
    $status_lbl  = fc_pedido_status_label( $status );

    // Multi-item
    $items_raw = get_post_meta( $pedido_id, '_fc_pedido_items', true );
    $items     = [];
    if ( $items_raw ) {
        $decoded = json_decode( $items_raw, true );
        if ( is_array( $decoded ) ) $items = $decoded;
    }
    if ( empty( $items ) ) {
        // Legacy fallback
        $items[] = [
            'arreglo_id'            => (int) get_post_meta( $pedido_id, '_fc_pedido_arreglo_id',   true ),
            'arreglo_nombre'        => get_post_meta( $pedido_id, '_fc_pedido_arreglo_nombre',      true ),
            'imagen_url'            => fc_get_pedido_arreglo_thumb( $pedido_id ),
            'tamano'                => get_post_meta( $pedido_id, '_fc_pedido_tamano',              true ),
            'color'                 => get_post_meta( $pedido_id, '_fc_pedido_color',               true ),
            'destinatario'          => get_post_meta( $pedido_id, '_fc_pedido_destinatario',        true ),
            'destinatario_telefono' => get_post_meta( $pedido_id, '_fc_pedido_destinatario_telefono', true ),
            'mensaje_tarjeta'       => get_post_meta( $pedido_id, '_fc_pedido_mensaje_tarjeta',     true ),
        ];
    }

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

  /* ── Cuerpo ── */
  .fc-doc-body {
    padding: 22px 26px;
  }

  /* ── Items list ── */
  .fc-items-print-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin-bottom: 16px;
  }
  .fc-item-print-row {
    display: flex;
    gap: 16px;
    border: 1px solid #f3e8f0;
    border-radius: 8px;
    overflow: hidden;
    background: #fdf2f8;
  }
  .fc-item-print-thumb {
    flex-shrink: 0;
    width: 110px;
    background: #fdf2f8;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 10px;
  }
  .fc-item-print-thumb img {
    width: 90px;
    height: 90px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid #f9a8d4;
    display: block;
  }
  .fc-item-print-no-img {
    width: 90px;
    height: 90px;
    border-radius: 8px;
    border: 2px dashed #f9a8d4;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #c084a8;
    font-size: 10px;
    text-align: center;
    background: #fff;
  }
  .fc-item-print-info {
    padding: 12px 14px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
    justify-content: center;
  }
  .fc-item-print-name {
    font-size: 13px;
    font-weight: 700;
    color: #9d174d;
  }
  .fc-item-print-sub {
    font-size: 11px;
    color: #6b7280;
  }
  .fc-item-print-dest {
    font-size: 11px;
    color: #374151;
    font-weight: 600;
  }
  .fc-item-print-tarjeta {
    font-size: 11px;
    color: #374151;
    font-style: italic;
  }

  /* Columna info — datos de entrega */
  .fc-info-col {
    padding: 0;
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
            <div class="fc-fecha-reg">Registrado el <?php
                $tz_tj_p  = new DateTimeZone( 'America/Tijuana' );
                $reg_dt_p = new DateTime( get_post_field( 'post_date_gmt', $post->ID ), new DateTimeZone( 'UTC' ) );
                $reg_dt_p->setTimezone( $tz_tj_p );
                echo esc_html( $reg_dt_p->format( 'd/m/Y H:i' ) );
            ?></div>
        </div>
    </div>

    <!-- Cuerpo -->
    <div class="fc-doc-body">

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
        <?php if ( $canal_label ) : ?>
        <div class="fc-row">
            <span class="fc-row-label">Canal</span>
            <span class="fc-row-value"><?php echo esc_html( $canal_label ); ?><?php echo $canal_detalle ? ' · ' . esc_html( $canal_detalle ) : ''; ?></span>
        </div>
        <?php endif; ?>
        <?php if ( $nota ) : ?>
        <div class="fc-row">
            <span class="fc-row-label">Nota</span>
            <span class="fc-row-value"><?php echo esc_html( $nota ); ?></span>
        </div>
        <?php endif; ?>
        <?php if ( $nota_fl ) : ?>
        <div class="fc-row">
            <span class="fc-row-label">Florería</span>
            <span class="fc-row-value italic"><?php echo esc_html( $nota_fl ); ?></span>
        </div>
        <?php endif; ?>

        <hr class="fc-divider" />

        <!-- Arreglos -->
        <div class="fc-section-title">Arreglo<?php echo count( $items ) > 1 ? 's' : ''; ?></div>
        <div class="fc-items-print-list">
        <?php foreach ( $items as $item ) :
            $item_img   = $item['imagen_url'] ?? '';
            if ( ! $item_img && ! empty( $item['arreglo_id'] ) ) {
                $item_img = fc_get_arreglo_thumb_by_tamano_color(
                    (int) $item['arreglo_id'],
                    $item['tamano'] ?? '',
                    $item['color']  ?? ''
                );
            }
            $item_sub   = array_filter( [
                $item['tamano'] ?? '',
                ( ! empty( $item['color'] ) && strpos( $item['color'], '--' ) !== 0 ) ? $item['color'] : '',
            ] );
            $item_dest  = $item['destinatario']           ?? '';
            $item_tel   = $item['destinatario_telefono']  ?? '';
            $item_tel2  = $item['destinatario_telefono2'] ?? '';
            $item_tarj  = $item['mensaje_tarjeta']        ?? '';
        ?>
        <div class="fc-item-print-row">
            <div class="fc-item-print-thumb">
                <?php if ( $item_img ) : ?>
                    <img src="<?php echo esc_url( $item_img ); ?>" alt="" />
                <?php else : ?>
                    <div class="fc-item-print-no-img">Sin foto</div>
                <?php endif; ?>
            </div>
            <div class="fc-item-print-info">
                <div class="fc-item-print-name"><?php echo esc_html( $item['arreglo_nombre'] ?? '' ); ?></div>
                <?php if ( $item_sub ) : ?>
                <div class="fc-item-print-sub"><?php echo esc_html( implode( ' · ', $item_sub ) ); ?></div>
                <?php endif; ?>
                <?php if ( $item_dest ) : ?>
                <div class="fc-item-print-dest">Para: <?php echo esc_html( $item_dest ); ?><?php echo $item_tel ? ' · ' . esc_html( $item_tel ) : ''; ?><?php echo $item_tel2 ? ' · ' . esc_html( $item_tel2 ) : ''; ?></div>
                <?php endif; ?>
                <?php if ( $item_tarj ) : ?>
                <div class="fc-item-print-tarjeta">"<?php echo esc_html( $item_tarj ); ?>"</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

    </div><!-- .fc-doc-body -->

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
