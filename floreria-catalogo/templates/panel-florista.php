<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$is_logged_in    = is_user_logged_in();
$has_cap         = $is_logged_in && ( current_user_can( 'fc_ver_pedidos' ) || current_user_can( 'manage_options' ) );
$current_user    = $is_logged_in ? wp_get_current_user() : null;
$shop_name     = 'Florería Monarca';
$status_labels = fc_pedido_status_labels();

get_header();
?>
<div class="fc-panel-body">

<?php if ( ! $has_cap ) : ?>
<!-- ── LOGIN ── -->
<div class="fc-login-wrap">
    <div class="fc-login-card">
        <div class="fc-login-logo">
            <h1><?php echo esc_html( $shop_name ); ?></h1>
            <p>Panel de gestión de pedidos</p>
        </div>
        <h2>Iniciar sesión</h2>
        <form id="fc-login-form" autocomplete="on">
            <div class="fc-form-group">
                <label for="fc-login-username">Usuario o correo electrónico</label>
                <input
                    type="text"
                    id="fc-login-username"
                    name="username"
                    autocomplete="username"
                    placeholder="tu@correo.com"
                    required
                />
            </div>
            <div class="fc-form-group">
                <label for="fc-login-password">Contraseña</label>
                <input
                    type="password"
                    id="fc-login-password"
                    name="password"
                    autocomplete="current-password"
                    placeholder="••••••••"
                    required
                />
            </div>
            <button type="submit" class="fc-btn-primary">Entrar</button>
            <p class="fc-login-error" id="fc-login-error"></p>
        </form>
    </div>
</div>

<?php else : ?>
<!-- ── PANEL ── -->

<!-- Header -->
<header class="fc-panel-header" id="fc-panel-header">
    <div class="fc-panel-header-left">
        <h1>Panel Floristas</h1>
    </div>
    <div class="fc-panel-header-right">
        <span class="fc-user-name">Hola, <?php echo esc_html( $current_user->display_name ); ?></span>
        <button class="fc-btn-outline" id="fc-logout-btn">Cerrar sesión</button>
        <button class="fc-btn-new-pedido" id="fc-btn-new-pedido">+ Nuevo pedido</button>
    </div>
</header>

<!-- Main content -->
<main class="fc-panel-content">

    <!-- Search bar -->
    <div class="fc-search-bar">
        <div class="fc-search-wrap">
            <span class="fc-search-icon">&#128269;</span>
            <input
                type="text"
                id="fc-search-input"
                placeholder="Buscar por número, nombre, teléfono, tarjeta..."
                autocomplete="off"
            />
            <button class="fc-search-clear" id="fc-search-clear" aria-label="Limpiar búsqueda" style="display:none;">&times;</button>
        </div>
    </div>

    <!-- Filter tabs -->
    <div class="fc-filter-tabs">
        <button class="fc-filter-tab active" data-status="all">Todos</button>
        <?php foreach ( $status_labels as $key => $label ) : ?>
        <button class="fc-filter-tab" data-status="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
        <?php endforeach; ?>
    </div>

    <!-- Date filter -->
    <div class="fc-date-filter">
        <label for="fc-fecha-filter">Fecha de entrega:</label>
        <input type="date" id="fc-fecha-filter" />
        <button class="fc-btn-outline" id="fc-clear-fecha">Todos los días</button>
    </div>

    <!-- Orders grid -->
    <div id="fc-orders-grid" class="fc-orders-grid">
        <div class="fc-loading">Cargando pedidos...</div>
    </div>

</main>

<!-- ── New Order Modal ── -->
<div class="fc-modal-overlay" id="fc-modal-overlay">
    <div class="fc-modal" role="dialog" aria-modal="true" aria-labelledby="fc-modal-title">
        <div class="fc-modal-header">
            <h2 id="fc-modal-title">Nuevo pedido</h2>
            <button class="fc-modal-close" id="fc-modal-close" aria-label="Cerrar">&times;</button>
        </div>

        <div class="fc-modal-body">
            <form id="fc-new-pedido-form">

                <!-- Canal de contacto (obligatorio) -->
                <div class="fc-form-group">
                    <label for="fc-modal-canal">Canal de contacto <span style="color:#b91c1c;">*</span></label>
                    <select id="fc-modal-canal" name="canal" required>
                        <option value="">-- ¿Por dónde contactó? --</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="instagram">Instagram</option>
                        <option value="facebook">Facebook</option>
                        <option value="otro">Otro</option>
                    </select>
                </div>
                <div class="fc-form-group" id="fc-canal-nombre-group" style="display:none;">
                    <label for="fc-modal-canal-nombre">Nombre del contacto</label>
                    <input type="text" id="fc-modal-canal-nombre" name="canal_nombre" placeholder="Nombre completo" />
                </div>
                <div class="fc-form-group" id="fc-canal-contacto-group" style="display:none;">
                    <label for="fc-modal-canal-contacto" id="fc-canal-contacto-label">Contacto</label>
                    <input type="text" id="fc-modal-canal-contacto" name="canal_contacto" placeholder="" />
                </div>

                <!-- Tipo -->
                <div class="fc-form-group">
                    <label>Tipo de entrega</label>
                    <div class="fc-tipo-toggle">
                        <button type="button" class="fc-tipo-option active" data-tipo="envio">Envío a domicilio</button>
                        <button type="button" class="fc-tipo-option" data-tipo="recoleccion">Recolección en tienda</button>
                    </div>
                </div>

                <!-- Fecha -->
                <div class="fc-form-group">
                    <label for="fc-modal-fecha">Fecha de entrega</label>
                    <input type="date" id="fc-modal-fecha" name="fecha" required />
                </div>

                <!-- Envío section -->
                <div id="fc-modal-envio-section">
                    <div class="fc-form-group">
                        <label for="fc-modal-horario">Horario de entrega</label>
                        <select id="fc-modal-horario" name="horario">
                            <option value="">-- Selecciona fecha primero --</option>
                        </select>
                    </div>
                    <div class="fc-form-group">
                        <label for="fc-modal-direccion">Dirección de entrega</label>
                        <input type="text" id="fc-modal-direccion" name="direccion" placeholder="Calle, número, colonia..." />
                    </div>
                </div>

                <!-- Recolección section -->
                <div id="fc-modal-recoleccion-section" style="display:none;">
                    <div class="fc-form-group">
                        <label for="fc-modal-hora-recoleccion">Hora de recolección</label>
                        <input type="time" id="fc-modal-hora-recoleccion" name="hora_recoleccion" />
                    </div>
                </div>

                <!-- Nota especial -->
                <div class="fc-form-group">
                    <label for="fc-modal-nota">Nota especial</label>
                    <textarea id="fc-modal-nota" name="nota" rows="2" placeholder="Indicaciones especiales del cliente..."></textarea>
                </div>

                <!-- ── Arreglos (multi-ítem) ── -->
                <div class="fc-items-section">
                    <div class="fc-items-section-title">Arreglos</div>
                    <div id="fc-items-container">
                        <!-- Blocks added dynamically by JS -->
                    </div>
                    <button type="button" class="fc-btn-add-item" id="fc-add-item-btn">
                        &#43; Agregar arreglo
                    </button>
                </div>

            </form>
        </div>

        <div class="fc-modal-footer">
            <!-- Success box -->
            <div class="fc-success-box" id="fc-pedido-success">
                <h3>¡Pedido registrado!</h3>
                <p>Número de pedido: <strong id="fc-pedido-num-result"></strong></p>
                <p>Comparte este link con el cliente para que pueda ver el estado:</p>
                <div class="fc-success-link" id="fc-pedido-link"></div>
                <button class="fc-btn-sm" id="fc-copy-link-btn">Copiar link</button>
            </div>

            <button type="submit" form="fc-new-pedido-form" class="fc-btn-primary" id="fc-submit-pedido">
                Registrar pedido
            </button>
        </div>
    </div>
</div>

<?php endif; ?>

</div><!-- .fc-panel-body -->

<?php get_footer(); ?>
