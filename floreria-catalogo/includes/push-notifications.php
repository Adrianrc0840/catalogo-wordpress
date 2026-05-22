<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────
// Servir sw.js desde la raíz del sitio
// ─────────────────────────────────────────────
add_action( 'init', function () {
    add_rewrite_rule( '^sw\.js$', 'index.php?fc_sw_js=1', 'top' );
} );
add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'fc_sw_js';
    return $vars;
} );
add_action( 'template_redirect', function () {
    if ( ! get_query_var( 'fc_sw_js' ) ) return;
    header( 'Content-Type: application/javascript; charset=utf-8' );
    header( 'Service-Worker-Allowed: /' );
    header( 'Cache-Control: no-cache' );
    readfile( FC_PATH . 'assets/js/sw.js' );
    exit;
} );

// ─────────────────────────────────────────────
// Helpers base64url
// ─────────────────────────────────────────────
function fc_push_b64u_encode( $data ) {
    return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}
function fc_push_b64u_decode( $data ) {
    $pad = strlen( $data ) % 4;
    if ( $pad ) $data .= str_repeat( '=', 4 - $pad );
    return base64_decode( strtr( $data, '-_', '+/' ) );
}

// ─────────────────────────────────────────────
// VAPID — generar y obtener claves
// ─────────────────────────────────────────────
function fc_push_get_vapid_keys() {
    $keys = get_option( 'fc_push_vapid_keys', [] );
    if ( ! empty( $keys['public'] ) && ! empty( $keys['private'] ) ) return $keys;

    $key = openssl_pkey_new( [
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ] );
    if ( ! $key ) {
        error_log( '[FC Push] openssl_pkey_new falló: ' . openssl_error_string() );
        return null;
    }

    $details = openssl_pkey_get_details( $key );
    $x = str_pad( $details['ec']['x'], 32, "\x00", STR_PAD_LEFT );
    $y = str_pad( $details['ec']['y'], 32, "\x00", STR_PAD_LEFT );
    $public_raw = "\x04" . $x . $y;

    openssl_pkey_export( $key, $private_pem );

    $keys = [
        'public'  => fc_push_b64u_encode( $public_raw ),
        'private' => $private_pem,
    ];
    update_option( 'fc_push_vapid_keys', $keys );
    return $keys;
}

// ─────────────────────────────────────────────
// Suscripciones — guardar / leer / eliminar
// ─────────────────────────────────────────────
function fc_push_get_subscriptions() {
    return get_option( 'fc_push_subscriptions', [] );
}
function fc_push_save_subscriptions( $subs ) {
    update_option( 'fc_push_subscriptions', $subs );
}
function fc_push_add_subscription( $endpoint, $p256dh, $auth, $user_id = 0 ) {
    $subs = fc_push_get_subscriptions();
    foreach ( $subs as &$s ) {
        if ( $s['endpoint'] === $endpoint ) {
            $s['p256dh']  = $p256dh;
            $s['auth']    = $auth;
            $s['user_id'] = $user_id;
            fc_push_save_subscriptions( $subs );
            return;
        }
    }
    $subs[] = compact( 'endpoint', 'p256dh', 'auth', 'user_id' );
    fc_push_save_subscriptions( $subs );
}
function fc_push_remove_subscription( $endpoint ) {
    $subs = array_values( array_filter(
        fc_push_get_subscriptions(),
        fn( $s ) => $s['endpoint'] !== $endpoint
    ) );
    fc_push_save_subscriptions( $subs );
}

// ─────────────────────────────────────────────
// AJAX: suscribir dispositivo
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_push_subscribe', 'fc_ajax_push_subscribe' );
function fc_ajax_push_subscribe() {
    check_ajax_referer( 'fc_panel_nonce', 'nonce' );
    $raw = json_decode( wp_unslash( $_POST['subscription'] ?? '' ), true );
    if ( empty( $raw['endpoint'] ) ) wp_send_json_error();

    $endpoint = esc_url_raw( $raw['endpoint'] );
    $p256dh   = sanitize_text_field( $raw['keys']['p256dh'] ?? '' );
    $auth     = sanitize_text_field( $raw['keys']['auth']   ?? '' );

    fc_push_add_subscription( $endpoint, $p256dh, $auth, get_current_user_id() );
    wp_send_json_success();
}

// ─────────────────────────────────────────────
// Cron: registrar intervalo de 5 minutos
// ─────────────────────────────────────────────
add_filter( 'cron_schedules', function ( $schedules ) {
    $schedules['fc_every5'] = [
        'interval' => 300,
        'display'  => 'Cada 5 minutos (Florería)',
    ];
    return $schedules;
} );

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'fc_push_check_pedidos' ) ) {
        wp_schedule_event( time(), 'fc_every5', 'fc_push_check_pedidos' );
    }
} );

add_action( 'fc_push_check_pedidos', 'fc_push_run_check' );
function fc_push_run_check() {
    $tz  = new DateTimeZone( 'America/Tijuana' );
    $now = new DateTime( 'now', $tz );
    $hoy = $now->format( 'Y-m-d' );

    $pedidos = get_posts( [
        'post_type'      => 'pedido',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [
            [ 'key' => '_fc_pedido_fecha',  'value' => $hoy ],
            [ 'key' => '_fc_pedido_status', 'value' => [ 'aceptado', 'en_preparacion' ], 'compare' => 'IN' ],
        ],
    ] );

    foreach ( $pedidos as $pedido ) {
        $pid              = $pedido->ID;
        $tipo             = get_post_meta( $pid, '_fc_pedido_tipo',             true );
        $horario          = get_post_meta( $pid, '_fc_pedido_horario',          true );
        $hora_recoleccion = get_post_meta( $pid, '_fc_pedido_hora_recoleccion', true );
        $numero           = get_post_meta( $pid, '_fc_pedido_numero',           true );
        $direccion        = get_post_meta( $pid, '_fc_pedido_direccion',        true );

        $start = $tipo === 'recoleccion'
            ? fc_push_parse_hora( $hora_recoleccion, false )
            : fc_push_parse_hora( $horario, true );

        if ( ! $start ) continue;

        $current_minutes = (int) $now->format( 'H' ) * 60 + (int) $now->format( 'i' );
        $diff = $start - $current_minutes;
        if ( $diff < 26 || $diff > 34 ) continue;

        $notified_key = '_fc_push_notified_' . date( 'Ymd' );
        if ( get_post_meta( $pid, $notified_key, true ) ) continue;
        update_post_meta( $pid, $notified_key, 1 );

        $tipo_label = $tipo === 'recoleccion' ? 'Recolección' : 'Envío';
        $body       = $tipo_label . ' en ~30 min';
        if ( $direccion ) $body .= ' · ' . wp_strip_all_tags( $direccion );

        fc_push_send_to_all( '🌸 ' . $numero, $body );
    }
}

// ─────────────────────────────────────────────
// Parsear horario → minutos desde medianoche
// ─────────────────────────────────────────────
function fc_push_parse_hora( $str, $is_range ) {
    if ( ! $str ) return null;
    if ( preg_match( '/(\d{1,2}):(\d{2})\s*(am|pm)?/i', $str, $m ) ) {
        $h    = (int) $m[1];
        $min  = (int) $m[2];
        $ampm = strtolower( $m[3] ?? '' );
        if ( $ampm === 'pm' && $h !== 12 ) $h += 12;
        if ( $ampm === 'am' && $h === 12 ) $h  = 0;
        return $h * 60 + $min;
    }
    return null;
}

// ─────────────────────────────────────────────
// Enviar push a todas las suscripciones
// ─────────────────────────────────────────────
function fc_push_send_to_all( $title, $body, $url = '/panel-florista/' ) {
    $subs = fc_push_get_subscriptions();
    $keys = fc_push_get_vapid_keys();
    if ( ! $keys || empty( $subs ) ) return;

    $payload = json_encode( compact( 'title', 'body', 'url' ) );

    foreach ( $subs as $sub ) {
        $result = fc_push_send_one( $sub, $payload, $keys );
        if ( is_wp_error( $result ) ) continue;
        $code = wp_remote_retrieve_response_code( $result );
        if ( in_array( $code, [ 404, 410 ], true ) ) {
            fc_push_remove_subscription( $sub['endpoint'] );
        }
    }
}

// ─────────────────────────────────────────────
// Enviar push a una suscripción
// ─────────────────────────────────────────────
function fc_push_send_one( $sub, $payload, $keys ) {
    $endpoint = $sub['endpoint'];
    $p256dh   = $sub['p256dh'];
    $auth     = $sub['auth'];

    $encrypted = fc_push_encrypt( $payload, $p256dh, $auth );
    if ( ! $encrypted ) return new WP_Error( 'encrypt_fail', 'Encryption failed' );

    $jwt = fc_push_vapid_jwt( $endpoint, $keys['private'], $keys['public'] );
    if ( ! $jwt ) return new WP_Error( 'jwt_fail', 'JWT failed' );

    return wp_remote_post( $endpoint, [
        'method'  => 'POST',
        'timeout' => 15,
        'headers' => [
            'Authorization'    => "vapid t={$jwt},k={$keys['public']}",
            'Content-Type'     => 'application/octet-stream',
            'Content-Encoding' => 'aes128gcm',
            'TTL'              => '86400',
        ],
        'body'    => $encrypted,
    ] );
}

// ─────────────────────────────────────────────
// Cifrado Web Push — RFC 8291 (aes128gcm)
// ─────────────────────────────────────────────
function fc_push_encrypt( $payload, $p256dh, $auth ) {
    $receiver_pub = fc_push_b64u_decode( $p256dh );
    $auth_secret  = fc_push_b64u_decode( $auth );

    $local_key = openssl_pkey_new( [
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ] );
    if ( ! $local_key ) return false;

    $det = openssl_pkey_get_details( $local_key );
    $x   = str_pad( $det['ec']['x'], 32, "\x00", STR_PAD_LEFT );
    $y   = str_pad( $det['ec']['y'], 32, "\x00", STR_PAD_LEFT );
    $local_pub = "\x04" . $x . $y;

    $pem          = fc_push_raw_pub_to_pem( $receiver_pub );
    $receiver_key = openssl_pkey_get_public( $pem );
    if ( ! $receiver_key ) return false;

    $shared = openssl_pkey_derive( $receiver_key, $local_key, 32 );
    if ( ! $shared ) return false;

    $info_ikm = "WebPush: info\x00" . $receiver_pub . $local_pub;
    $ikm      = fc_push_hkdf( $auth_secret, $shared, $info_ikm, 32 );

    $salt  = random_bytes( 16 );
    $cek   = fc_push_hkdf( $salt, $ikm, "Content-Encoding: aes128gcm\x00", 16 );
    $nonce = fc_push_hkdf( $salt, $ikm, "Content-Encoding: nonce\x00",      12 );

    $tag        = '';
    $ciphertext = openssl_encrypt( $payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
    if ( $ciphertext === false ) return false;

    return $salt . pack( 'N', 4096 ) . chr( 65 ) . $local_pub . $ciphertext . $tag;
}

function fc_push_hkdf( $salt, $ikm, $info, $length ) {
    $prk = hash_hmac( 'sha256', $ikm, $salt, true );
    $t = $okm = '';
    for ( $i = 1; strlen( $okm ) < $length; $i++ ) {
        $t    = hash_hmac( 'sha256', $t . $info . chr( $i ), $prk, true );
        $okm .= $t;
    }
    return substr( $okm, 0, $length );
}

function fc_push_raw_pub_to_pem( $raw ) {
    $der_prefix = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
                . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";
    $der = $der_prefix . $raw;
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split( base64_encode( $der ), 64, "\n" )
        . "-----END PUBLIC KEY-----\n";
}

// ─────────────────────────────────────────────
// VAPID JWT (ES256)
// ─────────────────────────────────────────────
function fc_push_vapid_jwt( $endpoint, $private_pem, $public_b64u ) {
    $audience = parse_url( $endpoint, PHP_URL_SCHEME ) . '://' . parse_url( $endpoint, PHP_URL_HOST );
    $header   = fc_push_b64u_encode( json_encode( [ 'typ' => 'JWT', 'alg' => 'ES256' ] ) );
    $claims   = fc_push_b64u_encode( json_encode( [
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => 'mailto:' . get_option( 'admin_email' ),
    ] ) );

    $input = $header . '.' . $claims;
    $key   = openssl_pkey_get_private( $private_pem );
    if ( ! $key ) return false;

    openssl_sign( $input, $der_sig, $key, OPENSSL_ALGO_SHA256 );

    $offset = 2;
    $offset++;
    $r_len = ord( $der_sig[ $offset++ ] );
    $r     = substr( $der_sig, $offset, $r_len ); $offset += $r_len;
    $offset++;
    $s_len = ord( $der_sig[ $offset++ ] );
    $s     = substr( $der_sig, $offset, $s_len );

    $r = str_pad( ltrim( $r, "\x00" ), 32, "\x00", STR_PAD_LEFT );
    $s = str_pad( ltrim( $s, "\x00" ), 32, "\x00", STR_PAD_LEFT );

    return $input . '.' . fc_push_b64u_encode( $r . $s );
}

// ─────────────────────────────────────────────
// AJAX: notificación de prueba
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_push_test', function () {
    check_ajax_referer( 'fc_panel_nonce', 'nonce' );
    fc_push_send_to_all( '🌸 Prueba Monarca', 'Las notificaciones push están funcionando ✓' );
    wp_send_json_success( [ 'subs' => count( fc_push_get_subscriptions() ) ] );
} );
