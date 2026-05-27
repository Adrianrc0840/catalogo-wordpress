<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────────────────────────────────────
// OneSignal Push Notifications — Florería Monarca
// La REST API Key se guarda en wp_options (nunca sale al cliente).
// El App ID se usa en el front-end para inicializar el SDK.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Helper de debug temporal: guarda mensajes en wp_options para verlos en el admin.
 * Se limpia automáticamente después de 10 minutos o al hacer clic en "Limpiar debug".
 */
function fc_push_debug( $msg ) {
    $log   = get_option( 'fc_push_debug_log', [] );
    $log[] = '[' . current_time( 'H:i:s' ) . '] ' . $msg;
    update_option( 'fc_push_debug_log', $log, false );
}

// Mostrar el debug log como aviso en la página de pedidos
add_action( 'admin_notices', function () {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'fc-pedidos' ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Limpiar log si se solicitó
    if ( isset( $_GET['fc_clear_debug'] ) && check_admin_referer( 'fc_clear_debug' ) ) {
        delete_option( 'fc_push_debug_log' );
        return;
    }

    $log = get_option( 'fc_push_debug_log', [] );
    if ( empty( $log ) ) return;

    $clear_url = wp_nonce_url(
        add_query_arg( 'fc_clear_debug', '1', admin_url( 'edit.php?post_type=arreglo&page=fc-pedidos' ) ),
        'fc_clear_debug'
    );
    echo '<div class="notice notice-info" style="font-family:monospace;font-size:13px;">';
    echo '<p><strong>🔍 FC Push Debug</strong> &nbsp; <a href="' . esc_url( $clear_url ) . '">Limpiar</a></p>';
    echo '<ul style="margin:0 0 8px 16px;list-style:disc;">';
    foreach ( $log as $line ) {
        echo '<li>' . esc_html( $line ) . '</li>';
    }
    echo '</ul></div>';
} );

/**
 * Envía una notificación push a todos los suscriptores via OneSignal REST API.
 *
 * @param string $title   Título de la notificación.
 * @param string $message Cuerpo de la notificación.
 * @param string $url     URL a abrir al tocar (por defecto: panel de floristas).
 */
function fc_onesignal_send( $title, $message, $url = '' ) {
    $app_id  = get_option( 'fc_onesignal_app_id',  '' );
    $api_key = get_option( 'fc_onesignal_api_key', '' );

    if ( ! $app_id || ! $api_key ) return 'sin_credenciales';

    if ( ! $url ) {
        $url = home_url( '/panel-florista/' );
    }

    $body = [
        'app_id'            => $app_id,
        'included_segments' => [ 'All' ],
        'headings'          => [ 'en' => $title,   'es' => $title   ],
        'contents'          => [ 'en' => $message, 'es' => $message ],
        'web_url'           => $url,
    ];

    $response = wp_remote_post( 'https://onesignal.com/api/v1/notifications', [
        'headers' => [
            'Authorization' => 'Key ' . $api_key,
            'Content-Type'  => 'application/json; charset=utf-8',
            'Accept'        => 'application/json',
        ],
        'body'    => wp_json_encode( $body ),
        'timeout' => 20,
        'sslverify' => false,
    ] );

    if ( is_wp_error( $response ) ) {
        return 'wp_error: ' . $response->get_error_message();
    }

    return wp_remote_retrieve_body( $response );
}

/**
 * Dispara la notificación cuando se guarda un pedido nuevo (no en actualizaciones).
 * Aplica tanto para pedidos creados desde el panel como desde el PDV.
 */
add_action( 'fc_pedido_creado', 'fc_onesignal_nuevo_pedido' );
function fc_onesignal_nuevo_pedido( $post_id ) {
    fc_push_debug( 'fc_onesignal_nuevo_pedido llamada para pedido ' . $post_id );
    $numero   = get_post_meta( $post_id, '_fc_pedido_numero',           true );
    $tipo     = get_post_meta( $post_id, '_fc_pedido_tipo',             true );
    $horario  = get_post_meta( $post_id, '_fc_pedido_horario',          true );
    $hora_rec = get_post_meta( $post_id, '_fc_pedido_hora_recoleccion', true );
    $fecha    = get_post_meta( $post_id, '_fc_pedido_fecha',            true );

    $tipo_label = ( $tipo === 'recoleccion' ) ? 'Recolección' : 'Envío';

    // Hora de entrega
    $hora_str = '';
    if ( $tipo === 'recoleccion' && $hora_rec ) {
        $hora_str = $hora_rec;
    } elseif ( $horario ) {
        // Tomar la hora de inicio del rango "10:00am – 12:00pm"
        $partes = preg_split( '/\s*[–-]\s*/', $horario );
        $hora_str = ! empty( $partes[0] ) ? trim( $partes[0] ) : $horario;
    }

    $title   = '🌸 Nuevo pedido' . ( $numero ? ' #' . $numero : '' );
    $message = $tipo_label . ( $hora_str ? ' · ' . $hora_str : '' ) . ( $fecha ? ' · ' . $fecha : '' );

    $result = fc_onesignal_send( $title, $message );
    fc_push_debug( 'Resultado OneSignal pedido ' . $post_id . ': ' . ( is_string( $result ) ? $result : wp_json_encode( $result ) ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// Cron: notificación 30 minutos antes del inicio de cada entrega
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Registra el intervalo de 5 minutos para el cron.
 */
add_filter( 'cron_schedules', 'fc_onesignal_cron_interval' );
function fc_onesignal_cron_interval( $schedules ) {
    $schedules['fc_cada_5min'] = [
        'interval' => 300,
        'display'  => 'Cada 5 minutos (Florería)',
    ];
    return $schedules;
}

/**
 * Programa el cron al cargar WordPress (si aún no está programado).
 */
add_action( 'wp', 'fc_onesignal_schedule_cron' );
function fc_onesignal_schedule_cron() {
    if ( ! wp_next_scheduled( 'fc_onesignal_check_pedidos' ) ) {
        wp_schedule_event( time(), 'fc_cada_5min', 'fc_onesignal_check_pedidos' );
    }
}

/**
 * Cancela el cron al desactivar el plugin.
 */
register_deactivation_hook( FC_PATH . 'floreria-catalogo.php', 'fc_onesignal_clear_cron' );
function fc_onesignal_clear_cron() {
    $timestamp = wp_next_scheduled( 'fc_onesignal_check_pedidos' );
    if ( $timestamp ) wp_unschedule_event( $timestamp, 'fc_onesignal_check_pedidos' );
}

/**
 * Convierte una hora "10:00am" / "1:00pm" a timestamp real usando la zona
 * horaria configurada en WordPress (evita mezclar UTC del servidor).
 *
 * @param string      $hora_label  p.ej. "10:00am"
 * @param string      $fecha_ymd   p.ej. "2026-05-22"
 * @param DateTimeZone $tz         Zona horaria de WordPress.
 * @return int|false
 */
function fc_parse_hora_a_timestamp( $hora_label, $fecha_ymd, $tz ) {
    $hora_label = trim( strtolower( $hora_label ) );

    if ( ! preg_match( '/^(\d{1,2}):(\d{2})(am|pm)$/', $hora_label, $m ) ) {
        return false;
    }

    $h   = (int) $m[1];
    $min = (int) $m[2];
    $mer = $m[3];

    if ( $mer === 'pm' && $h !== 12 ) $h += 12;
    if ( $mer === 'am' && $h === 12 ) $h  = 0;

    $dt = new DateTime(
        $fecha_ymd . ' ' . sprintf( '%02d:%02d:00', $h, $min ),
        $tz
    );
    return $dt->getTimestamp();
}

/**
 * Callback del cron: revisa pedidos de hoy y manda notificación si faltan ~30 min.
 * Toda la lógica de tiempo usa la zona horaria de WordPress para evitar
 * discrepancias con el servidor (HostGator corre en UTC).
 */
add_action( 'fc_onesignal_check_pedidos', 'fc_onesignal_run_check' );
function fc_onesignal_run_check() {
    $app_id  = get_option( 'fc_onesignal_app_id',  '' );
    $api_key = get_option( 'fc_onesignal_api_key', '' );
    if ( ! $app_id || ! $api_key ) return;

    // Usar siempre la zona horaria configurada en WordPress
    $tz      = wp_timezone();
    $now_dt  = new DateTime( 'now', $tz );
    $now     = $now_dt->getTimestamp();          // Unix timestamp real
    $hoy     = $now_dt->format( 'Y-m-d' );      // fecha local

    $min_diff = 20 * 60;  // 20 min  ┐ ventana amplia para absorber
    $max_diff = 40 * 60;  // 40 min  ┘ imprecisión del cron de WP

    $pedidos = get_posts( [
        'post_type'   => 'pedido',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_query'  => [
            'relation' => 'AND',
            [
                'key'     => '_fc_pedido_fecha',
                'value'   => $hoy,
                'compare' => '=',
            ],
            [
                'key'     => '_fc_onesignal_notif_enviada',
                'compare' => 'NOT EXISTS',
            ],
        ],
    ] );

    foreach ( $pedidos as $pedido ) {
        $tipo     = get_post_meta( $pedido->ID, '_fc_pedido_tipo',             true );
        $horario  = get_post_meta( $pedido->ID, '_fc_pedido_horario',          true );
        $hora_rec = get_post_meta( $pedido->ID, '_fc_pedido_hora_recoleccion', true );
        $numero   = get_post_meta( $pedido->ID, '_fc_pedido_numero',           true );
        $status   = get_post_meta( $pedido->ID, '_fc_pedido_status',           true );

        if ( in_array( $status, [ 'cancelado', 'entregado' ], true ) ) continue;

        $delivery_ts = false;

        if ( $tipo === 'recoleccion' && $hora_rec ) {
            // hora_recoleccion: "HH:MM"
            $dt = new DateTime( $hoy . ' ' . $hora_rec . ':00', $tz );
            $delivery_ts = $dt->getTimestamp();

        } elseif ( $horario ) {
            // horario: "10:00am – 12:00pm" → tomar la hora de inicio
            $partes = preg_split( '/\s*[–-]\s*/', $horario );
            if ( ! empty( $partes[0] ) ) {
                $delivery_ts = fc_parse_hora_a_timestamp( trim( $partes[0] ), $hoy, $tz );
            }
        }

        if ( ! $delivery_ts ) continue;

        $diff = $delivery_ts - $now;
        if ( $diff < $min_diff || $diff > $max_diff ) continue;

        $tipo_label = ( $tipo === 'recoleccion' ) ? 'Recolección' : 'Envío';
        $hora_str   = $now_dt->setTimestamp( $delivery_ts )->format( 'g:ia' );

        $title   = '⏰ Pedido en 30 min — ' . $hora_str;
        $message = ( $numero ? 'Pedido #' . $numero : 'Sin número' ) . ' · ' . $tipo_label;

        fc_onesignal_send( $title, $message );
        update_post_meta( $pedido->ID, '_fc_onesignal_notif_enviada', current_time( 'mysql' ) );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Botón de prueba en Configuración (submit del mismo formulario — sin AJAX)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Procesa el botón de prueba antes de renderizar la página de configuración.
 * Se engancha en admin_init para poder usar wp_redirect si fuera necesario.
 */
add_action( 'admin_init', 'fc_onesignal_handle_test' );
function fc_onesignal_handle_test() {
    // Salir inmediatamente si no es ninguno de nuestros botones
    if ( ! isset( $_POST['fc_onesignal_test'] ) && ! isset( $_POST['fc_onesignal_trigger'] ) ) return;
    if ( ! check_admin_referer( 'fc_settings' ) )  return;
    if ( ! current_user_can( 'manage_options' ) )  return;

    // Botón: notificación de prueba genérica
    if ( isset( $_POST['fc_onesignal_test'] ) ) {
        $result = fc_onesignal_send(
            '🌸 Notificación de prueba',
            'Las notificaciones de Florería Monarca están funcionando correctamente.'
        );
        set_transient( 'fc_onesignal_test_ok', $result, 30 );
    }

    // Botón: disparar la verificación del cron ahora mismo
    if ( isset( $_POST['fc_onesignal_trigger'] ) ) {
        fc_onesignal_run_check();
        set_transient( 'fc_onesignal_trigger_ok', 1, 30 );
    }
}
