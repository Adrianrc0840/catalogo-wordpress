<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Submenú ──
add_action( 'admin_menu', 'fc_add_politicas_page' );
function fc_add_politicas_page() {
    add_submenu_page(
        'edit.php?post_type=arreglo',
        'Políticas',
        'Políticas',
        'manage_options',
        'fc-politicas',
        'fc_render_politicas_page'
    );
}

// ── Contenido por defecto de cada tab ──
function fc_politicas_tabs_default() {
    return [
        [
            'titulo' => 'Políticas',
            'contenido' => '<ul>
    <li>El <strong>envío del pedido genera un costo adicional</strong>, el cual varía según la zona de entrega.</li>
    <li>En caso de <strong>requerir factura</strong>, deberá solicitarse <strong>antes de realizar el pago.</strong></li>
    <li>Los <strong>pedidos se confirman</strong> únicamente con un <strong>anticipo mínimo del 50%</strong> del valor total.</li>
    <li>Para <strong>entregas a domicilio</strong>, el pedido deberá estar <strong>liquidado con al menos 1 día hábil de anticipación.</strong></li>
    <li>Es obligatorio proporcionar el <strong>nombre completo y número telefónico</strong> de la persona que recibe, para garantizar una entrega eficiente.</li>
    <li>El repartidor podrá esperar un <strong>máximo de 15 minutos</strong> en el punto de entrega.</li>
    <li>Si no es posible concretar la entrega, <strong>será necesario reagendar el pedido</strong>, lo cual puede generar ajustes en tiempos y costos.</li>
    <li><strong>No se manejan horarios exactos de entrega</strong>; se trabaja dentro de rangos previamente establecidos.</li>
    <li>Los materiales del arreglo (<strong>follaje, envoltura, base, tonos de orquídea y lirio</strong>) pueden <strong>variar según temporada y disponibilidad.</strong></li>
    <li>Si se <strong>requiere fotografía del arreglo</strong>, deberá solicitarse <strong>al momento de realizar el pedido.</strong> No se garantiza el envío de fotos si no fue solicitado previamente.</li>
</ul>',
        ],
        [
            'titulo' => 'Horarios',
            'contenido' => '<p>Nuestro horario de atención al público es el siguiente:</p>
<ul>
    <li>Lunes a viernes: de 10:00 a.m. a 8:00 p.m.</li>
    <li>Sábados: de 10:00 a.m. a 5:00 p.m.</li>
    <li>Domingos: cerrado.</li>
</ul>
<p>En los mismos horarios <strong>comienza la atención</strong> a través de nuestras redes sociales y canales digitales. Cualquier mensaje recibido <strong>fuera</strong> de este horario será respondido al <strong>inicio de la siguiente jornada laboral.</strong></p>',
        ],
        [
            'titulo' => 'Entregas a domicilio',
            'contenido' => '<p>Envíos a domicilio se realizan a <strong>partir de las 10:00 a.m.</strong>, organizados en <strong>bloques de dos horas</strong>, con el fin de optimizar las rutas de reparto y garantizar un mejor servicio:</p>
<ul>
    <li>10:00 a.m. – 12:00 p.m.</li>
    <li>11:00 a.m. – 1:00 p.m.</li>
    <li>12:00 p.m. – 2:00 p.m.</li>
    <li>1:00 p.m. – 3:00 p.m.</li>
    <li>2:00 p.m. – 4:00 p.m.</li>
    <li>3:00 p.m. – 5:00 p.m.</li>
    <li>4:00 p.m. – 6:00 p.m.</li>
    <li>5:00 p.m. – 7:00 p.m.</li>
</ul>
<p><strong>Nota:</strong> Los días sábado el último bloque disponible corresponde de 3:00 p.m. a 5:00 p.m.</p>
<p>Es <strong>importante</strong> considerar que los repartos se efectúan en conjunto con otros pedidos, por lo que los tiempos de entrega se ajustan a las rutas programadas. En consecuencia, <strong>no es posible garantizar un horario exacto de entrega</strong>, únicamente el rango de tiempo seleccionado.</p>
<p><strong>Recordatorio:</strong> Todas las entregas a domicilio se realizan <strong>únicamente</strong> en pedidos <strong>liquidados</strong> con al menos <strong>un día hábil de anticipación.</strong></p>',
        ],
    ];
}

function fc_get_politicas_tabs() {
    $guardados = get_option( 'fc_politicas_tabs', [] );
    $default   = fc_politicas_tabs_default();

    // Rellenar con defaults si faltan tabs
    $tabs = [];
    for ( $i = 0; $i < 3; $i++ ) {
        $tabs[] = [
            'titulo'    => $guardados[ $i ]['titulo']    ?? $default[ $i ]['titulo'],
            'contenido' => $guardados[ $i ]['contenido'] ?? $default[ $i ]['contenido'],
        ];
    }
    return $tabs;
}

// ── Página admin ──
function fc_render_politicas_page() {
    if ( isset( $_POST['fc_save_politicas'] ) && check_admin_referer( 'fc_politicas' ) ) {
        $tabs = [];
        foreach ( $_POST['fc_politicas_tabs'] as $tab ) {
            $tabs[] = [
                'titulo'    => sanitize_text_field( $tab['titulo'] ?? '' ),
                'contenido' => wp_kses_post( $tab['contenido'] ?? '' ),
            ];
        }
        update_option( 'fc_politicas_tabs', $tabs );
        echo '<div class="notice notice-success is-dismissible"><p>¡Políticas guardadas!</p></div>';
    }

    $tabs = fc_get_politicas_tabs();
    ?>
    <div class="wrap">
        <h1>Políticas</h1>
        <p style="color:#666;margin-bottom:24px;">Cada sección aparece como un tab con el shortcode <code>[floreria_politicas]</code>. Puedes editar el título y el contenido de cada uno.</p>
        <form method="post">
            <?php wp_nonce_field( 'fc_politicas' ); ?>
            <?php foreach ( $tabs as $i => $tab ) : ?>
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px 24px;margin-bottom:24px;">
                <h2 style="margin-top:0;font-size:15px;color:#1d2327;">Tab <?php echo $i + 1; ?></h2>
                <table class="form-table" style="margin-top:0;">
                    <tr>
                        <th style="width:80px;"><label>Título</label></th>
                        <td>
                            <input type="text"
                                   name="fc_politicas_tabs[<?php echo $i; ?>][titulo]"
                                   value="<?php echo esc_attr( $tab['titulo'] ); ?>"
                                   class="regular-text" />
                        </td>
                    </tr>
                </table>
                <?php wp_editor( $tab['contenido'], 'fc_politicas_tab_' . $i, [
                    'textarea_name' => 'fc_politicas_tabs[' . $i . '][contenido]',
                    'media_buttons' => false,
                    'textarea_rows' => 12,
                    'teeny'         => false,
                ] ); ?>
            </div>
            <?php endforeach; ?>
            <?php submit_button( 'Guardar políticas', 'primary', 'fc_save_politicas' ); ?>
        </form>
    </div>
    <?php
}

// ── Shortcode ──
add_shortcode( 'floreria_politicas', 'fc_render_politicas_shortcode' );
function fc_render_politicas_shortcode() {
    $tabs = fc_get_politicas_tabs();
    if ( empty( $tabs ) ) return '';

    ob_start();
    ?>
    <div class="fc-politicas-wrap">
        <div class="fc-pol-tabs" role="tablist">
            <?php foreach ( $tabs as $i => $tab ) : ?>
            <button class="fc-pol-tab <?php echo $i === 0 ? 'active' : ''; ?>"
                    data-tab="<?php echo $i; ?>"
                    role="tab"
                    aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>">
                <?php echo esc_html( $tab['titulo'] ); ?>
            </button>
            <?php endforeach; ?>
        </div>
        <div class="fc-pol-contenido">
            <?php foreach ( $tabs as $i => $tab ) : ?>
            <div class="fc-pol-panel <?php echo $i === 0 ? 'active' : ''; ?>"
                 data-panel="<?php echo $i; ?>"
                 role="tabpanel">
                <div class="fc-politicas">
                    <?php echo wp_kses_post( $tab['contenido'] ); ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
