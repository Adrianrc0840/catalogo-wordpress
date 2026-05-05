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

// ── Contenido por defecto ──
function fc_politicas_default() {
    return '
<h2>Políticas</h2>
<ul>
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
</ul>

<h2>Horarios</h2>
<p>Nuestro horario de atención al público es el siguiente:</p>
<ul>
    <li>Lunes a viernes: de 10:00 a.m. a 8:00 p.m.</li>
    <li>Sábados: de 10:00 a.m. a 5:00 p.m.</li>
    <li>Domingos: cerrado.</li>
</ul>
<p>En los mismos horarios <strong>comienza la atención</strong> a través de nuestras redes sociales y canales digitales. Cualquier mensaje recibido <strong>fuera</strong> de este horario será respondido al <strong>inicio de la siguiente jornada laboral.</strong></p>

<h2>Entregas a domicilio</h2>
<p>Envíos a domicilio se realizan a <strong>partir de las 10:00 a.m.</strong>, organizados en <strong>bloques de dos horas</strong>, con el fin de optimizar las rutas de reparto y garantizar un mejor servicio:</p>
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
<p><strong>Recordatorio:</strong> Todas las entregas a domicilio se realizan <strong>únicamente</strong> en pedidos <strong>liquidados</strong> con al menos <strong>un día hábil de anticipación.</strong></p>
';
}

// ── Página admin ──
function fc_render_politicas_page() {
    if ( isset( $_POST['fc_save_politicas'] ) && check_admin_referer( 'fc_politicas' ) ) {
        update_option( 'fc_politicas_contenido', wp_kses_post( $_POST['fc_politicas_contenido'] ?? '' ) );
        echo '<div class="notice notice-success is-dismissible"><p>¡Políticas guardadas!</p></div>';
    }

    $contenido = get_option( 'fc_politicas_contenido', fc_politicas_default() );
    ?>
    <div class="wrap">
        <h1>Políticas</h1>
        <p style="color:#666;">Edita el contenido que se mostrará con el shortcode <code>[floreria_politicas]</code>.</p>
        <form method="post">
            <?php wp_nonce_field( 'fc_politicas' ); ?>
            <?php wp_editor( $contenido, 'fc_politicas_contenido', [
                'textarea_name' => 'fc_politicas_contenido',
                'media_buttons' => false,
                'textarea_rows' => 20,
                'teeny'         => false,
            ] ); ?>
            <?php submit_button( 'Guardar políticas', 'primary', 'fc_save_politicas' ); ?>
        </form>
    </div>
    <?php
}

// ── Shortcode ──
add_shortcode( 'floreria_politicas', 'fc_render_politicas_shortcode' );
function fc_render_politicas_shortcode() {
    $contenido = get_option( 'fc_politicas_contenido', fc_politicas_default() );
    return '<div class="fc-politicas">' . wp_kses_post( $contenido ) . '</div>';
}
