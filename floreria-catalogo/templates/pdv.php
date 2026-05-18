<?php
/**
 * Template: Punto de Venta (PDV)
 * URL: /pdv/
 */
if ( ! defined( 'ABSPATH' ) ) exit;

nocache_headers();

$is_logged_in = is_user_logged_in();
$is_admin     = $is_logged_in && current_user_can( 'administrator' );
$shop_name    = get_option( 'blogname', 'Florería' );
$today        = current_time( 'Y-m-d' );

get_header();
?>
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
                <input
                    type="text"
                    id="fc-pdv-user"
                    name="username"
                    autocomplete="username"
                    placeholder="tu@correo.com"
                    required
                />
            </div>
            <div class="fc-pdv-form-group">
                <label for="fc-pdv-pass">Contraseña</label>
                <input
                    type="password"
                    id="fc-pdv-pass"
                    name="password"
                    autocomplete="current-password"
                    placeholder="••••••••"
                    required
                />
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
            <span class="fc-pdv-header-logo">🌸</span>
            <span class="fc-pdv-header-title"><?php echo esc_html( $shop_name ); ?> — PDV</span>
        </div>
        <div class="fc-pdv-header-actions">
            <button id="fc-pdv-btn-logout" class="fc-pdv-btn-logout">Cerrar sesión</button>
        </div>
    </header>

    <!-- ── MIDDLE: CATALOG (left) + TICKET (right) ── -->
    <div class="fc-pdv-middle">

        <!-- Catalog panel -->
        <div class="fc-pdv-catalog">
            <div class="fc-pdv-catalog-toolbar">
                <input
                    type="search"
                    id="fc-pdv-search"
                    class="fc-pdv-search"
                    placeholder="Buscar arreglo…"
                    autocomplete="off"
                />
                <button id="fc-pdv-btn-personalizado" class="fc-pdv-btn-personalizado">
                    + Personalizado
                </button>
            </div>
            <div id="fc-pdv-catalog-list" class="fc-pdv-catalog-list">
                <p style="color:#94a3b8;font-size:14px;text-align:center;padding:32px 0;">Cargando catálogo…</p>
            </div>
        </div>

        <!-- Ticket panel -->
        <div class="fc-pdv-ticket">
            <div class="fc-pdv-ticket-header">
                <span>Ticket</span>
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
                <button id="fc-pdv-btn-cobrar" class="fc-pdv-btn-cobrar" disabled>
                    Cobrar
                </button>
            </div>
        </div>

    </div><!-- /.fc-pdv-middle -->

    <!-- ── BOTTOM: CAJA + INFORMES tabs ── -->
    <div class="fc-pdv-bottom">
        <div class="fc-pdv-tabs">
            <button class="fc-pdv-tab active" data-tab="fc-pdv-tab-caja">💰 Caja</button>
            <button class="fc-pdv-tab" data-tab="fc-pdv-tab-informes">📊 Informes</button>
        </div>

        <!-- Tab: Caja -->
        <div id="fc-pdv-tab-caja" class="fc-pdv-tab-content active">
            <div id="fc-pdv-caja-wrap" class="fc-pdv-caja-wrap">
                <p style="color:#94a3b8;font-size:14px;">Cargando caja…</p>
            </div>
        </div>

        <!-- Tab: Informes -->
        <div id="fc-pdv-tab-informes" class="fc-pdv-tab-content">
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
            <div id="fc-pdv-informes-result" class="fc-pdv-informes-result">
                <!-- populated by JS -->
            </div>
        </div>

    </div><!-- /.fc-pdv-bottom -->

</div><!-- /#fc-pdv-main -->
<?php endif; ?>

</div><!-- /.fc-pdv-body -->
<?php get_footer(); ?>
