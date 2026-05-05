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
        update_option( 'fc_whatsapp',          sanitize_text_field( $_POST['fc_whatsapp']         ?? '' ) );
        update_option( 'fc_catalog_page_url',  esc_url_raw(         $_POST['fc_catalog_page_url']  ?? '' ) );
        update_option( 'fc_politicas_url',     esc_url_raw(         $_POST['fc_politicas_url']     ?? '' ) );
        echo '<div class="notice notice-success is-dismissible"><p>¡Configuración guardada!</p></div>';
    }

    $whatsapp      = get_option( 'fc_whatsapp',         '' );
    $catalog_url   = get_option( 'fc_catalog_page_url', '' );
    $politicas_url = get_option( 'fc_politicas_url',    '' );
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

            </table>
            <?php submit_button( 'Guardar cambios', 'primary', 'fc_save_settings' ); ?>
        </form>
    </div>
    <?php
}
