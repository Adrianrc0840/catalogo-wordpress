<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────────────────────────────────────
// OneSignal Push Notifications — Florería Monarca
// La REST API Key se guarda en wp_options (nunca sale al cliente).
// El App ID se usa en el front-end para inicializar el SDK.
// ─────────────────────────────────────────────────────────────────────────────

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

    if ( ! $app_id || ! $api_key ) return;

    if ( ! $url ) {
        $url = home_url( '/panel-florista/' );
    }

    $body = [
        'app_id'            => $app_id,
        'included_segments' => [ 'All' ],
        'headings'          => [ 'en' => $title,   'es' => $title   ],
        'contents'          => [ 'en' => $message, 'es' => $message ],
        'url'               => $url,
        'web_url'           => $url,
    ];

    wp_remote_post( 'https://onesignal.com/api/v1/notifications', [
        'headers' => [
            'Authorization' => 'Key ' . $api_key,
            'Content-Type'  => 'application/json; charset=utf-8',
            'Accept'        => 'application/json',
        ],
        'body'    => wp_json_encode( $body ),
        'timeout' => 15,
    ] );
}

/**
 * Dispara la notificación cuando se guarda un pedido nuevo (no en actualizaciones).
 * Aplica tanto para pedidos creados desde el panel como desde el PDV.
 */
add_action( 'save_post_pedido', 'fc_onesignal_nuevo_pedido', 20, 3 );
function fc_onesignal_nuevo_pedido( $post_id, $post, $update ) {
    // Solo pedidos nuevos publicados (no borradores ni actualizaciones)
    if ( $update )                          return;
    if ( $post->post_status !== 'publish' ) return;
    if ( wp_is_post_revision( $post_id ) )  return;
    if ( wp_is_post_autosave( $post_id ) )  return;

    $numero = get_post_meta( $post_id, '_fc_pedido_numero',        true );
    $nombre = get_post_meta( $post_id, '_fc_pedido_cliente_nombre', true );
    $tipo   = get_post_meta( $post_id, '_fc_pedido_tipo',           true );
    $items  = get_post_meta( $post_id, '_fc_pedido_items',          true );

    // Construir descripción del primer arreglo si existe
    $detalle = '';
    if ( is_array( $items ) && ! empty( $items ) ) {
        $first = $items[0];
        $detalle = ! empty( $first['nombre'] ) ? $first['nombre'] : '';
    }
    if ( ! $detalle ) {
        // Fallback: campo legacy de arreglo
        $detalle = get_post_meta( $post_id, '_fc_pedido_arreglo_nombre', true );
    }

    $tipo_label = ( $tipo === 'recoleccion' ) ? 'Recolección' : 'Envío';

    $title   = '🌸 Nuevo pedido' . ( $numero ? ' #' . $numero : '' );
    $message = trim(
        ( $nombre  ? $nombre . ' — ' : '' ) .
        $tipo_label .
        ( $detalle ? ': ' . $detalle : '' )
    );

    fc_onesignal_send( $title, $message );
}

/**
 * AJAX: notificación de prueba desde la página de Configuración.
 * Solo administradores.
 */
add_action( 'wp_ajax_fc_onesignal_test', 'fc_onesignal_test_ajax' );
function fc_onesignal_test_ajax() {
    check_ajax_referer( 'fc_onesignal_test' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( '', 403 );

    fc_onesignal_send(
        '🌸 Notificación de prueba',
        'Las notificaciones de Florería Monarca están funcionando correctamente.'
    );

    wp_send_json_success( 'Notificación enviada.' );
}

/**
 * Encola el script del botón de prueba en la página de configuración del plugin.
 */
add_action( 'admin_enqueue_scripts', 'fc_onesignal_admin_scripts' );
function fc_onesignal_admin_scripts( $hook ) {
    if ( $hook !== 'arreglo_page_fc-settings' ) return;

    $nonce = wp_create_nonce( 'fc_onesignal_test' );
    ?>
    <script>
    (function(){
        document.addEventListener('DOMContentLoaded', function(){
            var btn = document.getElementById('fc-onesignal-test-btn');
            if ( ! btn ) return;
            btn.addEventListener('click', function(){
                btn.disabled = true;
                btn.textContent = 'Enviando…';
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=fc_onesignal_test&_ajax_nonce=<?php echo $nonce; ?>'
                })
                .then(r => r.json())
                .then(function(data){
                    btn.textContent = data.success ? '✅ ¡Enviada!' : '❌ Error';
                    setTimeout(function(){ btn.disabled = false; btn.textContent = 'Enviar notificación de prueba'; }, 3000);
                })
                .catch(function(){
                    btn.textContent = '❌ Error de red';
                    setTimeout(function(){ btn.disabled = false; btn.textContent = 'Enviar notificación de prueba'; }, 3000);
                });
            });
        });
    })();
    </script>
    <?php
}
