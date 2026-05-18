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
        <nav class="fc-pdv-nav">
            <button class="fc-pdv-nav-btn active" data-view="pdv">🛒 PDV</button>
            <button class="fc-pdv-nav-btn" data-view="caja">💰 Caja</button>
            <button class="fc-pdv-nav-btn" data-view="transacciones">📋 Ventas</button>
            <button class="fc-pdv-nav-btn" data-view="informes">📊 Informes</button>
        </nav>
        <button id="fc-pdv-btn-logout" class="fc-pdv-btn-header">Salir</button>
    </header>

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
        <div class="fc-pdv-ticket">
            <div class="fc-pdv-ticket-header"><span>Ticket</span></div>
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
            <div class="fc-pdv-form-group" style="margin:0">
                <label>Desde</label>
                <input type="date" id="fc-pdv-tx-desde" />
            </div>
            <div class="fc-pdv-form-group" style="margin:0">
                <label>Hasta</label>
                <input type="date" id="fc-pdv-tx-hasta" />
            </div>
            <button id="fc-pdv-tx-buscar" class="fc-pdv-btn-sm outline">Buscar</button>
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
