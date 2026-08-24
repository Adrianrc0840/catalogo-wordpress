<?php
/**
 * Template: Punto de Venta (PDV)
 * URL: /pdv/
 */
if ( ! defined( 'ABSPATH' ) ) exit;

nocache_headers();

$is_logged_in = is_user_logged_in();
$is_admin     = $is_logged_in && current_user_can( 'administrator' );
$shop_name    = 'Florería Monarca';
$today        = current_time( 'Y-m-d' );

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( $shop_name ); ?> — Punto de Venta</title>
    <?php wp_head(); ?>
</head>
<body <?php body_class( 'fc-pdv-page' ); ?>>
<?php wp_body_open(); ?>
<div class="fc-pdv-body">

<?php if ( ! $is_admin ) : ?>
<!-- ── LOGIN ── -->
<div class="fc-pdv-login-wrap">
    <div class="fc-pdv-login-card">
        <div class="fc-pdv-login-logo">
            <h1><?php echo esc_html( $shop_name ); ?></h1>
            <p>Punto de Venta</p>
        </div>
        <h2>Iniciar sesión</h2>
        <form id="fc-pdv-login-form" autocomplete="on">
            <div class="fc-pdv-form-group">
                <label for="fc-pdv-user">Usuario o correo electrónico</label>
                <input type="text" id="fc-pdv-user" name="username" autocomplete="username"
                       placeholder="tu@correo.com" required />
            </div>
            <div class="fc-pdv-form-group">
                <label for="fc-pdv-pass">Contraseña</label>
                <input type="password" id="fc-pdv-pass" name="password" autocomplete="current-password"
                       placeholder="••••••••" required />
            </div>
            <button type="submit" class="fc-pdv-btn-primary">Entrar</button>
            <p class="fc-pdv-login-error" id="fc-pdv-login-error"></p>
        </form>
    </div>
</div>

<?php else : ?>
<!-- ── PDV MAIN ── -->
<div id="fc-pdv-main">

    <!-- ── HEADER ── -->
    <header class="fc-pdv-header">
        <div class="fc-pdv-header-brand">
            <span>🌸</span>
            <span><?php echo esc_html( $shop_name ); ?></span>
        </div>
        <?php
        /*
         * El icono y el texto van en <span> separados para que en móvil se
         * puedan apilar como pestaña. En escritorio el espacio entre ambos
         * los deja igual que antes.
         */
        ?>
        <nav class="fc-pdv-nav">
            <button class="fc-pdv-nav-btn active" data-view="pdv"><span class="fc-pdv-nav-ico">🛒</span> <span class="fc-pdv-nav-txt">PDV</span></button>
            <button class="fc-pdv-nav-btn" data-view="funeral"><span class="fc-pdv-nav-ico">🚨</span> <span class="fc-pdv-nav-txt">Modo funeral</span></button>
            <button class="fc-pdv-nav-btn" data-view="caja"><span class="fc-pdv-nav-ico">💰</span> <span class="fc-pdv-nav-txt">Caja</span></button>
            <button class="fc-pdv-nav-btn" data-view="transacciones"><span class="fc-pdv-nav-ico">📋</span> <span class="fc-pdv-nav-txt">Ventas</span></button>
            <button class="fc-pdv-nav-btn" data-view="informes"><span class="fc-pdv-nav-ico">📊</span> <span class="fc-pdv-nav-txt">Informes</span></button>
            <?php // Solo móvil: en la barra de abajo no caben las cinco secciones. ?>
            <button type="button" id="fc-pdv-btn-mas" class="fc-pdv-nav-mas" aria-expanded="false" aria-controls="fc-pdv-mas-menu"><span class="fc-pdv-nav-ico">☰</span> <span class="fc-pdv-nav-txt">Más</span></button>
        </nav>
        <button id="fc-pdv-btn-logout" class="fc-pdv-btn-header">Salir</button>
    </header>

    <?php
    /*
     * Menú "Más" — solo móvil. Sus botones llevan la misma clase y el mismo
     * data-view que los de la barra, así que el manejador de navegación que ya
     * existe los toma solos; aquí únicamente se cierra el menú al elegir.
     */
    ?>
    <div id="fc-pdv-mas-menu" class="fc-pdv-mas-menu" hidden>
        <button class="fc-pdv-nav-btn fc-pdv-mas-item" data-view="funeral"><span class="fc-pdv-nav-ico">🚨</span> <span class="fc-pdv-nav-txt">Modo funeral</span></button>
        <button class="fc-pdv-nav-btn fc-pdv-mas-item" data-view="informes"><span class="fc-pdv-nav-ico">📊</span> <span class="fc-pdv-nav-txt">Informes</span></button>
    </div>
    <div id="fc-pdv-mas-backdrop" class="fc-pdv-mas-backdrop" hidden></div>

    <!-- ── VIEW: PDV (catálogo + ticket) ── -->
    <div id="fc-pdv-view-pdv" class="fc-pdv-view active">

        <!-- Catálogo -->
        <div class="fc-pdv-catalog">
            <div class="fc-pdv-catalog-toolbar">
                <input type="search" id="fc-pdv-search" class="fc-pdv-search"
                       placeholder="Buscar arreglo…" autocomplete="off" />
                <button id="fc-pdv-btn-personalizado" class="fc-pdv-btn-personalizado">+ Personalizado</button>
            </div>
            <div id="fc-pdv-catalog-content" class="fc-pdv-catalog-content">
                <p style="color:#94a3b8;font-size:14px;text-align:center;padding:40px 0;">Cargando catálogo…</p>
            </div>
        </div>

        <!-- Ticket -->
        <?php
        /*
         * En escritorio es la columna de la derecha. En móvil el mismo bloque
         * se convierte en la hoja que sube desde abajo, así que el botón de
         * cerrar solo aparece ahí.
         */
        ?>
        <div class="fc-pdv-ticket">
            <div class="fc-pdv-ticket-header">
                <span>Ticket</span>
                <button type="button" id="fc-pdv-ticket-close" class="fc-pdv-ticket-close" aria-label="Cerrar ticket">&times;</button>
            </div>
            <div id="fc-pdv-ticket-items" class="fc-pdv-ticket-items">
                <div class="fc-pdv-ticket-empty">
                    <div class="fc-pdv-ticket-empty-icon">🛒</div>
                    <span>Ticket vacío</span>
                </div>
            </div>
            <div class="fc-pdv-ticket-footer">
                <div class="fc-pdv-ticket-total">
                    <span>Total</span>
                    <span id="fc-pdv-total-amount">$0.00</span>
                </div>
                <button id="fc-pdv-btn-cobrar" class="fc-pdv-btn-cobrar" disabled>Cobrar</button>
            </div>
        </div>

        <?php
        /*
         * Barra del ticket — solo móvil. Vive dentro de la vista del PDV a
         * propósito: así desaparece sola al cambiar a Caja, Ventas o Informes,
         * sin tener que esconderla desde el JS.
         */
        ?>
        <button type="button" id="fc-pdv-ticket-bar" class="fc-pdv-ticket-bar">
            <span class="fc-pdv-ticket-bar-count" id="fc-pdv-ticket-bar-count">Ticket vacío</span>
            <span class="fc-pdv-ticket-bar-total" id="fc-pdv-ticket-bar-total">$0.00</span>
        </button>

    </div><!-- /#fc-pdv-view-pdv -->

    <!-- ── VIEW: Caja ── -->
    <div id="fc-pdv-view-caja" class="fc-pdv-view">
        <div id="fc-pdv-caja-wrap" class="fc-pdv-caja-wrap">
            <p style="color:#94a3b8;font-size:14px;">Cargando caja…</p>
        </div>
    </div>

    <!-- ── VIEW: Transacciones ── -->
    <div id="fc-pdv-view-transacciones" class="fc-pdv-view">
        <div class="fc-pdv-informes-toolbar">
            <div class="fc-pdv-form-group">
                <label>Desde</label>
                <input type="date" id="fc-pdv-tx-desde" />
            </div>
            <div class="fc-pdv-form-group">
                <label>Hasta</label>
                <input type="date" id="fc-pdv-tx-hasta" />
            </div>
            <button id="fc-pdv-tx-buscar" class="fc-pdv-btn-sm outline">Buscar</button>
            <div id="fc-pdv-tx-resumen" class="fc-pdv-tx-resumen" style="display:none"></div>
        </div>
        <div id="fc-pdv-transacciones-result" class="fc-pdv-informes-result"></div>
    </div>

    <!-- ── VIEW: Informes ── -->
    <div id="fc-pdv-view-informes" class="fc-pdv-view">
        <div class="fc-pdv-informes-toolbar">
            <div class="fc-pdv-form-group" style="margin:0">
                <label>Desde</label>
                <input type="date" id="fc-pdv-inf-desde" />
            </div>
            <div class="fc-pdv-form-group" style="margin:0">
                <label>Hasta</label>
                <input type="date" id="fc-pdv-inf-hasta" />
            </div>
            <button id="fc-pdv-inf-buscar" class="fc-pdv-btn-sm outline">Buscar</button>
        </div>
        <div id="fc-pdv-informes-result" class="fc-pdv-informes-result"></div>
    </div>

</div><!-- /#fc-pdv-main -->
<?php endif; ?>

</div><!-- /.fc-pdv-body -->
<?php wp_footer(); ?>
</body>
</html>
