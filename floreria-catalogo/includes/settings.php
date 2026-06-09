<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'fc_add_settings_page' );
function fc_add_settings_page() {
    add_submenu_page(
        'edit.php?post_type=arreglo',
        'Configuración',
        'Configuración',
        'manage_options',
        'fc-settings',
        'fc_render_settings_page'
    );
}

function fc_render_settings_page() {
    if ( isset( $_POST['fc_save_settings'] ) && check_admin_referer( 'fc_settings' ) ) {
        update_option( 'fc_whatsapp',                sanitize_text_field( $_POST['fc_whatsapp']               ?? '' ) );
        update_option( 'fc_catalog_page_url',        esc_url_raw(         $_POST['fc_catalog_page_url']        ?? '' ) );
        update_option( 'fc_politicas_url',           esc_url_raw(         $_POST['fc_politicas_url']           ?? '' ) );
        update_option( 'fc_gmaps_key',               sanitize_text_field( $_POST['fc_gmaps_key']              ?? '' ) );
        update_option( 'fc_arreglo_wrapper_page_id',    (int) ( $_POST['fc_arreglo_wrapper_page_id']    ?? 0 ) );
        update_option( 'fc_rastreo_wrapper_page_id',    (int) ( $_POST['fc_rastreo_wrapper_page_id']    ?? 0 ) );
        update_option( 'fc_asistencia_wrapper_page_id', (int) ( $_POST['fc_asistencia_wrapper_page_id'] ?? 0 ) );
        update_option( 'fc_onesignal_app_id',        sanitize_text_field( $_POST['fc_onesignal_app_id']       ?? '' ) );
        update_option( 'fc_onesignal_api_key',       sanitize_text_field( $_POST['fc_onesignal_api_key']      ?? '' ) );
        $disabled_pages = isset( $_POST['fc_cart_disabled_pages'] ) ? array_map( 'intval', (array) $_POST['fc_cart_disabled_pages'] ) : [];
        update_option( 'fc_cart_disabled_pages', $disabled_pages );
        echo '<div class="notice notice-success is-dismissible"><p>¡Configuración guardada!</p></div>';
    }

    // Avisos de prueba
    if ( $test_result = get_transient( 'fc_onesignal_test_ok' ) ) {
        delete_transient( 'fc_onesignal_test_ok' );
        echo '<div class="notice notice-info is-dismissible"><p><strong>Respuesta de OneSignal:</strong><br><code>' . esc_html( $test_result ) . '</code></p></div>';
    }
    if ( get_transient( 'fc_onesignal_trigger_ok' ) ) {
        delete_transient( 'fc_onesignal_trigger_ok' );
        echo '<div class="notice notice-success is-dismissible"><p>✅ Verificación de pedidos ejecutada. Si había alguno a ~30 min, llegó la notificación.</p></div>';
    }

    $whatsapp        = get_option( 'fc_whatsapp',                '' );
    $catalog_url     = get_option( 'fc_catalog_page_url',        '' );
    $politicas_url   = get_option( 'fc_politicas_url',           '' );
    $gmaps_key       = get_option( 'fc_gmaps_key',               '' );
    $wrapper_page_id           = (int) get_option( 'fc_arreglo_wrapper_page_id',    0 );
    $rastreo_wrapper_page_id   = (int) get_option( 'fc_rastreo_wrapper_page_id',    0 );
    $asistencia_wrapper_page_id = (int) get_option( 'fc_asistencia_wrapper_page_id', 0 );
    $os_app_id       = get_option( 'fc_onesignal_app_id',        '' );
    $os_api_key      = get_option( 'fc_onesignal_api_key',       '' );
    ?>
    <div class="wrap">
        <h1>Configuración del Catálogo</h1>
        <form method="post">
            <?php wp_nonce_field( 'fc_settings' ); ?>
            <table class="form-table">

                <tr>
                    <th><label for="fc_whatsapp">Número de WhatsApp</label></th>
                    <td>
                        <input type="text" name="fc_whatsapp" id="fc_whatsapp" value="<?php echo esc_attr( $whatsapp ); ?>" class="regular-text" placeholder="521234567890" />
                        <p class="description">Código de país + número, sin + ni espacios. Ej: <strong>521234567890</strong></p>
                    </td>
                </tr>

                <tr>
                    <th><label for="fc_catalog_page_url">URL del catálogo</label></th>
                    <td>
                        <input type="url" name="fc_catalog_page_url" id="fc_catalog_page_url" value="<?php echo esc_attr( $catalog_url ); ?>" class="regular-text" placeholder="https://tufloreria.com/catalogo" />
                        <p class="description">URL de la página con el shortcode <code>[catalogo_floreria]</code>. Se usa para el botón "← Volver al catálogo".</p>
                    </td>
                </tr>

                <tr>
                    <th><label for="fc_politicas_url">URL de políticas</label></th>
                    <td>
                        <input type="url" name="fc_politicas_url" id="fc_politicas_url" value="<?php echo esc_attr( $politicas_url ); ?>" class="regular-text" placeholder="https://tufloreria.com/politicas" />
                        <p class="description">URL de la página con el shortcode <code>[floreria_politicas]</code>. Se usa en el link "Leí las políticas" al hacer un pedido.</p>
                    </td>
                </tr>

                <tr>
                    <th><label for="fc_gmaps_key">API Key — Google Maps</label></th>
                    <td>
                        <input type="text" name="fc_gmaps_key" id="fc_gmaps_key" value="<?php echo esc_attr( $gmaps_key ); ?>" class="regular-text" placeholder="AIzaSy..." />
                        <p class="description">Necesaria para el autocompletado de direcciones en el formulario de pedidos. Requiere <strong>Places API</strong> habilitada en Google Cloud Console.</p>
                    </td>
                </tr>

                <tr>
                    <th><label for="fc_arreglo_wrapper_page_id">Página de detalle de arreglo</label></th>
                    <td>
                        <?php $pages = get_pages( [ 'post_status' => 'publish', 'sort_column' => 'post_title' ] ); ?>
                        <select name="fc_arreglo_wrapper_page_id" id="fc_arreglo_wrapper_page_id">
                            <option value="0">— Sin página wrapper (usar template del plugin) —</option>
                            <?php foreach ( $pages as $p ) : ?>
                            <option value="<?php echo $p->ID; ?>" <?php selected( $wrapper_page_id, $p->ID ); ?>>
                                <?php echo esc_html( $p->post_title ); ?> (ID <?php echo $p->ID; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            Selecciona la página de Elementor que usarás como plantilla para el detalle de cada arreglo.<br>
                            Esa página debe contener el shortcode <code>[floreria_detalle_arreglo]</code> donde quieras mostrar el producto.<br>
                            Si dejas <em>Sin página wrapper</em>, se usará el template propio del plugin (sin tu nav/footer de Elementor).
                        </p>
                    </td>
                </tr>

                <tr>
                    <th><label for="fc_rastreo_wrapper_page_id">Página de rastreo de pedido</label></th>
                    <td>
                        <?php $pages2 = get_pages( [ 'post_status' => 'publish', 'sort_column' => 'post_title' ] ); ?>
                        <select name="fc_rastreo_wrapper_page_id" id="fc_rastreo_wrapper_page_id">
                            <option value="0">— Sin página wrapper (usar template del plugin) —</option>
                            <?php foreach ( $pages2 as $p ) : ?>
                            <option value="<?php echo $p->ID; ?>" <?php selected( $rastreo_wrapper_page_id, $p->ID ); ?>>
                                <?php echo esc_html( $p->post_title ); ?> (ID <?php echo $p->ID; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            Selecciona la página de Elementor que usarás como plantilla para el rastreo de pedidos del cliente.<br>
                            Esa página debe contener el shortcode <code>[floreria_rastreo_pedido]</code> donde quieras mostrar el contenido.<br>
                            Si dejas <em>Sin página wrapper</em>, se usará el template propio del plugin (sin tu nav/footer de Elementor).
                        </p>
                    </td>
                </tr>

                <tr>
                    <th><label for="fc_asistencia_wrapper_page_id">Página del kiosco de asistencia</label></th>
                    <td>
                        <?php $pages3 = get_pages( [ 'post_status' => 'publish', 'sort_column' => 'post_title' ] ); ?>
                        <select name="fc_asistencia_wrapper_page_id" id="fc_asistencia_wrapper_page_id">
                            <option value="0">— Sin página wrapper (usar template del plugin) —</option>
                            <?php foreach ( $pages3 as $p ) : ?>
                            <option value="<?php echo $p->ID; ?>" <?php selected( $asistencia_wrapper_page_id, $p->ID ); ?>>
                                <?php echo esc_html( $p->post_title ); ?> (ID <?php echo $p->ID; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            Selecciona la página de Elementor que usarás como plantilla para el kiosco de asistencia.<br>
                            Esa página debe contener el shortcode <code>[floreria_kiosco_asistencia]</code> donde quieras mostrar el kiosco.<br>
                            Si dejas <em>Sin página wrapper</em>, se usará el template propio del plugin (sin tu nav/footer de Elementor).
                        </p>
                    </td>
                </tr>

                <!-- ── Accesos rápidos ── -->
                <tr>
                    <th colspan="2"><h2 style="margin:0;padding:16px 0 4px;">Accesos rápidos</h2></th>
                </tr>
                <tr>
                    <th>Panel Floristas</th>
                    <td>
                        <a href="<?php echo esc_url( home_url( '/panel-florista/' ) ); ?>" target="_blank" class="button button-secondary">
                            Abrir Panel Floristas ↗
                        </a>
                        <p class="description">Panel front-end para gestión de pedidos.</p>
                    </td>
                </tr>
                <tr>
                    <th>Punto de Venta</th>
                    <td>
                        <a href="<?php echo esc_url( home_url( '/pdv/' ) ); ?>" target="_blank" class="button button-secondary">
                            Abrir PDV ↗
                        </a>
                        <p class="description">Pantalla de punto de venta en tienda.</p>
                    </td>
                </tr>
                <tr>
                    <th>Kiosco de Asistencia</th>
                    <td>
                        <a href="<?php echo esc_url( home_url( '/asistencia/' ) ); ?>" target="_blank" class="button button-secondary">
                            Abrir Kiosco ↗
                        </a>
                        <p class="description">Pantalla de registro de entrada y salida para empleadas.</p>
                    </td>
                </tr>

                <!-- ── OneSignal ── -->
                <tr>
                    <th colspan="2"><h2 style="margin:0;padding:16px 0 4px;">Notificaciones Push — OneSignal</h2></th>
                </tr>
                <tr>
                    <th><label for="fc_onesignal_app_id">App ID</label></th>
                    <td>
                        <input type="text" name="fc_onesignal_app_id" id="fc_onesignal_app_id" value="<?php echo esc_attr( $os_app_id ); ?>" class="regular-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                        <p class="description">Encuéntralo en OneSignal → Settings → Keys &amp; IDs.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="fc_onesignal_api_key">REST API Key</label></th>
                    <td>
                        <input type="password" name="fc_onesignal_api_key" id="fc_onesignal_api_key" value="<?php echo esc_attr( $os_api_key ); ?>" class="regular-text" placeholder="os_v2_app_..." />
                        <p class="description">Clave privada — nunca la compartas ni la subas a GitHub.</p>
                    </td>
                </tr>
                <?php if ( $os_app_id && $os_api_key ) : ?>
                <tr>
                    <th>Prueba</th>
                    <td>
                        <button type="submit" name="fc_onesignal_test" value="1" class="button button-secondary">Enviar notificación de prueba</button>
                        <p class="description">Manda una notificación genérica para verificar que la conexión con OneSignal funciona.</p>
                    </td>
                </tr>
                <tr>
                    <th>Verificar ahora</th>
                    <td>
                        <button type="submit" name="fc_onesignal_trigger" value="1" class="button button-secondary">Disparar verificación de pedidos</button>
                        <p class="description">Ejecuta la revisión de pedidos inmediatamente (igual que el cron automático). Útil para probar sin esperar 5 minutos.</p>
                    </td>
                </tr>
                <?php endif; ?>

                <!-- ── Carrito flotante ── -->
                <tr>
                    <th colspan="2"><h2 style="margin:0;padding:16px 0 4px;">Carrito flotante</h2></th>
                </tr>
                <tr>
                    <th>Ocultar carrito en</th>
                    <td>
                        <?php
                        $cart_disabled = get_option( 'fc_cart_disabled_pages', [] );
                        if ( ! is_array( $cart_disabled ) ) $cart_disabled = [];
                        $cart_disabled = array_map( 'intval', $cart_disabled );
                        $all_pages = get_pages( [ 'post_status' => 'publish', 'sort_column' => 'post_title' ] );
                        if ( $all_pages ) :
                            foreach ( $all_pages as $p ) : ?>
                            <label style="display:block;margin-bottom:6px;">
                                <input type="checkbox" name="fc_cart_disabled_pages[]" value="<?php echo $p->ID; ?>"
                                    <?php checked( in_array( $p->ID, $cart_disabled ) ); ?> />
                                <?php echo esc_html( $p->post_title ); ?>
                                <span style="color:#888;font-size:12px;">(ID <?php echo $p->ID; ?>)</span>
                            </label>
                        <?php endforeach;
                        else : ?>
                            <p style="color:#888;">No hay páginas publicadas.</p>
                        <?php endif; ?>
                        <p class="description">Marca las páginas donde <strong>NO</strong> quieres que aparezca el botón flotante del carrito.</p>
                    </td>
                </tr>

            </table>
            <?php submit_button( 'Guardar cambios', 'primary', 'fc_save_settings' ); ?>
        </form>
    </div>
    <?php
}
