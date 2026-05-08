<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Find the pedido by order number or token ──
$ref = sanitize_text_field( get_query_var( 'fc_pedido_ref' ) );

$pedido = null;

if ( $ref ) {
    // Try by order number first
    $by_num = new WP_Query( [
        'post_type'      => 'pedido',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'meta_query'     => [ [
            'key'   => '_fc_pedido_numero',
            'value' => $ref,
        ] ],
    ] );

    if ( $by_num->have_posts() ) {
        $pedido = $by_num->posts[0];
    } else {
        // Try by token
        $by_token = new WP_Query( [
            'post_type'      => 'pedido',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => [ [
                'key'   => '_fc_pedido_token',
                'value' => $ref,
            ] ],
        ] );
        if ( $by_token->have_posts() ) {
            $pedido = $by_token->posts[0];
        }
    }
    wp_reset_postdata();
}

// ── Pedido meta ──
$pid = $pedido ? $pedido->ID : 0;

$numero           = $pid ? get_post_meta( $pid, '_fc_pedido_numero',           true ) : '';
$status           = $pid ? get_post_meta( $pid, '_fc_pedido_status',           true ) : '';
$tipo             = $pid ? get_post_meta( $pid, '_fc_pedido_tipo',             true ) : '';
$fecha            = $pid ? get_post_meta( $pid, '_fc_pedido_fecha',            true ) : '';
$horario          = $pid ? get_post_meta( $pid, '_fc_pedido_horario',          true ) : '';
$direccion        = $pid ? get_post_meta( $pid, '_fc_pedido_direccion',        true ) : '';
$hora_recoleccion = $pid ? get_post_meta( $pid, '_fc_pedido_hora_recoleccion', true ) : '';
$arreglo_nombre   = $pid ? get_post_meta( $pid, '_fc_pedido_arreglo_nombre',   true ) : '';
$tamano           = $pid ? get_post_meta( $pid, '_fc_pedido_tamano',           true ) : '';
$color_raw        = $pid ? get_post_meta( $pid, '_fc_pedido_color',            true ) : '';
$color            = ( $color_raw && strpos( $color_raw, '--' ) === false ) ? $color_raw : '';
$destinatario     = $pid ? get_post_meta( $pid, '_fc_pedido_destinatario',     true ) : '';
$mensaje_tarjeta  = $pid ? get_post_meta( $pid, '_fc_pedido_mensaje_tarjeta',  true ) : '';
$nota             = $pid ? get_post_meta( $pid, '_fc_pedido_nota',             true ) : '';
$nota_floreria    = $pid ? get_post_meta( $pid, '_fc_pedido_nota_floreria',    true ) : '';

$shop_name      = get_bloginfo( 'name' );
$catalog_url    = get_option( 'fc_catalog_page_url', home_url() );
$arreglo_thumb  = $pid ? fc_get_pedido_arreglo_thumb( $pid ) : '';

$status_colors = [
    'recibido'          => '#3b82f6',
    'en_preparacion'    => '#f59e0b',
    'en_camino'         => '#8b5cf6',
    'listo_recoleccion' => '#06b6d4',
    'entregado'         => '#10b981',
];
$line_color = $status_colors[ $status ] ?? '#c8185a';

// ── Status steps ──
$all_statuses = [
    'recibido',
    'en_preparacion',
    ( $tipo === 'recoleccion' ? 'listo_recoleccion' : 'en_camino' ),
    'entregado',
];

$status_labels_map = [
    'recibido'          => 'Recibido',
    'en_preparacion'    => 'En preparación',
    'en_camino'         => 'En camino',
    'listo_recoleccion' => 'Listo para recolección',
    'entregado'         => 'Entregado',
];

// Current step index (0-based)
$current_step = 0;
$status_order = [ 'recibido', 'en_preparacion', 'en_camino', 'listo_recoleccion', 'entregado' ];
foreach ( $all_statuses as $i => $s ) {
    if ( $s === $status ) {
        $current_step = $i;
    }
}

// Format date for display
$fecha_display = '';
if ( $fecha ) {
    $ts = strtotime( $fecha );
    if ( $ts ) {
        $meses = [
            1=>'enero', 2=>'febrero', 3=>'marzo', 4=>'abril', 5=>'mayo', 6=>'junio',
            7=>'julio', 8=>'agosto', 9=>'septiembre', 10=>'octubre', 11=>'noviembre', 12=>'diciembre',
        ];
        $fecha_display = date( 'j', $ts ) . ' de ' . $meses[ (int) date( 'n', $ts ) ] . ' de ' . date( 'Y', $ts );
    }
}

get_header();
?>
<style>
/* ── Single Pedido Styles ── */
:root {
    --fc-primary:  #c8185a;
    --fc-dark:     #8b1a47;
    --fc-light-bg: #fdf5f7;
}

.fc-pedido-wrap {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    max-width: 680px;
    margin: 0 auto;
    padding: 24px 20px 48px;
    color: #2d3748;
}

.fc-pedido-shop-header {
    text-align: center;
    margin-bottom: 32px;
}

.fc-pedido-shop-header h1 {
    font-size: 22px;
    font-weight: 700;
    color: var(--fc-primary);
    margin: 0 0 4px;
}

.fc-pedido-shop-header p {
    color: #718096;
    font-size: 14px;
    margin: 0;
}

.fc-pedido-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
    overflow: hidden;
    margin-bottom: 20px;
}

.fc-pedido-card-header {
    background: var(--fc-primary);
    padding: 20px 24px;
    color: #fff;
}

.fc-pedido-card-header h2 {
    margin: 0 0 4px;
    font-size: 14px;
    font-weight: 500;
    opacity: 0.85;
}

.fc-pedido-numero {
    font-size: 22px;
    font-weight: 700;
    letter-spacing: 0.04em;
}

/* ── Status colors (same as panel) ── */
:root {
    --fc-s-recibido:          #3b82f6;
    --fc-s-en_preparacion:    #f59e0b;
    --fc-s-en_camino:         #8b5cf6;
    --fc-s-listo_recoleccion: #06b6d4;
    --fc-s-entregado:         #10b981;
}

/* ── Progress bar ── */
.fc-progress-wrap {
    padding: 28px 24px 20px;
    background: #fafafa;
    border-bottom: 1px solid #f0f0f0;
}

.fc-progress-steps {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    position: relative;
}

.fc-progress-line {
    position: absolute;
    top: 16px;
    left: 16px;
    right: 16px;
    height: 3px;
    background: #e2e8f0;
    z-index: 0;
}

.fc-progress-line-fill {
    height: 100%;
    background: var(--fc-primary);
    transition: width 0.5s ease;
}

.fc-progress-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    flex: 1;
    position: relative;
    z-index: 1;
}

.fc-progress-dot {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #e2e8f0;
    border: 3px solid #fff;
    box-shadow: 0 0 0 2px #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: #a0aec0;
    transition: all 0.3s;
}

/* Colores por status para done y current */
.fc-progress-step.step-recibido.done .fc-progress-dot,
.fc-progress-step.step-recibido.current .fc-progress-dot {
    background: var(--fc-s-recibido);
    box-shadow: 0 0 0 2px var(--fc-s-recibido);
    color: #fff;
}
.fc-progress-step.step-en_preparacion.done .fc-progress-dot,
.fc-progress-step.step-en_preparacion.current .fc-progress-dot {
    background: var(--fc-s-en_preparacion);
    box-shadow: 0 0 0 2px var(--fc-s-en_preparacion);
    color: #fff;
}
.fc-progress-step.step-en_camino.done .fc-progress-dot,
.fc-progress-step.step-en_camino.current .fc-progress-dot {
    background: var(--fc-s-en_camino);
    box-shadow: 0 0 0 2px var(--fc-s-en_camino);
    color: #fff;
}
.fc-progress-step.step-listo_recoleccion.done .fc-progress-dot,
.fc-progress-step.step-listo_recoleccion.current .fc-progress-dot {
    background: var(--fc-s-listo_recoleccion);
    box-shadow: 0 0 0 2px var(--fc-s-listo_recoleccion);
    color: #fff;
}
.fc-progress-step.step-entregado.done .fc-progress-dot,
.fc-progress-step.step-entregado.current .fc-progress-dot {
    background: var(--fc-s-entregado);
    box-shadow: 0 0 0 2px var(--fc-s-entregado);
    color: #fff;
}

/* Anillo extra en el paso actual */
.fc-progress-step.step-recibido.current .fc-progress-dot          { box-shadow: 0 0 0 3px #fff, 0 0 0 5px var(--fc-s-recibido);          transform: scale(1.15); }
.fc-progress-step.step-en_preparacion.current .fc-progress-dot    { box-shadow: 0 0 0 3px #fff, 0 0 0 5px var(--fc-s-en_preparacion);    transform: scale(1.15); }
.fc-progress-step.step-en_camino.current .fc-progress-dot         { box-shadow: 0 0 0 3px #fff, 0 0 0 5px var(--fc-s-en_camino);         transform: scale(1.15); }
.fc-progress-step.step-listo_recoleccion.current .fc-progress-dot { box-shadow: 0 0 0 3px #fff, 0 0 0 5px var(--fc-s-listo_recoleccion); transform: scale(1.15); }
.fc-progress-step.step-entregado.current .fc-progress-dot         { box-shadow: 0 0 0 3px #fff, 0 0 0 5px var(--fc-s-entregado);         transform: scale(1.15); }

/* Labels coloreados según status */
.fc-progress-step.step-recibido.done .fc-progress-label,
.fc-progress-step.step-recibido.current .fc-progress-label          { color: var(--fc-s-recibido);          font-weight: 600; }
.fc-progress-step.step-en_preparacion.done .fc-progress-label,
.fc-progress-step.step-en_preparacion.current .fc-progress-label    { color: var(--fc-s-en_preparacion);    font-weight: 600; }
.fc-progress-step.step-en_camino.done .fc-progress-label,
.fc-progress-step.step-en_camino.current .fc-progress-label         { color: var(--fc-s-en_camino);         font-weight: 600; }
.fc-progress-step.step-listo_recoleccion.done .fc-progress-label,
.fc-progress-step.step-listo_recoleccion.current .fc-progress-label { color: var(--fc-s-listo_recoleccion); font-weight: 600; }
.fc-progress-step.step-entregado.done .fc-progress-label,
.fc-progress-step.step-entregado.current .fc-progress-label         { color: var(--fc-s-entregado);         font-weight: 600; }

.fc-progress-label {
    font-size: 10px;
    text-align: center;
    color: #a0aec0;
    line-height: 1.3;
    max-width: 70px;
}

/* ── Order details ── */
.fc-pedido-details {
    padding: 24px;
}

.fc-detail-section {
    margin-bottom: 20px;
}

.fc-detail-section h3 {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #a0aec0;
    margin: 0 0 10px;
}

.fc-detail-row {
    display: flex;
    gap: 12px;
    font-size: 14px;
    margin-bottom: 8px;
    align-items: flex-start;
}

.fc-detail-label {
    font-weight: 600;
    color: #718096;
    min-width: 100px;
    flex-shrink: 0;
}

.fc-detail-value {
    color: #2d3748;
    word-break: break-word;
}

.fc-divider {
    border: none;
    border-top: 1px solid #f0f0f0;
    margin: 16px 0;
}

/* ── Nota floreria ── */
.fc-nota-floreria-box {
    background: #fffbeb;
    border: 1.5px solid #f59e0b;
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 20px;
}

.fc-nota-floreria-box h3 {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #92400e;
    margin: 0 0 8px;
}

.fc-nota-floreria-box p {
    font-size: 14px;
    color: #78350f;
    margin: 0;
    line-height: 1.5;
}

/* ── Back link ── */
.fc-back-link {
    display: inline-block;
    color: var(--fc-primary);
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    margin-top: 8px;
}

.fc-back-link:hover {
    text-decoration: underline;
}

/* ── Arreglo photo ── */
.fc-arreglo-photo-wrap {
    text-align: center;
    margin-bottom: 20px;
}

.fc-arreglo-photo {
    max-width: 100%;
    width: 280px;
    height: 280px;
    object-fit: cover;
    border-radius: 14px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.12);
    cursor: zoom-in;
    transition: transform 0.2s, box-shadow 0.2s;
    display: block;
    margin: 0 auto;
}

.fc-arreglo-photo:hover {
    transform: scale(1.02);
    box-shadow: 0 8px 30px rgba(0,0,0,0.18);
}

/* ── Lightbox ── */
.fc-lb-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.9);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.22s;
    cursor: zoom-out;
}

.fc-lb-overlay.open {
    opacity: 1;
    pointer-events: auto;
}

.fc-lb-img {
    max-width: 92vw;
    max-height: 88vh;
    border-radius: 12px;
    object-fit: contain;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5);
    cursor: default;
}

.fc-lb-close {
    position: absolute;
    top: 16px;
    right: 20px;
    background: rgba(255,255,255,0.15);
    border: none;
    color: #fff;
    font-size: 26px;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}

.fc-lb-close:hover {
    background: rgba(255,255,255,0.28);
}

/* ── Not found ── */
.fc-not-found {
    text-align: center;
    padding: 60px 20px;
    color: #718096;
}

.fc-not-found h2 {
    color: #2d3748;
    margin-bottom: 8px;
}

/* ── Responsive ── */
@media (max-width: 480px) {
    .fc-progress-label {
        font-size: 9px;
        max-width: 56px;
    }
    .fc-progress-dot {
        width: 26px;
        height: 26px;
        font-size: 12px;
    }
    .fc-progress-line {
        top: 13px;
    }
    .fc-pedido-card-header {
        padding: 16px 18px;
    }
    .fc-pedido-details {
        padding: 18px;
    }
}
</style>

<div class="fc-pedido-wrap">

    <div class="fc-pedido-shop-header">
        <h1><?php echo esc_html( $shop_name ); ?></h1>
        <p>Estado de tu pedido</p>
    </div>

    <?php if ( ! $pedido ) : ?>

    <div class="fc-not-found">
        <h2>Pedido no encontrado</h2>
        <p>No pudimos encontrar un pedido con ese número o código. Por favor verifica el link o contacta a la florería.</p>
        <br>
        <a href="<?php echo esc_url( $catalog_url ); ?>" class="fc-back-link">&#8592; Volver al catálogo</a>
    </div>

    <?php else : ?>

    <!-- Nota florería (top priority if set) -->
    <?php if ( $nota_floreria ) : ?>
    <div class="fc-nota-floreria-box">
        <h3>&#128233; Mensaje de la florería</h3>
        <p><?php echo nl2br( esc_html( $nota_floreria ) ); ?></p>
    </div>
    <?php endif; ?>

    <div class="fc-pedido-card">
        <!-- Card header -->
        <div class="fc-pedido-card-header" style="background:<?php echo esc_attr( $line_color ); ?>;">
            <h2>Número de pedido</h2>
            <div class="fc-pedido-numero"><?php echo esc_html( $numero ); ?></div>
        </div>

        <!-- Progress bar -->
        <div class="fc-progress-wrap">
            <?php
            $total_steps = count( $all_statuses );
            $fill_pct    = $total_steps > 1 ? ( $current_step / ( $total_steps - 1 ) ) * 100 : 0;

            // Gradiente multicolor: un color por cada paso completado
            $gradient_stops = [];
            for ( $i = 0; $i <= $current_step; $i++ ) {
                $s    = $all_statuses[ $i ];
                $c    = $status_colors[ $s ] ?? '#c8185a';
                $pct  = $current_step > 0 ? round( ( $i / $current_step ) * 100 ) : 0;
                $gradient_stops[] = "$c {$pct}%";
            }
            $line_gradient = count( $gradient_stops ) > 1
                ? 'linear-gradient(to right, ' . implode( ', ', $gradient_stops ) . ')'
                : ( $gradient_stops[0] ?? '#e2e8f0' );
            ?>
            <div class="fc-progress-steps">
                <div class="fc-progress-line">
                    <div class="fc-progress-line-fill" style="width:<?php echo esc_attr( $fill_pct ); ?>%;background:<?php echo esc_attr( $line_gradient ); ?>;"></div>
                </div>
                <?php foreach ( $all_statuses as $step_idx => $step_status ) : ?>
                <?php
                $step_class = '';
                if ( $step_idx < $current_step )      $step_class = 'done';
                elseif ( $step_idx === $current_step ) $step_class = 'current';

                $step_label = $status_labels_map[ $step_status ] ?? $step_status;

                $icons = [
                    'recibido'          => '&#10003;',
                    'en_preparacion'    => '&#9878;',
                    'en_camino'         => '&#x1F4E6;',
                    'listo_recoleccion' => '&#x1F3EA;',
                    'entregado'         => '&#x2665;',
                ];
                $icon = $icons[ $step_status ] ?? ( $step_idx + 1 );
                ?>
                <div class="fc-progress-step <?php echo esc_attr( $step_class ); ?> step-<?php echo esc_attr( $step_status ); ?>">
                    <div class="fc-progress-dot">
                        <?php if ( $step_idx < $current_step ) : ?>
                        &#10003;
                        <?php else : ?>
                        <?php echo $icon; ?>
                        <?php endif; ?>
                    </div>
                    <span class="fc-progress-label"><?php echo esc_html( $step_label ); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Details -->
        <div class="fc-pedido-details">

            <!-- Foto del arreglo -->
            <?php if ( $arreglo_thumb ) : ?>
            <div class="fc-arreglo-photo-wrap">
                <img
                    src="<?php echo esc_url( $arreglo_thumb ); ?>"
                    alt="<?php echo esc_attr( $arreglo_nombre ); ?>"
                    class="fc-arreglo-photo"
                    id="fc-arreglo-photo"
                />
            </div>
            <?php endif; ?>

            <!-- Arreglo info -->
            <div class="fc-detail-section">
                <h3>Arreglo</h3>
                <div class="fc-detail-row">
                    <span class="fc-detail-label">Nombre</span>
                    <span class="fc-detail-value"><?php echo esc_html( $arreglo_nombre ); ?></span>
                </div>
                <?php if ( $tamano ) : ?>
                <div class="fc-detail-row">
                    <span class="fc-detail-label">Tamaño</span>
                    <span class="fc-detail-value"><?php echo esc_html( $tamano ); ?></span>
                </div>
                <?php endif; ?>
                <?php if ( $color ) : ?>
                <div class="fc-detail-row">
                    <span class="fc-detail-label">Color</span>
                    <span class="fc-detail-value"><?php echo esc_html( $color ); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <hr class="fc-divider" />

            <!-- Entrega info -->
            <div class="fc-detail-section">
                <h3>Entrega</h3>
                <div class="fc-detail-row">
                    <span class="fc-detail-label">Tipo</span>
                    <span class="fc-detail-value"><?php echo esc_html( $tipo === 'envio' ? 'Envío a domicilio' : 'Recolección en tienda' ); ?></span>
                </div>
                <?php if ( $fecha_display ) : ?>
                <div class="fc-detail-row">
                    <span class="fc-detail-label">Fecha</span>
                    <span class="fc-detail-value"><?php echo esc_html( $fecha_display ); ?></span>
                </div>
                <?php endif; ?>
                <?php if ( $tipo === 'envio' && $horario ) : ?>
                <div class="fc-detail-row">
                    <span class="fc-detail-label">Horario</span>
                    <span class="fc-detail-value"><?php echo esc_html( $horario ); ?></span>
                </div>
                <?php endif; ?>
                <?php if ( $tipo === 'recoleccion' && $hora_recoleccion ) : ?>
                <div class="fc-detail-row">
                    <span class="fc-detail-label">Hora</span>
                    <span class="fc-detail-value"><?php echo esc_html( $hora_recoleccion ); ?></span>
                </div>
                <?php endif; ?>
                <?php if ( $tipo === 'envio' && $direccion ) : ?>
                <div class="fc-detail-row">
                    <span class="fc-detail-label">Dirección</span>
                    <span class="fc-detail-value"><?php echo esc_html( $direccion ); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <?php if ( $destinatario || $mensaje_tarjeta || $nota ) : ?>
            <hr class="fc-divider" />

            <!-- Destinatario & mensaje -->
            <div class="fc-detail-section">
                <h3>Detalles del regalo</h3>
                <?php if ( $destinatario ) : ?>
                <div class="fc-detail-row">
                    <span class="fc-detail-label">Para</span>
                    <span class="fc-detail-value"><?php echo esc_html( $destinatario ); ?></span>
                </div>
                <?php endif; ?>
                <?php if ( $mensaje_tarjeta ) : ?>
                <div class="fc-detail-row">
                    <span class="fc-detail-label">Tarjeta</span>
                    <span class="fc-detail-value">"<?php echo esc_html( $mensaje_tarjeta ); ?>"</span>
                </div>
                <?php endif; ?>
                <?php if ( $nota ) : ?>
                <div class="fc-detail-row">
                    <span class="fc-detail-label">Nota especial</span>
                    <span class="fc-detail-value"><?php echo esc_html( $nota ); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <a href="<?php echo esc_url( $catalog_url ); ?>" class="fc-back-link">&#8592; Volver al catálogo</a>

    <?php endif; ?>

</div>

<?php if ( $arreglo_thumb ) : ?>
<!-- Lightbox -->
<div class="fc-lb-overlay" id="fc-lb-overlay" role="dialog" aria-modal="true" aria-label="Imagen del arreglo">
    <button class="fc-lb-close" id="fc-lb-close" aria-label="Cerrar">&times;</button>
    <img class="fc-lb-img" src="<?php echo esc_url( $arreglo_thumb ); ?>" alt="<?php echo esc_attr( $arreglo_nombre ); ?>" />
</div>
<script>
(function () {
    var overlay = document.getElementById('fc-lb-overlay');
    var photo   = document.getElementById('fc-arreglo-photo');
    var closeBtn = document.getElementById('fc-lb-close');

    function openLb() { overlay.classList.add('open'); }
    function closeLb() { overlay.classList.remove('open'); }

    if (photo)    photo.addEventListener('click', openLb);
    if (closeBtn) closeBtn.addEventListener('click', closeLb);
    if (overlay)  overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeLb();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeLb();
    });
})();
</script>
<?php endif; ?>

<?php get_footer(); ?>
