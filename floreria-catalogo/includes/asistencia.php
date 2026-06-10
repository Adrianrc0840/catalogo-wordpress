<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────
// DB: crear tablas en el primer init
// ─────────────────────────────────────────────
add_action( 'init', 'fc_asistencia_maybe_create_tables' );
function fc_asistencia_maybe_create_tables() {
    if ( get_option( 'fc_asistencia_db_version', '0' ) === '1.0' ) return;
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE {$wpdb->prefix}fc_empleados (
        id              bigint(20)     NOT NULL AUTO_INCREMENT,
        nombre          varchar(100)   NOT NULL,
        posicion        varchar(100)   NOT NULL DEFAULT '',
        foto_url        varchar(500)   NOT NULL DEFAULT '',
        numero          char(4)        NOT NULL,
        horas_requeridas decimal(4,2)  NOT NULL DEFAULT 7.50,
        activo          tinyint(1)     NOT NULL DEFAULT 1,
        PRIMARY KEY  (id),
        UNIQUE KEY numero (numero)
    ) $charset;" );

    dbDelta( "CREATE TABLE {$wpdb->prefix}fc_asistencia (
        id          bigint(20)  NOT NULL AUTO_INCREMENT,
        empleado_id bigint(20)  NOT NULL,
        tipo        varchar(10) NOT NULL,
        timestamp   datetime    NOT NULL,
        es_prueba   tinyint(1)  NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY empleado_id (empleado_id),
        KEY timestamp   (timestamp)
    ) $charset;" );

    update_option( 'fc_asistencia_db_version', '1.0' );
}

// ─────────────────────────────────────────────
// Helper: generar número de 4 dígitos único
// ─────────────────────────────────────────────
function fc_generar_numero_empleado() {
    global $wpdb;
    $tabla = $wpdb->prefix . 'fc_empleados';
    $intento = 0;
    do {
        $num = str_pad( mt_rand( 0, 9999 ), 4, '0', STR_PAD_LEFT );
        $existe = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tabla WHERE numero = %s", $num ) );
        $intento++;
    } while ( $existe && $intento < 200 );
    return $num;
}

// ─────────────────────────────────────────────
// Helpers para reportes
// ─────────────────────────────────────────────
function fc_fmt_minutos( $min ) {
    $min = (int) $min;
    $h   = intdiv( $min, 60 );
    $m   = $min % 60;
    return $h > 0
        ? $h . 'h' . ( $m > 0 ? ' ' . $m . 'min' : '' )
        : $m . 'min';
}

/**
 * Formatea un timestamp almacenado en hora local del sitio.
 * Los registros se guardan siempre en la zona horaria configurada en WordPress.
 */
function fc_fmt_hora( $ts ) {
    if ( ! $ts ) return '—';
    // Los timestamps están en hora local del sitio → no se convierte, solo se parsea.
    $dt = new DateTime( $ts, wp_timezone() );
    return $dt->format( 'g:i a' );
}

/**
 * Empareja entradas y salidas de un día y calcula el total trabajado.
 * Soporta múltiples pares (e.g. entrada–salida–entrada–salida para la hora de comida).
 *
 * @param array $registros  Array de ['id', 'tipo', 'timestamp', 'es_prueba'] ordenado por timestamp ASC.
 * @return array {
 *     total_min  int     Minutos totales de pares completos.
 *     pares      array   [['ent'=>ts, 'sal'=>ts, 'min'=>int], …]
 *     en_tienda  bool    Hay una entrada sin salida al final.
 *     ent_abierta string|null Timestamp de la entrada abierta si en_tienda=true.
 *     prueba     bool    Al menos un registro es de prueba.
 * }
 */
function fc_calcular_dia( array $registros ) {
    $total_min    = 0;
    $pares        = [];
    $prueba       = false;
    $ent_pendiente = null;

    foreach ( $registros as $r ) {
        if ( $r['es_prueba'] ) $prueba = true;
        if ( $r['tipo'] === 'entrada' ) {
            // Si ya había una entrada sin salida, la descartamos (registros inconsistentes)
            $ent_pendiente = $r['timestamp'];
        } elseif ( $r['tipo'] === 'salida' && $ent_pendiente !== null ) {
            $dur = (int) floor( ( strtotime( $r['timestamp'] ) - strtotime( $ent_pendiente ) ) / 60 );
            if ( $dur > 0 ) {
                $pares[]    = [ 'ent' => $ent_pendiente, 'sal' => $r['timestamp'], 'min' => $dur ];
                $total_min += $dur;
            }
            $ent_pendiente = null;
        }
    }

    return [
        'total_min'   => $total_min,
        'pares'       => $pares,
        'en_tienda'   => $ent_pendiente !== null,
        'ent_abierta' => $ent_pendiente,
        'prueba'      => $prueba,
    ];
}

// ─────────────────────────────────────────────
// AJAX: Login (solo admins)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_fc_asistencia_login', 'fc_ajax_asistencia_login' );
add_action( 'wp_ajax_fc_asistencia_login',        'fc_ajax_asistencia_login' );
function fc_ajax_asistencia_login() {
    $username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '';
    $password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';

    if ( ! $username || ! $password ) {
        wp_send_json_error( [ 'message' => 'Usuario y contraseña requeridos.' ] );
    }

    $user = wp_authenticate( $username, $password );

    if ( is_wp_error( $user ) ) {
        wp_send_json_error( [ 'message' => 'Credenciales incorrectas.' ] );
    }

    if ( ! user_can( $user, 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Solo los administradores pueden acceder.' ] );
    }

    $token = fc_generate_autologin_token( $user->ID );
    wp_send_json_success( [ 'token' => $token ] );
}

// ─────────────────────────────────────────────
// Autologin redirect para kiosco
// ─────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_fc_asistencia_autologin', 'fc_ajax_asistencia_autologin' );
add_action( 'wp_ajax_fc_asistencia_autologin',        'fc_ajax_asistencia_autologin' );
function fc_ajax_asistencia_autologin() {
    $raw_token  = isset( $_GET['token'] ) ? wp_unslash( $_GET['token'] ) : '';
    $kiosko_url = home_url( '/asistencia/' );
    $user_id    = fc_verify_autologin_token( $raw_token );
    if ( $user_id ) {
        $user = get_user_by( 'id', $user_id );
        if ( $user && user_can( $user, 'manage_options' ) ) {
            wp_set_current_user( $user->ID );
            wp_set_auth_cookie( $user->ID, true );
        }
    }
    wp_safe_redirect( $kiosko_url );
    exit;
}

// ─────────────────────────────────────────────
// AJAX: Logout kiosco
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_asistencia_logout', 'fc_ajax_asistencia_logout' );
function fc_ajax_asistencia_logout() {
    wp_logout();
    wp_send_json_success();
}

// ─────────────────────────────────────────────
// Rewrite: /asistencia/
// ─────────────────────────────────────────────
add_action( 'init', 'fc_asistencia_rewrite' );
function fc_asistencia_rewrite() {
    add_rewrite_rule( '^asistencia/?$', 'index.php?fc_asistencia=1', 'top' );
}

add_filter( 'query_vars', function( $vars ) {
    $vars[] = 'fc_asistencia';
    return $vars;
} );

add_filter( 'template_include', 'fc_asistencia_template_include' );
function fc_asistencia_template_include( $template ) {
    // Si hay wrapper activo, template_redirect ya cambió la query → no cargar el template propio.
    if ( ! empty( $GLOBALS['fc_is_asistencia_wrapper'] ) ) return $template;
    if ( get_query_var( 'fc_asistencia' ) ) {
        $t = FC_PATH . 'templates/asistencia.php';
        if ( file_exists( $t ) ) return $t;
    }
    return $template;
}

// ─────────────────────────────────────────────
// Shortcode: [floreria_kiosco_asistencia]
// ─────────────────────────────────────────────
add_shortcode( 'floreria_kiosco_asistencia', 'fc_shortcode_kiosco_asistencia' );
function fc_shortcode_kiosco_asistencia() {
    ob_start();
    fc_asistencia_render_content();
    return ob_get_clean();
}

// ─────────────────────────────────────────────
// Renderizar el contenido del kiosco (login o kiosco)
// Usado tanto por el template standalone como por el shortcode.
// ─────────────────────────────────────────────
function fc_asistencia_render_content() {
    $is_admin = is_user_logged_in() && current_user_can( 'manage_options' );
    ?>
    <div class="fc-asist-page">
    <?php if ( ! $is_admin ) : ?>
    <!-- ── LOGIN ── -->
    <div class="fc-asist-login-wrap">
        <div class="fc-asist-login-card">
            <div class="fc-asist-login-logo">
                <img src="<?php echo esc_url( FC_URL . 'assets/images/Logo-principal.PNG' ); ?>" alt="Florería Monarca">
                <h1>Florería Monarca</h1>
                <p>Sistema de Asistencia</p>
            </div>
            <h2>Iniciar sesión</h2>
            <form id="fc-asist-login-form" autocomplete="on">
                <div class="fc-asist-field">
                    <label for="fc-asist-user">Usuario o correo</label>
                    <input type="text" id="fc-asist-user" name="username"
                           autocomplete="username" placeholder="tu@correo.com" required>
                </div>
                <div class="fc-asist-field">
                    <label for="fc-asist-pass">Contraseña</label>
                    <input type="password" id="fc-asist-pass" name="password"
                           autocomplete="current-password" placeholder="••••••••" required>
                </div>
                <button type="submit" class="fc-asist-login-btn">Entrar</button>
                <p class="fc-asist-login-error" id="fc-asist-login-error"></p>
            </form>
        </div>
    </div>

    <?php else : ?>
    <!-- ── KIOSCO ── -->
    <div id="fc-asist-app">

        <!-- Logout discreto -->
        <button id="fc-asist-logout" title="Cerrar sesión">⏻</button>

        <!-- ── PASO 1: Teclado numérico ── -->
        <div class="fc-step" id="fc-step-pad">
            <div class="fc-kiosk-header">
                <div class="fc-kiosk-logo">
                    <img src="<?php echo esc_url( FC_URL . 'assets/images/Logo-principal.PNG' ); ?>" alt="Florería Monarca">
                </div>
                <h1>Florería Monarca</h1>
                <p>Sistema de Asistencia</p>
            </div>

            <div class="fc-pin-display" id="fc-pin-display">
                <span class="fc-pin-dot" id="fc-dot-0"></span>
                <span class="fc-pin-dot" id="fc-dot-1"></span>
                <span class="fc-pin-dot" id="fc-dot-2"></span>
                <span class="fc-pin-dot" id="fc-dot-3"></span>
            </div>
            <p class="fc-pin-label" id="fc-pin-label">Ingresa tu número de empleada</p>

            <div class="fc-numpad">
                <button class="fc-num-btn" data-n="1">1</button>
                <button class="fc-num-btn" data-n="2">2</button>
                <button class="fc-num-btn" data-n="3">3</button>
                <button class="fc-num-btn" data-n="4">4</button>
                <button class="fc-num-btn" data-n="5">5</button>
                <button class="fc-num-btn" data-n="6">6</button>
                <button class="fc-num-btn" data-n="7">7</button>
                <button class="fc-num-btn" data-n="8">8</button>
                <button class="fc-num-btn" data-n="9">9</button>
                <div></div>
                <button class="fc-num-btn" data-n="0">0</button>
                <button class="fc-num-btn fc-del-btn" id="fc-del-btn">⌫</button>
            </div>
        </div>

        <!-- ── PASO 2: Tarjeta de empleada ── -->
        <div class="fc-step" id="fc-step-emp" style="display:none;">
            <div class="fc-emp-card">
                <div class="fc-emp-foto-wrap">
                    <img id="fc-emp-foto" src="" alt="" style="display:none;">
                    <div id="fc-emp-avatar" class="fc-emp-avatar">👤</div>
                </div>
                <h2 id="fc-emp-nombre">—</h2>
                <p id="fc-emp-posicion" class="fc-emp-pos">—</p>
                <p class="fc-emp-question">¿Qué deseas registrar?</p>
                <div class="fc-action-btns">
                    <button class="fc-btn-entrada" id="fc-btn-entrada">
                        <span class="fc-btn-icon">🟢</span>Entrada
                    </button>
                    <button class="fc-btn-salida" id="fc-btn-salida">
                        <span class="fc-btn-icon">🔴</span>Salida
                    </button>
                </div>
                <button class="fc-btn-cancelar" id="fc-btn-cancelar">Cancelar</button>
            </div>
        </div>

        <!-- ── PASO 3: Confirmación ── -->
        <div class="fc-step" id="fc-step-ok" style="display:none;">
            <div class="fc-confirm-card">
                <div class="fc-confirm-check" id="fc-confirm-icon">✓</div>
                <h2 id="fc-confirm-nombre">—</h2>
                <p class="fc-confirm-tipo" id="fc-confirm-tipo">—</p>
                <p class="fc-confirm-hora" id="fc-confirm-hora">—</p>
                <div class="fc-confirm-timer">
                    <div class="fc-timer-bar" id="fc-timer-bar"></div>
                </div>
            </div>
        </div>

    </div>
    <?php endif; ?>
    </div><!-- .fc-asist-page -->
    <?php
}

// Sin caché en el kiosco
add_action( 'plugins_loaded', 'fc_asistencia_disable_cache', 1 );
function fc_asistencia_disable_cache() {
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    if ( strpos( $uri, '/asistencia' ) === false ) return;
    defined( 'DONOTCACHEPAGE' ) || define( 'DONOTCACHEPAGE', true );
    if ( ! headers_sent() ) {
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
    }
}

// ─────────────────────────────────────────────
// Enqueue assets del kiosco
// ─────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'fc_enqueue_asistencia' );
function fc_enqueue_asistencia() {
    // Cargar en ruta directa, wrapper Elementor, o cualquier página con el shortcode
    global $post;
    $tiene_shortcode = is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'floreria_kiosco_asistencia' );
    if ( ! get_query_var( 'fc_asistencia' ) && empty( $GLOBALS['fc_is_asistencia_wrapper'] ) && ! $tiene_shortcode ) return;
    wp_enqueue_style(  'fc-asistencia', FC_URL . 'assets/css/asistencia.css', [], FC_VERSION );
    wp_enqueue_script( 'fc-asistencia', FC_URL . 'assets/js/asistencia.js',  [], FC_VERSION, true );
    wp_localize_script( 'fc-asistencia', 'fcAsist', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'fc_asistencia_nonce' ),
    ] );
}

// ─────────────────────────────────────────────
// Admin: menú
// ─────────────────────────────────────────────
add_action( 'admin_menu', 'fc_asistencia_admin_menu' );
function fc_asistencia_admin_menu() {
    add_menu_page(
        'Asistencia',
        'Asistencia',
        'manage_options',
        'fc-asistencia',
        'fc_asistencia_admin_page',
        'dashicons-clock',
        30
    );

    // Enlace al kiosco en el submenú de Arreglos (junto a Panel Floristas y PDV)
    add_submenu_page(
        'edit.php?post_type=arreglo',
        'Kiosco Asistencia',
        'Kiosco Asistencia ↗',
        'manage_options',
        'fc-asistencia-link',
        '__return_false'
    );
}

// Redirigir al hacer clic en "Kiosco Asistencia ↗"
add_action( 'admin_init', 'fc_asistencia_redirect_link' );
function fc_asistencia_redirect_link() {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'fc-asistencia-link' ) {
        wp_safe_redirect( home_url( '/asistencia/' ) );
        exit;
    }
}

// ─────────────────────────────────────────────
// Admin: página
// ─────────────────────────────────────────────
function fc_asistencia_admin_page() {
    $nonce   = wp_create_nonce( 'fc_asistencia_nonce' );
    $ajaxurl = admin_url( 'admin-ajax.php' );
    $kiosko  = home_url( '/asistencia/' );
    ?>
    <div class="wrap fc-asist-wrap">
        <h1>🌸 Sistema de Asistencia</h1>

        <div class="fc-asist-tabs">
            <button class="fc-asist-tab active" data-tab="empleadas">👤 Empleadas</button>
            <button class="fc-asist-tab" data-tab="reportes">📊 Reportes</button>
        </div>

        <!-- ── TAB EMPLEADAS ── -->
        <div class="fc-asist-panel active" id="fc-tab-empleadas">
            <div style="margin-bottom:16px;display:flex;gap:8px;align-items:center;">
                <button class="button button-primary" id="fc-nueva-empleada">＋ Nueva empleada</button>
                <a href="<?php echo esc_url( $kiosko ); ?>" target="_blank" class="button">🔗 Ver kiosco</a>
            </div>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:60px">Foto</th>
                        <th>Nombre</th>
                        <th>Posición</th>
                        <th style="width:110px">Nº Empleada</th>
                        <th style="width:110px">Horas/día</th>
                        <th style="width:80px">Estado</th>
                        <th style="width:130px">Acciones</th>
                    </tr>
                </thead>
                <tbody id="fc-emp-tbody">
                    <tr><td colspan="7" style="text-align:center;padding:20px;color:#888;">Cargando…</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ── TAB REPORTES ── -->
        <div class="fc-asist-panel" id="fc-tab-reportes">

            <div class="fc-rep-filtros">
                <label>Empleada
                    <select id="fc-rep-emp"><option value="">Todas</option></select>
                </label>
                <label>Período
                    <select id="fc-rep-periodo">
                        <option value="dia">Día</option>
                        <option value="semana">Semana</option>
                        <option value="rango">Rango de fechas</option>
                    </select>
                </label>
                <span class="fc-rep-sep"></span>
                <span id="fc-wrap-dia">
                    <label>Fecha<input type="date" id="fc-rep-dia"></label>
                </span>
                <span id="fc-wrap-semana" style="display:none">
                    <label>Semana del<input type="date" id="fc-rep-semana"></label>
                </span>
                <span id="fc-wrap-rango" style="display:none;gap:20px;align-items:flex-end;">
                    <label>Del<input type="date" id="fc-rep-desde"></label>
                    <label>Al<input type="date" id="fc-rep-hasta"></label>
                </span>
                <span class="fc-rep-sep"></span>
                <div style="display:flex;gap:8px;align-items:flex-end;">
                    <button class="button button-primary" id="fc-rep-generar">Generar reporte</button>
                    <button class="button" id="fc-rep-print" style="display:none">🖨️ Imprimir</button>
                </div>
            </div>

            <!-- Modo prueba -->
            <details class="fc-prueba-box">
                <summary>🧪 Modo prueba — insertar registro manual</summary>
                <div class="fc-prueba-form">
                    <select id="fc-prueba-emp"><option value="">Selecciona empleada</option></select>
                    <select id="fc-prueba-tipo">
                        <option value="entrada">Entrada</option>
                        <option value="salida">Salida</option>
                    </select>
                    <input type="date" id="fc-prueba-fecha">
                    <input type="time" id="fc-prueba-hora">
                    <button class="button" id="fc-prueba-btn">Insertar registro</button>
                </div>
            </details>

            <div id="fc-rep-resultado"></div>
        </div>

        <!-- ── MODAL REGISTROS DÍA ── -->
        <div id="fc-modal-registros">
            <div class="fc-modal-overlay"></div>
            <div class="fc-modal-box">
                <h2 id="fc-modal-reg-titulo">Registros del día</h2>
                <p class="fc-reg-sub" id="fc-modal-reg-sub">Cargando…</p>
                <ul class="fc-reg-lista" id="fc-reg-lista"></ul>
                <div class="fc-reg-add-wrap">
                    <strong style="font-size:13px;">Agregar registro</strong>
                    <div class="fc-reg-add-row" style="margin-top:8px;">
                        <select id="fc-reg-add-tipo" class="fc-reg-tipo">
                            <option value="entrada">Entrada</option>
                            <option value="salida">Salida</option>
                        </select>
                        <input type="time" id="fc-reg-add-hora" class="fc-reg-hora" step="60">
                        <button class="button button-primary fc-reg-save" id="fc-reg-add-btn">Agregar</button>
                    </div>
                </div>
                <div style="text-align:right;margin-top:20px;padding-top:12px;border-top:1px solid #eee;">
                    <button class="button" id="fc-modal-reg-cerrar">Cerrar</button>
                </div>
            </div>
        </div>

        <!-- ── MODAL EMPLEADA ── -->
        <div id="fc-modal-emp" style="display:none;position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;">
            <div class="fc-modal-overlay"></div>
            <div class="fc-modal-box">
                <h2 id="fc-modal-titulo">Nueva empleada</h2>
                <input type="hidden" id="fc-emp-id" value="0">
                <div class="fc-field">
                    <label>Nombre *</label>
                    <input type="text" id="fc-emp-nombre" placeholder="Nombre completo">
                </div>
                <div class="fc-field">
                    <label>Posición</label>
                    <input type="text" id="fc-emp-posicion" placeholder="Florista, Cajera…">
                </div>
                <div class="fc-field">
                    <label>Foto (URL)</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text" id="fc-emp-foto" placeholder="https://…" style="flex:1">
                        <button type="button" class="button" id="fc-emp-foto-btn">Seleccionar</button>
                        <button type="button" class="button" id="fc-emp-foto-clear" title="Quitar foto" style="color:#b00;display:none;">✕</button>
                    </div>
                    <div id="fc-emp-foto-preview" style="margin-top:8px;display:none;">
                        <img id="fc-emp-foto-img" src="" style="width:64px;height:64px;border-radius:50%;object-fit:cover;">
                    </div>
                </div>
                <div class="fc-field">
                    <label>Horas requeridas por día</label>
                    <input type="number" id="fc-emp-horas" value="7.5" step="0.5" min="1" max="12">
                </div>
                <div class="fc-field" id="fc-num-field">
                    <label>Número de empleada</label>
                    <div id="fc-emp-num-display" class="fc-num-display">— — — —</div>
                    <small style="color:#888;">Se genera automáticamente al guardar</small>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:24px;">
                    <button class="button" id="fc-modal-cancelar">Cancelar</button>
                    <button class="button button-primary" id="fc-modal-guardar">Guardar</button>
                </div>
            </div>
        </div>
    </div>

    <style>
    .fc-asist-wrap { max-width:1200px; }
    .fc-asist-tabs { display:flex; gap:2px; border-bottom:2px solid #ddd; margin-bottom:20px; }
    .fc-asist-tab  { padding:8px 20px; border:none; background:none; cursor:pointer; font-size:14px;
                     border-bottom:3px solid transparent; margin-bottom:-2px; border-radius:4px 4px 0 0; }
    .fc-asist-tab:hover  { background:#f5f5f5; }
    .fc-asist-tab.active { border-bottom-color:#c8185a; color:#c8185a; font-weight:700; background:#fff; }
    .fc-asist-panel      { display:none; }
    .fc-asist-panel.active { display:block; }

    .fc-rep-filtros {
        background: #f9f9f9;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        padding: 18px 20px;
        margin-bottom: 16px;
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        align-items: flex-end;
    }
    .fc-rep-filtros label {
        display: flex;
        flex-direction: column;
        font-size: 12px;
        font-weight: 700;
        gap: 6px;
        color: #555;
        text-transform: uppercase;
        letter-spacing: .4px;
    }
    .fc-rep-filtros select {
        padding: 8px 12px;
        border: 1px solid #d0d0d0;
        border-radius: 6px;
        font-size: 13px;
        background: #fff;
        min-width: 130px;
    }
    .fc-rep-filtros input[type="date"] {
        padding: 7px 10px;
        border: 1px solid #d0d0d0;
        border-radius: 6px;
        font-size: 13px;
        background: #fff;
    }
    .fc-rep-sep {
        width: 1px;
        height: 40px;
        background: #ddd;
        align-self: flex-end;
        margin-bottom: 2px;
    }

    .fc-prueba-box { background:#fff8e1; border:1px solid #ffd54f; border-radius:8px;
                     padding:12px 16px; margin-bottom:20px; }
    .fc-prueba-box summary { cursor:pointer; font-weight:700; color:#e65100; user-select:none; }
    .fc-prueba-form { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; align-items:center; }
    .fc-prueba-form select,
    .fc-prueba-form input  { padding:6px 10px; border:1px solid #ccc; border-radius:5px; font-size:13px; }

    #fc-modal-emp  { display:none; }
    #fc-modal-emp.visible { display:flex; }
    .fc-modal-overlay { position:absolute; inset:0; background:rgba(0,0,0,.5); }
    .fc-modal-box { position:relative; background:#fff; border-radius:12px; padding:28px 32px;
                    width:480px; max-width:95vw; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.3); }
    .fc-modal-box h2 { margin:0 0 20px; font-size:18px; }
    .fc-field { margin-bottom:14px; }
    .fc-field label { display:block; font-weight:600; margin-bottom:5px; font-size:13px; }
    .fc-field input  { width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:6px; font-size:14px; box-sizing:border-box; }
    .fc-num-display { font-size:32px; font-weight:800; letter-spacing:10px; color:#c8185a; padding:8px 0 4px; }

    .fc-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
    .fc-badge-on  { background:#d1fae5; color:#065f46; }
    .fc-badge-off { background:#fee2e2; color:#991b1b; }
    .fc-badge-test{ background:#fff3cd; color:#856404; font-size:10px; padding:1px 5px; border-radius:4px; }

    /* Report table */
    .fc-rep-wrap { overflow-x:auto; margin-top:8px; }
    .fc-rep-table { width:100%; border-collapse:collapse; font-size:13px; min-width:600px; }
    .fc-rep-table th {
        background: #f3f4f6;
        padding: 10px 14px;
        text-align: left;
        border-bottom: 2px solid #e5e7eb;
        white-space: nowrap;
        font-size: 12px;
        color: #555;
    }
    .fc-rep-table th small { display:block; font-weight:400; color:#888; font-size:11px; margin-top:1px; }
    .fc-rep-table td { padding: 10px 14px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
    .fc-rep-table tbody tr:hover td { background:#fafafa; }
    .fc-extra  { color:#c8185a; font-weight:700; }
    .fc-falta  { color:#bbb; font-size:16px; }
    .fc-rep-titulo { font-size:16px; font-weight:700; margin-bottom:8px; color:#111; }
    .fc-rep-nota   { font-size:11px; color:#888; margin-top:8px; }

    /* Celda de detalle de día — grid 2 columnas, los pares se distribuyen de izq a der */
    .fc-dia-cell    {
        display: grid;
        grid-template-columns: 1fr 1fr;
        column-gap: 12px;
        row-gap: 6px;
        min-width: 170px;
        align-items: start;
    }
    .fc-dia-par     { display:flex; flex-direction:column; gap:2px; }
    .fc-dia-ent     { font-size:12px; color:#15803d; font-weight:600; white-space:nowrap; }
    .fc-dia-sal     { font-size:12px; color:#b91c1c; font-weight:600; white-space:nowrap; }
    .fc-dia-par-dur { font-size:10px; color:#888; }
    /* Total y badge abarcan las 2 columnas */
    .fc-dia-tot,
    .fc-dia-tienda,
    .fc-badge-test  { grid-column: 1 / -1; }
    .fc-dia-tot     { font-size:13px; font-weight:800; color:#111;
                      padding-top:5px; border-top:2px solid #e5e7eb; margin-top:2px; }
    .fc-dia-tienda  { font-size:11px; color:#b45309; font-weight:600; font-style:italic; }

    /* Botón editar día */
    .fc-edit-dia-btn {
        display: inline-block;
        margin-top: 6px;
        background: none;
        border: 1px solid #ddd;
        border-radius: 5px;
        padding: 2px 7px;
        font-size: 12px;
        cursor: pointer;
        color: #666;
        transition: all .15s;
    }
    .fc-edit-dia-btn:hover { border-color:#c8185a; color:#c8185a; background:#fff0f5; }

    /* Modal registros del día */
    #fc-modal-registros { display:none; position:fixed; inset:0; z-index:99999;
                          align-items:center; justify-content:center; }
    #fc-modal-registros .fc-modal-overlay { position:absolute; inset:0; background:rgba(0,0,0,.5); }
    #fc-modal-registros .fc-modal-box { position:relative; background:#fff; border-radius:12px;
        padding:28px 28px 20px; width:520px; max-width:96vw; max-height:90vh;
        overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.3); }
    #fc-modal-registros h2 { margin:0 0 4px; font-size:17px; }
    #fc-modal-registros .fc-reg-sub { font-size:13px; color:#888; margin-bottom:18px; }

    .fc-reg-lista { list-style:none; margin:0 0 14px; padding:0; }
    .fc-reg-row   { display:flex; align-items:center; gap:8px;
                    padding:8px 0; border-bottom:1px solid #f0f0f0; }
    .fc-reg-row:last-child { border-bottom:none; }
    .fc-reg-tipo  { width: 90px; padding:5px 8px; border:1px solid #ccc; border-radius:5px; font-size:13px; }
    .fc-reg-hora  { width: 110px; padding:5px 8px; border:1px solid #ccc; border-radius:5px; font-size:13px; }
    .fc-reg-tipo.entrada-color { border-color:#86efac; background:#f0fdf4; color:#15803d; font-weight:700; }
    .fc-reg-tipo.salida-color  { border-color:#fca5a5; background:#fff1f2; color:#b91c1c; font-weight:700; }
    .fc-reg-save  { padding:5px 10px; font-size:12px; }
    .fc-reg-del   { padding:5px 9px; font-size:12px; color:#b00; border-color:#fca5a5; }
    .fc-reg-prueba-tag { font-size:10px; background:#fff3cd; color:#856404;
                         border-radius:4px; padding:1px 5px; }
    .fc-reg-add-wrap { padding-top:10px; border-top:1px solid #e5e7eb; margin-top:4px; }
    .fc-reg-add-row  { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

    /* La impresión usa una página independiente (?fc_print_asistencia=1) */
    </style>

    <script>
    (function(){
        var ajax    = '<?php echo esc_js( $ajaxurl ); ?>';
        var nonce   = '<?php echo esc_js( $nonce ); ?>';
        var homeurl = '<?php echo esc_js( home_url( '/' ) ); ?>';

        // ── Tabs ──
        document.querySelectorAll('.fc-asist-tab').forEach(function(btn){
            btn.addEventListener('click', function(){
                document.querySelectorAll('.fc-asist-tab').forEach(function(b){ b.classList.remove('active'); });
                document.querySelectorAll('.fc-asist-panel').forEach(function(p){ p.classList.remove('active'); });
                btn.classList.add('active');
                document.getElementById('fc-tab-' + btn.dataset.tab).classList.add('active');
            });
        });

        // ── Cargar empleadas ──
        var empCache = [];
        function cargarEmpleadas() {
            fetch(ajax + '?action=fc_asistencia_lista_emps&nonce=' + nonce)
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res.success) return;
                empCache = res.data;
                var tbody    = document.getElementById('fc-emp-tbody');
                var repSel   = document.getElementById('fc-rep-emp');
                var pruebaSel= document.getElementById('fc-prueba-emp');

                repSel.innerHTML    = '<option value="">Todas</option>';
                pruebaSel.innerHTML = '<option value="">Selecciona empleada</option>';

                if (!res.data.length) {
                    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:#888;">Sin empleadas registradas</td></tr>';
                    return;
                }

                tbody.innerHTML = '';
                res.data.forEach(function(e){
                    var foto = e.foto_url
                        ? '<img src="'+e.foto_url+'" style="width:40px;height:40px;border-radius:50%;object-fit:cover;" onerror="this.replaceWith(document.createTextNode(\'👤\'))">'
                        : '<span style="font-size:24px">👤</span>';
                    var badge = e.activo == 1
                        ? '<span class="fc-badge fc-badge-on">Activa</span>'
                        : '<span class="fc-badge fc-badge-off">Inactiva</span>';
                    var tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td>'+foto+'</td>'+
                        '<td><strong>'+e.nombre+'</strong></td>'+
                        '<td>'+(e.posicion||'—')+'</td>'+
                        '<td><span style="font-size:20px;font-weight:800;letter-spacing:4px;color:#c8185a;">'+e.numero+'</span></td>'+
                        '<td>'+e.horas_requeridas+'h</td>'+
                        '<td>'+badge+'</td>'+
                        '<td>'+
                            '<button class="button button-small fc-btn-edit" data-id="'+e.id+'">Editar</button> '+
                            '<button class="button button-small fc-btn-del"  data-id="'+e.id+'" style="color:#b00">Borrar</button>'+
                        '</td>';
                    tbody.appendChild(tr);

                    [repSel, pruebaSel].forEach(function(sel){
                        var o = document.createElement('option');
                        o.value = e.id; o.textContent = e.nombre;
                        sel.appendChild(o);
                    });
                });

                // Editar
                tbody.querySelectorAll('.fc-btn-edit').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var e = empCache.find(function(x){ return x.id == btn.dataset.id; });
                        if (!e) return;
                        document.getElementById('fc-modal-titulo').textContent = 'Editar empleada';
                        document.getElementById('fc-emp-id').value      = e.id;
                        document.getElementById('fc-emp-nombre').value  = e.nombre;
                        document.getElementById('fc-emp-posicion').value= e.posicion||'';
                        document.getElementById('fc-emp-foto').value    = e.foto_url||'';
                        document.getElementById('fc-emp-horas').value   = e.horas_requeridas;
                        document.getElementById('fc-emp-num-display').textContent = e.numero;
                        document.getElementById('fc-num-field').style.display = '';
                        actualizarPreviewFoto();
                        document.getElementById('fc-modal-emp').style.display = 'flex';
                    });
                });

                // Borrar
                tbody.querySelectorAll('.fc-btn-del').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        if (!confirm('¿Borrar esta empleada? Se eliminarán también sus registros de asistencia.')) return;
                        var body = new URLSearchParams({ action:'fc_asistencia_borrar_emp', nonce:nonce, id:btn.dataset.id });
                        fetch(ajax, { method:'POST', body:body })
                        .then(function(r){ return r.json(); })
                        .then(function(res){ if (res.success) cargarEmpleadas(); else alert(res.data?.message||'Error.'); });
                    });
                });
            });
        }
        cargarEmpleadas();

        // ── Nueva empleada ──
        document.getElementById('fc-nueva-empleada').addEventListener('click', function(){
            document.getElementById('fc-modal-titulo').textContent = 'Nueva empleada';
            document.getElementById('fc-emp-id').value       = '0';
            document.getElementById('fc-emp-nombre').value   = '';
            document.getElementById('fc-emp-posicion').value = '';
            document.getElementById('fc-emp-foto').value     = '';
            document.getElementById('fc-emp-horas').value    = '7.5';
            document.getElementById('fc-emp-num-display').textContent = 'Se asigna al guardar';
            document.getElementById('fc-num-field').style.display = '';
            document.getElementById('fc-emp-foto-preview').style.display = 'none';
            document.getElementById('fc-modal-emp').style.display = 'flex';
        });

        function cerrarModal() { document.getElementById('fc-modal-emp').style.display = 'none'; }
        document.getElementById('fc-modal-cancelar').addEventListener('click', cerrarModal);
        document.querySelector('.fc-modal-overlay').addEventListener('click', cerrarModal);

        // Preview foto en tiempo real
        function actualizarPreviewFoto() {
            var url      = document.getElementById('fc-emp-foto').value.trim();
            var prev     = document.getElementById('fc-emp-foto-preview');
            var img      = document.getElementById('fc-emp-foto-img');
            var clearBtn = document.getElementById('fc-emp-foto-clear');
            if (url) {
                img.src              = url;
                prev.style.display   = '';
                clearBtn.style.display = '';
            } else {
                prev.style.display   = 'none';
                clearBtn.style.display = 'none';
            }
        }
        document.getElementById('fc-emp-foto').addEventListener('input', actualizarPreviewFoto);

        // Quitar foto
        document.getElementById('fc-emp-foto-clear').addEventListener('click', function(){
            document.getElementById('fc-emp-foto').value = '';
            actualizarPreviewFoto();
        });

        // Media uploader
        document.getElementById('fc-emp-foto-btn').addEventListener('click', function(){
            var frame = wp.media({ title:'Seleccionar foto', button:{ text:'Usar esta foto' }, multiple:false });
            frame.on('select', function(){
                var att = frame.state().get('selection').first().toJSON();
                document.getElementById('fc-emp-foto').value = att.url;
                actualizarPreviewFoto();
            });
            frame.open();
        });

        // Guardar
        document.getElementById('fc-modal-guardar').addEventListener('click', function(){
            var id     = document.getElementById('fc-emp-id').value;
            var nombre = document.getElementById('fc-emp-nombre').value.trim();
            if (!nombre) { alert('El nombre es requerido.'); return; }

            var action = id == '0' ? 'fc_asistencia_crear_emp' : 'fc_asistencia_editar_emp';
            var body   = new URLSearchParams({
                action:           action,
                nonce:            nonce,
                id:               id,
                nombre:           nombre,
                posicion:         document.getElementById('fc-emp-posicion').value.trim(),
                foto_url:         document.getElementById('fc-emp-foto').value.trim(),
                horas_requeridas: document.getElementById('fc-emp-horas').value,
            });
            fetch(ajax, { method:'POST', body:body })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (res.success) { cerrarModal(); cargarEmpleadas(); }
                else alert(res.data?.message || 'Error al guardar.');
            });
        });

        // ── Período toggle ──
        document.getElementById('fc-rep-periodo').addEventListener('change', function(){
            var v = this.value;
            document.getElementById('fc-wrap-dia').style.display    = v === 'dia'    ? '' : 'none';
            document.getElementById('fc-wrap-semana').style.display = v === 'semana' ? '' : 'none';
            document.getElementById('fc-wrap-rango').style.display  = v === 'rango'  ? 'flex' : 'none';
        });

        // Fecha por defecto = hoy
        var hoy = new Date().toISOString().slice(0, 10);
        ['fc-rep-dia','fc-rep-semana','fc-rep-desde','fc-rep-hasta'].forEach(function(id){
            document.getElementById(id).value = hoy;
        });

        // ── Generar reporte ──
        document.getElementById('fc-rep-generar').addEventListener('click', function(){
            var periodo = document.getElementById('fc-rep-periodo').value;
            var params  = new URLSearchParams({
                action:      'fc_asistencia_reporte',
                nonce:       nonce,
                empleada_id: document.getElementById('fc-rep-emp').value,
                periodo:     periodo,
            });
            if (periodo === 'dia')    params.set('fecha',         document.getElementById('fc-rep-dia').value);
            if (periodo === 'semana') params.set('semana_inicio', document.getElementById('fc-rep-semana').value);
            if (periodo === 'rango')  {
                params.set('desde', document.getElementById('fc-rep-desde').value);
                params.set('hasta', document.getElementById('fc-rep-hasta').value);
            }
            document.getElementById('fc-rep-resultado').innerHTML = '<p style="color:#888;padding:16px 0;">Generando reporte…</p>';
            fetch(ajax + '?' + params.toString())
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res.success) {
                    document.getElementById('fc-rep-resultado').innerHTML = '<p style="color:red;">'+(res.data?.message||'Error')+'</p>';
                    return;
                }
                document.getElementById('fc-rep-resultado').innerHTML = res.data.html;
                document.getElementById('fc-rep-print').style.display = '';
            });
        });

        document.getElementById('fc-rep-print').addEventListener('click', function(){
            var periodo = document.getElementById('fc-rep-periodo').value;
            var params  = new URLSearchParams({
                fc_print_asistencia: '1',
                empleada_id: document.getElementById('fc-rep-emp').value,
                periodo:     periodo,
            });
            if (periodo === 'dia')    params.set('fecha',         document.getElementById('fc-rep-dia').value);
            if (periodo === 'semana') params.set('semana_inicio', document.getElementById('fc-rep-semana').value);
            if (periodo === 'rango')  {
                params.set('desde', document.getElementById('fc-rep-desde').value);
                params.set('hasta', document.getElementById('fc-rep-hasta').value);
            }
            window.open(homeurl + '?' + params.toString(), '_blank');
        });

        // ── Modal de registros del día ──
        var modalReg      = document.getElementById('fc-modal-registros');
        var regLista      = document.getElementById('fc-reg-lista');
        var regTitulo     = document.getElementById('fc-modal-reg-titulo');
        var regSub        = document.getElementById('fc-modal-reg-sub');
        var regEmpActual  = 0;
        var regFechaActual = '';
        var regNombreActual = '';

        function abrirModalRegistros(empId, fecha, nombre) {
            regEmpActual   = empId;
            regFechaActual = fecha;
            regNombreActual = nombre;

            // Formatear fecha legible
            var partes = fecha.split('-');
            var meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
            var lbl = parseInt(partes[2],10) + ' ' + meses[parseInt(partes[1],10)-1] + ' ' + partes[0];
            regTitulo.textContent = '✏️ ' + nombre;
            regSub.textContent    = lbl;
            regLista.innerHTML    = '<li style="color:#888;padding:10px 0;">Cargando…</li>';
            modalReg.style.display = 'flex';

            cargarRegistrosDia();
        }

        function cargarRegistrosDia() {
            fetch(ajax + '?action=fc_asistencia_registros_dia&nonce=' + nonce +
                  '&empleado_id=' + regEmpActual + '&fecha=' + regFechaActual)
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res.success) { regLista.innerHTML = '<li style="color:red;">Error al cargar.</li>'; return; }
                renderRegistros(res.data);
            });
        }

        function renderRegistros(rows) {
            if (!rows.length) {
                regLista.innerHTML = '<li style="color:#888;padding:8px 0;font-style:italic;">Sin registros para este día.</li>';
                return;
            }
            regLista.innerHTML = '';
            rows.forEach(function(reg) {
                var li = document.createElement('li');
                li.className = 'fc-reg-row';
                li.dataset.id = reg.id;

                // Extraer hora (HH:MM) del timestamp
                var tsParts = reg.timestamp.split(' ');
                var horaParts = tsParts[1] ? tsParts[1].slice(0,5) : '00:00';

                var tipoClass = reg.tipo === 'entrada' ? 'entrada-color' : 'salida-color';
                var pruebaTag = reg.es_prueba == 1 ? '<span class="fc-reg-prueba-tag">prueba</span>' : '';

                li.innerHTML =
                    '<select class="fc-reg-tipo '+tipoClass+'">' +
                        '<option value="entrada"'+(reg.tipo==='entrada'?' selected':'')+'>↑ Entrada</option>' +
                        '<option value="salida"' +(reg.tipo==='salida' ?' selected':'')+'>↓ Salida</option>' +
                    '</select>' +
                    '<input type="time" class="fc-reg-hora" value="'+horaParts+'" step="60">' +
                    pruebaTag +
                    '<button class="button button-small fc-reg-save">Guardar</button>' +
                    '<button class="button button-small fc-reg-del">🗑</button>';

                // Color dinámico del tipo
                var tipoSel = li.querySelector('.fc-reg-tipo');
                tipoSel.addEventListener('change', function(){
                    tipoSel.className = 'fc-reg-tipo ' + (tipoSel.value === 'entrada' ? 'entrada-color' : 'salida-color');
                });

                // Guardar
                li.querySelector('.fc-reg-save').addEventListener('click', function(){
                    var nuevoTipo = tipoSel.value;
                    var nuevaHora = li.querySelector('.fc-reg-hora').value;
                    if (!nuevaHora) { alert('Ingresa una hora válida.'); return; }
                    var ts = regFechaActual + ' ' + nuevaHora + ':00';
                    var body = new URLSearchParams({ action:'fc_asistencia_editar_registro', nonce:nonce,
                                                    id:reg.id, tipo:nuevoTipo, timestamp:ts });
                    fetch(ajax, { method:'POST', body:body })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (res.success) { cargarRegistrosDia(); }
                        else alert(res.data?.message || 'Error al guardar.');
                    });
                });

                // Borrar
                li.querySelector('.fc-reg-del').addEventListener('click', function(){
                    if (!confirm('¿Eliminar este registro?')) return;
                    var body = new URLSearchParams({ action:'fc_asistencia_del_registro', nonce:nonce, id:reg.id });
                    fetch(ajax, { method:'POST', body:body })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (res.success) cargarRegistrosDia();
                        else alert(res.data?.message || 'Error al eliminar.');
                    });
                });

                regLista.appendChild(li);
            });
        }

        // Agregar registro
        document.getElementById('fc-reg-add-btn').addEventListener('click', function(){
            var tipo = document.getElementById('fc-reg-add-tipo').value;
            var hora = document.getElementById('fc-reg-add-hora').value;
            if (!hora) { alert('Ingresa la hora.'); return; }
            var ts = regFechaActual + ' ' + hora + ':00';
            var body = new URLSearchParams({ action:'fc_asistencia_add_registro', nonce:nonce,
                                            empleado_id:regEmpActual, tipo:tipo, timestamp:ts });
            fetch(ajax, { method:'POST', body:body })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (res.success) {
                    document.getElementById('fc-reg-add-hora').value = '';
                    cargarRegistrosDia();
                } else alert(res.data?.message || 'Error al agregar.');
            });
        });

        // Delegación de eventos para botones ✏️ del reporte (generados dinámicamente)
        document.getElementById('fc-rep-resultado').addEventListener('click', function(e) {
            var btn = e.target.closest('.fc-edit-dia-btn');
            if (!btn) return;
            abrirModalRegistros(btn.dataset.emp, btn.dataset.fecha, btn.dataset.nombre);
        });

        // Cerrar modal
        document.getElementById('fc-modal-reg-cerrar').addEventListener('click', function(){
            modalReg.style.display = 'none';
        });
        modalReg.querySelector('.fc-modal-overlay').addEventListener('click', function(){
            modalReg.style.display = 'none';
        });

        // ── Modo prueba ──
        document.getElementById('fc-prueba-btn').addEventListener('click', function(){
            var emp   = document.getElementById('fc-prueba-emp').value;
            var tipo  = document.getElementById('fc-prueba-tipo').value;
            var fecha = document.getElementById('fc-prueba-fecha').value;
            var hora  = document.getElementById('fc-prueba-hora').value;
            if (!emp || !fecha || !hora) { alert('Completa todos los campos.'); return; }
            var body = new URLSearchParams({ action:'fc_asistencia_prueba', nonce:nonce, empleada_id:emp, tipo:tipo, fecha:fecha, hora:hora });
            fetch(ajax, { method:'POST', body:body })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (res.success) alert('✅ Registro de prueba insertado.');
                else alert(res.data?.message || 'Error.');
            });
        });

    })();
    </script>
    <?php

    // Enqueue media para el uploader
    wp_enqueue_media();
}

// ─────────────────────────────────────────────
// AJAX: Listar empleadas (admin)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_asistencia_lista_emps', 'fc_ajax_lista_emps' );
function fc_ajax_lista_emps() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    global $wpdb;
    $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}fc_empleados ORDER BY nombre ASC" );
    wp_send_json_success( $rows );
}

// ─────────────────────────────────────────────
// AJAX: Crear empleada (admin)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_asistencia_crear_emp', 'fc_ajax_crear_emp' );
function fc_ajax_crear_emp() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    $nombre  = sanitize_text_field( $_POST['nombre']           ?? '' );
    $pos     = sanitize_text_field( $_POST['posicion']         ?? '' );
    $foto    = esc_url_raw(         $_POST['foto_url']         ?? '' );
    $horas   = floatval(            $_POST['horas_requeridas'] ?? 7.5 );
    if ( ! $nombre ) wp_send_json_error( [ 'message' => 'El nombre es requerido.' ] );
    $numero = fc_generar_numero_empleado();
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'fc_empleados', [
        'nombre'           => $nombre,
        'posicion'         => $pos,
        'foto_url'         => $foto,
        'numero'           => $numero,
        'horas_requeridas' => $horas,
        'activo'           => 1,
    ] );
    wp_send_json_success( [ 'id' => $wpdb->insert_id, 'numero' => $numero ] );
}

// ─────────────────────────────────────────────
// AJAX: Editar empleada (admin)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_asistencia_editar_emp', 'fc_ajax_editar_emp' );
function fc_ajax_editar_emp() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    $id    = intval(           $_POST['id']               ?? 0 );
    $nombre= sanitize_text_field( $_POST['nombre']        ?? '' );
    $pos   = sanitize_text_field( $_POST['posicion']      ?? '' );
    $foto  = esc_url_raw(         $_POST['foto_url']      ?? '' );
    $horas = floatval(            $_POST['horas_requeridas'] ?? 7.5 );
    if ( ! $id || ! $nombre ) wp_send_json_error( [ 'message' => 'Datos inválidos.' ] );
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'fc_empleados',
        [ 'nombre' => $nombre, 'posicion' => $pos, 'foto_url' => $foto, 'horas_requeridas' => $horas ],
        [ 'id' => $id ]
    );
    wp_send_json_success();
}

// ─────────────────────────────────────────────
// AJAX: Borrar empleada (admin)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_asistencia_borrar_emp', 'fc_ajax_borrar_emp' );
function fc_ajax_borrar_emp() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    $id = intval( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( [ 'message' => 'ID inválido.' ] );
    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'fc_empleados',  [ 'id'          => $id ] );
    $wpdb->delete( $wpdb->prefix . 'fc_asistencia', [ 'empleado_id' => $id ] );
    wp_send_json_success();
}

// ─────────────────────────────────────────────
// AJAX: Buscar empleada por número (kiosco – público)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_fc_asistencia_buscar', 'fc_ajax_asistencia_buscar' );
add_action( 'wp_ajax_fc_asistencia_buscar',        'fc_ajax_asistencia_buscar' );
function fc_ajax_asistencia_buscar() {
    $numero = sanitize_text_field( $_POST['numero'] ?? '' );
    if ( strlen( $numero ) !== 4 ) wp_send_json_error( [ 'message' => 'Número inválido.' ] );
    global $wpdb;
    $emp = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, nombre, posicion, foto_url FROM {$wpdb->prefix}fc_empleados WHERE numero = %s AND activo = 1",
        $numero
    ) );
    if ( ! $emp ) wp_send_json_error( [ 'message' => 'Número no encontrado.' ] );
    wp_send_json_success( $emp );
}

// ─────────────────────────────────────────────
// AJAX: Fichar entrada/salida (kiosco – público)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_nopriv_fc_asistencia_fichar', 'fc_ajax_asistencia_fichar' );
add_action( 'wp_ajax_fc_asistencia_fichar',        'fc_ajax_asistencia_fichar' );
function fc_ajax_asistencia_fichar() {
    $empleado_id = intval( $_POST['empleado_id'] ?? 0 );
    $tipo        = sanitize_text_field( $_POST['tipo'] ?? '' );
    if ( ! $empleado_id || ! in_array( $tipo, [ 'entrada', 'salida' ], true ) ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos.' ] );
    }
    $now = new DateTime( 'now', wp_timezone() );
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'fc_asistencia', [
        'empleado_id' => $empleado_id,
        'tipo'        => $tipo,
        'timestamp'   => $now->format( 'Y-m-d H:i:s' ),
        'es_prueba'   => 0,
    ] );
    wp_send_json_success( [ 'hora' => $now->format( 'g:i a' ) ] );
}

// ─────────────────────────────────────────────
// AJAX: Insertar registro de prueba (admin)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_asistencia_prueba', 'fc_ajax_asistencia_prueba' );
function fc_ajax_asistencia_prueba() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    $emp_id = intval(           $_POST['empleada_id'] ?? 0 );
    $tipo   = sanitize_text_field( $_POST['tipo']     ?? '' );
    $fecha  = sanitize_text_field( $_POST['fecha']    ?? '' );
    $hora   = sanitize_text_field( $_POST['hora']     ?? '' );
    if ( ! $emp_id || ! in_array( $tipo, [ 'entrada', 'salida' ], true ) || ! $fecha || ! $hora ) {
        wp_send_json_error( [ 'message' => 'Completa todos los campos.' ] );
    }
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'fc_asistencia', [
        'empleado_id' => $emp_id,
        'tipo'        => $tipo,
        'timestamp'   => $fecha . ' ' . $hora . ':00',
        'es_prueba'   => 1,
    ] );
    wp_send_json_success();
}

// ─────────────────────────────────────────────
// AJAX: Reporte (admin)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_asistencia_reporte', 'fc_ajax_asistencia_reporte' );
function fc_ajax_asistencia_reporte() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );

    $empleada_id = intval( $_GET['empleada_id'] ?? 0 );
    $periodo     = sanitize_text_field( $_GET['periodo'] ?? 'dia' );
    $tz          = wp_timezone();

    switch ( $periodo ) {
        case 'semana':
            $inicio = new DateTime( sanitize_text_field( $_GET['semana_inicio'] ?? 'now' ), $tz );
            $dow    = (int) $inicio->format( 'N' ); // 1=Lun … 7=Dom
            $inicio->modify( '-' . ( $dow - 1 ) . ' days' );
            $fin = ( clone $inicio )->modify( '+6 days' );
            break;
        case 'rango':
            $inicio = new DateTime( sanitize_text_field( $_GET['desde'] ?? 'now' ), $tz );
            $fin    = new DateTime( sanitize_text_field( $_GET['hasta'] ?? 'now' ), $tz );
            break;
        default:
            $inicio = new DateTime( sanitize_text_field( $_GET['fecha'] ?? 'now' ), $tz );
            $fin    = clone $inicio;
            break;
    }

    $desde = $inicio->format( 'Y-m-d' ) . ' 00:00:00';
    $hasta = $fin->format( 'Y-m-d' )    . ' 23:59:59';

    global $wpdb;

    $empleadas = $empleada_id
        ? $wpdb->get_results( $wpdb->prepare( "SELECT id, nombre, horas_requeridas FROM {$wpdb->prefix}fc_empleados WHERE id = %d", $empleada_id ) )
        : $wpdb->get_results( "SELECT id, nombre, horas_requeridas FROM {$wpdb->prefix}fc_empleados WHERE activo = 1 ORDER BY nombre ASC" );

    if ( empty( $empleadas ) ) wp_send_json_error( [ 'message' => 'No hay empleadas registradas.' ] );

    $emp_ids      = array_column( (array) $empleadas, 'id' );
    $placeholders = implode( ',', array_fill( 0, count( $emp_ids ), '%d' ) );
    $query        = $wpdb->prepare(
        "SELECT id, empleado_id, tipo, timestamp, es_prueba
         FROM {$wpdb->prefix}fc_asistencia
         WHERE empleado_id IN ($placeholders) AND timestamp BETWEEN %s AND %s
         ORDER BY empleado_id, timestamp ASC",
        array_merge( $emp_ids, [ $desde, $hasta ] )
    );
    $registros = $wpdb->get_results( $query );

    // Agrupar todos los registros por empleada y fecha (soporte de múltiples pares)
    $datos = [];
    foreach ( $registros as $reg ) {
        $f = substr( $reg->timestamp, 0, 10 );
        $datos[ $reg->empleado_id ][ $f ][] = [
            'id'        => $reg->id,
            'tipo'      => $reg->tipo,
            'timestamp' => $reg->timestamp,
            'es_prueba' => (bool) $reg->es_prueba,
        ];
    }

    // Rango de fechas
    $fechas = [];
    $cur    = clone $inicio;
    while ( $cur <= $fin ) {
        $fechas[] = $cur->format( 'Y-m-d' );
        $cur->modify( '+1 day' );
    }

    $dias_es  = [ 'Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb' ];
    $meses_es = [ 'ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic' ];

    // Título del período
    $t = (int) $inicio->format( 'j' ) . ' ' . $meses_es[ (int) $inicio->format( 'n' ) - 1 ];
    if ( $inicio->format( 'Y-m-d' ) !== $fin->format( 'Y-m-d' ) ) {
        $t .= ' – ' . (int) $fin->format( 'j' ) . ' ' . $meses_es[ (int) $fin->format( 'n' ) - 1 ];
    }
    $t .= ' ' . $inicio->format( 'Y' );

    ob_start();
    $es_un_dia = count( $fechas ) === 1;
    ?>
    <p class="fc-rep-titulo">📊 Reporte de asistencia — <?php echo esc_html( $t ); ?></p>
    <div class="fc-rep-wrap">
    <table class="fc-rep-table">
        <thead>
            <tr>
                <th>Empleada</th>
                <?php if ( $es_un_dia ) : ?>
                    <th>Registros del día</th>
                    <th>Total horas</th>
                    <th>Horas extra</th>
                <?php else : ?>
                    <?php foreach ( $fechas as $f ) :
                        $dt  = new DateTime( $f );
                        $lbl = $dias_es[ (int) $dt->format( 'w' ) ] . ' ' . (int) $dt->format( 'j' );
                    ?><th><?php echo esc_html( $lbl ); ?></th><?php endforeach; ?>
                    <th>Total<br>período</th>
                    <th>Horas<br>extra</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $empleadas as $emp ) :
            $min_req   = (int) round( (float) $emp->horas_requeridas * 60 );
            $emp_datos = $datos[ $emp->id ] ?? [];
            $total_min = 0;
            $total_ext = 0;
        ?>
        <tr>
            <td>
                <strong><?php echo esc_html( $emp->nombre ); ?></strong><br>
                <small style="color:#888;font-weight:400;"><?php echo esc_html( fc_fmt_minutos( $min_req ) ); ?>/día</small>
            </td>

            <?php if ( $es_un_dia ) :
                $regs_dia = $emp_datos[ $fechas[0] ] ?? [];
                $calc     = $regs_dia ? fc_calcular_dia( $regs_dia ) : [ 'total_min'=>0,'pares'=>[],'en_tienda'=>false,'ent_abierta'=>null,'prueba'=>false ];
                $extra    = max( 0, $calc['total_min'] - $min_req );
            ?>
                <td>
                    <?php if ( $regs_dia ) : ?>
                        <div class="fc-dia-cell">
                            <?php foreach ( $calc['pares'] as $par ) : ?>
                                <div class="fc-dia-par">
                                    <span class="fc-dia-ent">↑ <?php echo esc_html( fc_fmt_hora( $par['ent'] ) ); ?></span>
                                    <span class="fc-dia-sal">↓ <?php echo esc_html( fc_fmt_hora( $par['sal'] ) ); ?></span>
                                    <span class="fc-dia-par-dur"><?php echo esc_html( fc_fmt_minutos( $par['min'] ) ); ?></span>
                                </div>
                            <?php endforeach; ?>
                            <?php if ( $calc['en_tienda'] ) : ?>
                                <div class="fc-dia-par">
                                    <span class="fc-dia-ent">↑ <?php echo esc_html( fc_fmt_hora( $calc['ent_abierta'] ) ); ?></span>
                                    <span class="fc-dia-tienda">En tienda…</span>
                                </div>
                            <?php endif; ?>
                            <?php if ( $calc['prueba'] ) echo '<span class="fc-badge-test" style="display:block;margin-top:4px;">prueba</span>'; ?>
                        </div>
                    <?php else : ?>
                        <span class="fc-falta">Sin registros</span>
                    <?php endif; ?>
                    <button class="fc-edit-dia-btn" data-emp="<?php echo $emp->id; ?>"
                            data-fecha="<?php echo esc_attr( $fechas[0] ); ?>"
                            data-nombre="<?php echo esc_attr( $emp->nombre ); ?>"
                            title="Editar registros">✏️</button>
                </td>
                <td><?php echo $calc['total_min'] > 0 ? '<strong>'.esc_html(fc_fmt_minutos($calc['total_min'])).'</strong>' : '<span class="fc-falta">—</span>'; ?></td>
                <td><?php echo $extra > 0 ? '<span class="fc-extra">+'.esc_html(fc_fmt_minutos($extra)).'</span>' : '<span style="color:#bbb;">—</span>'; ?></td>

            <?php else :
                foreach ( $fechas as $f ) :
                    $regs_dia = $emp_datos[ $f ] ?? [];
                    $calc     = $regs_dia ? fc_calcular_dia( $regs_dia ) : [ 'total_min'=>0,'pares'=>[],'en_tienda'=>false,'ent_abierta'=>null,'prueba'=>false ];
                    if ( $calc['total_min'] ) {
                        $total_min += $calc['total_min'];
                        $total_ext += max( 0, $calc['total_min'] - $min_req );
                    }
                ?>
                <td>
                    <?php if ( $regs_dia ) : ?>
                        <div class="fc-dia-cell">
                            <?php foreach ( $calc['pares'] as $par ) : ?>
                                <div class="fc-dia-par">
                                    <span class="fc-dia-ent">↑ <?php echo esc_html( fc_fmt_hora( $par['ent'] ) ); ?></span>
                                    <span class="fc-dia-sal">↓ <?php echo esc_html( fc_fmt_hora( $par['sal'] ) ); ?></span>
                                </div>
                            <?php endforeach; ?>
                            <?php if ( $calc['en_tienda'] ) : ?>
                                <div class="fc-dia-par">
                                    <span class="fc-dia-ent">↑ <?php echo esc_html( fc_fmt_hora( $calc['ent_abierta'] ) ); ?></span>
                                    <span class="fc-dia-tienda">En tienda…</span>
                                </div>
                            <?php endif; ?>
                            <?php if ( $calc['total_min'] > 0 ) echo '<span class="fc-dia-tot">'.esc_html(fc_fmt_minutos($calc['total_min'])).'</span>'; ?>
                            <?php if ( $calc['prueba'] ) echo ' <span class="fc-badge-test">P</span>'; ?>
                        </div>
                    <?php else : ?>
                        <span class="fc-falta">—</span>
                    <?php endif; ?>
                    <button class="fc-edit-dia-btn" data-emp="<?php echo $emp->id; ?>"
                            data-fecha="<?php echo esc_attr( $f ); ?>"
                            data-nombre="<?php echo esc_attr( $emp->nombre ); ?>"
                            title="Editar registros">✏️</button>
                </td>
                <?php endforeach; ?>
                <td><?php echo $total_min > 0 ? '<strong style="font-size:15px;">'.esc_html(fc_fmt_minutos($total_min)).'</strong>' : '<span class="fc-falta">—</span>'; ?></td>
                <td><?php echo $total_ext > 0 ? '<span class="fc-extra">+'.esc_html(fc_fmt_minutos($total_ext)).'</span>' : '<span style="color:#bbb;">—</span>'; ?></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="fc-rep-nota">↑ Entrada &nbsp;·&nbsp; ↓ Salida &nbsp;·&nbsp; Jornada base por empleada indicada bajo su nombre &nbsp;·&nbsp; P = registro de prueba &nbsp;·&nbsp; ✏️ editar registros del día</p>
    <?php
    wp_send_json_success( [ 'html' => ob_get_clean() ] );
}

// ─────────────────────────────────────────────
// AJAX: Obtener registros de un día (para modal de edición)
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_asistencia_registros_dia', 'fc_ajax_registros_dia' );
function fc_ajax_registros_dia() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    $emp_id = intval( $_GET['empleado_id'] ?? 0 );
    $fecha  = sanitize_text_field( $_GET['fecha'] ?? '' );
    if ( ! $emp_id || ! $fecha ) wp_send_json_error( [ 'message' => 'Datos inválidos.' ] );
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, tipo, timestamp, es_prueba FROM {$wpdb->prefix}fc_asistencia
         WHERE empleado_id = %d AND timestamp BETWEEN %s AND %s
         ORDER BY timestamp ASC",
        $emp_id, $fecha . ' 00:00:00', $fecha . ' 23:59:59'
    ) );
    wp_send_json_success( $rows );
}

// ─────────────────────────────────────────────
// AJAX: Editar un registro individual
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_asistencia_editar_registro', 'fc_ajax_editar_registro' );
function fc_ajax_editar_registro() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    $id   = intval( $_POST['id']   ?? 0 );
    $tipo = sanitize_text_field( $_POST['tipo'] ?? '' );
    $ts   = sanitize_text_field( $_POST['timestamp'] ?? '' );
    if ( ! $id || ! in_array( $tipo, [ 'entrada', 'salida' ], true ) || ! $ts ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos.' ] );
    }
    // Validar formato de timestamp
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $ts ) ) {
        wp_send_json_error( [ 'message' => 'Formato de fecha inválido.' ] );
    }
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'fc_asistencia',
        [ 'tipo' => $tipo, 'timestamp' => $ts ],
        [ 'id'   => $id ]
    );
    wp_send_json_success();
}

// ─────────────────────────────────────────────
// AJAX: Eliminar un registro individual
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_asistencia_del_registro', 'fc_ajax_del_registro' );
function fc_ajax_del_registro() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    $id = intval( $_POST['id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( [ 'message' => 'ID inválido.' ] );
    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'fc_asistencia', [ 'id' => $id ] );
    wp_send_json_success();
}

// ─────────────────────────────────────────────
// AJAX: Agregar un nuevo registro manual
// ─────────────────────────────────────────────
add_action( 'wp_ajax_fc_asistencia_add_registro', 'fc_ajax_add_registro' );
function fc_ajax_add_registro() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Sin permiso.' ], 403 );
    $emp_id = intval(              $_POST['empleado_id'] ?? 0 );
    $tipo   = sanitize_text_field( $_POST['tipo']        ?? '' );
    $ts     = sanitize_text_field( $_POST['timestamp']   ?? '' );
    if ( ! $emp_id || ! in_array( $tipo, [ 'entrada', 'salida' ], true ) || ! $ts ) {
        wp_send_json_error( [ 'message' => 'Datos inválidos.' ] );
    }
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'fc_asistencia', [
        'empleado_id' => $emp_id,
        'tipo'        => $tipo,
        'timestamp'   => $ts,
        'es_prueba'   => 0,
    ] );
    wp_send_json_success( [ 'id' => $wpdb->insert_id ] );
}

// ─────────────────────────────────────────────
// Página de impresión: ?fc_print_asistencia=1
// ─────────────────────────────────────────────
add_action( 'template_redirect', 'fc_print_asistencia_page', 1 );
function fc_print_asistencia_page() {
    if ( ! isset( $_GET['fc_print_asistencia'] ) ) return;
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Acceso denegado.', 'Sin permiso', [ 'response' => 403 ] );
    }

    // ── Misma lógica de datos que fc_ajax_asistencia_reporte ──
    $empleada_id = intval( $_GET['empleada_id'] ?? 0 );
    $periodo     = sanitize_text_field( $_GET['periodo'] ?? 'dia' );
    $tz          = wp_timezone();

    switch ( $periodo ) {
        case 'semana':
            $inicio = new DateTime( sanitize_text_field( $_GET['semana_inicio'] ?? 'now' ), $tz );
            $dow    = (int) $inicio->format( 'N' );
            $inicio->modify( '-' . ( $dow - 1 ) . ' days' );
            $fin = ( clone $inicio )->modify( '+6 days' );
            break;
        case 'rango':
            $inicio = new DateTime( sanitize_text_field( $_GET['desde'] ?? 'now' ), $tz );
            $fin    = new DateTime( sanitize_text_field( $_GET['hasta'] ?? 'now' ), $tz );
            break;
        default:
            $inicio = new DateTime( sanitize_text_field( $_GET['fecha'] ?? 'now' ), $tz );
            $fin    = clone $inicio;
    }

    $desde = $inicio->format( 'Y-m-d' ) . ' 00:00:00';
    $hasta = $fin->format( 'Y-m-d' )    . ' 23:59:59';

    global $wpdb;
    $empleadas = $empleada_id
        ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fc_empleados WHERE id = %d", $empleada_id ) )
        : $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}fc_empleados WHERE activo=1 ORDER BY nombre ASC" );

    if ( ! $empleadas ) wp_die( 'Sin empleadas registradas.' );

    $emp_ids      = array_column( (array) $empleadas, 'id' );
    $placeholders = implode( ',', array_fill( 0, count( $emp_ids ), '%d' ) );
    $registros    = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, empleado_id, tipo, timestamp, es_prueba
         FROM {$wpdb->prefix}fc_asistencia
         WHERE empleado_id IN ($placeholders) AND timestamp BETWEEN %s AND %s
         ORDER BY empleado_id, timestamp ASC",
        array_merge( $emp_ids, [ $desde, $hasta ] )
    ) );

    $datos = [];
    foreach ( $registros as $reg ) {
        $f = substr( $reg->timestamp, 0, 10 );
        $datos[ $reg->empleado_id ][ $f ][] = [
            'id' => $reg->id, 'tipo' => $reg->tipo,
            'timestamp' => $reg->timestamp, 'es_prueba' => (bool) $reg->es_prueba,
        ];
    }

    $fechas = [];
    $cur    = clone $inicio;
    while ( $cur <= $fin ) { $fechas[] = $cur->format( 'Y-m-d' ); $cur->modify( '+1 day' ); }

    $dias_es  = [ 'Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb' ];
    $meses_es = [ 'ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic' ];

    // Título del período
    $t = (int) $inicio->format( 'j' ) . ' ' . $meses_es[ (int) $inicio->format( 'n' ) - 1 ];
    if ( $inicio->format( 'Y-m-d' ) !== $fin->format( 'Y-m-d' ) ) {
        $t .= ' – ' . (int) $fin->format( 'j' ) . ' ' . $meses_es[ (int) $fin->format( 'n' ) - 1 ];
    }
    $t .= ' ' . $inicio->format( 'Y' );

    $generado = ( new DateTime( 'now', $tz ) )->format( 'd/m/Y g:i a' );

    header( 'Content-Type: text/html; charset=UTF-8' );
    ?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Asistencia <?php echo esc_html( $t ); ?> — Florería Monarca</title>
<style>
  *, *::before, *::after {
    box-sizing: border-box; margin: 0; padding: 0;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 13px; color: #1a1a1a; background: #f0f2f5;
    padding: 24px; max-width: 720px; margin: 0 auto;
  }

  /* ── Botones (ocultos al imprimir) ── */
  .fc-print-actions {
    display: flex; justify-content: flex-end; gap: 10px; margin-bottom: 20px;
  }
  .fc-print-btn {
    padding: 8px 18px; border: none; border-radius: 6px;
    cursor: pointer; font-size: 13px; font-weight: 600;
  }
  .fc-print-btn.primary   { background: #c8185a; color: #fff; }
  .fc-print-btn.secondary { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }

  /* ── Documento ── */
  .fc-doc { border: 1px solid #d1d5db; border-radius: 10px; overflow: hidden; background: #fff; }

  /* Encabezado */
  .fc-doc-header {
    background: #c8185a; color: #fff;
    padding: 20px 28px;
    display: flex; align-items: center; justify-content: space-between;
  }
  .fc-doc-header h1   { font-size: 20px; font-weight: 700; letter-spacing: -.3px; }
  .fc-doc-subtitle    { font-size: 10px; opacity: .7; margin-top: 3px;
                        text-transform: uppercase; letter-spacing: .6px; }
  .fc-doc-header-right { text-align: right; }
  .fc-doc-periodo      { font-size: 16px; font-weight: 700; }
  .fc-doc-generado     { font-size: 10px; opacity: .65; margin-top: 3px; }

  /* Cuerpo */
  .fc-doc-body { padding: 0; }

  /* ── Sección por empleada ── */
  .fc-emp-section { border-bottom: 1px solid #f0f0f0; }
  .fc-emp-section:last-child { border-bottom: none; }

  .fc-emp-header {
    display: flex; align-items: center; gap: 16px;
    padding: 16px 24px 14px;
    background: #fdf4f7; border-bottom: 1px solid #f3d0de;
  }
  .fc-emp-foto {
    width: 48px; height: 48px; border-radius: 50%;
    border: 2px solid #c8185a; overflow: hidden; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    background: #fce8f0; font-size: 22px;
  }
  .fc-emp-foto img { width: 100%; height: 100%; object-fit: cover; }
  .fc-emp-info     { flex: 1; }
  .fc-emp-nombre   { font-size: 15px; font-weight: 800; color: #4a1228; }
  .fc-emp-meta     { font-size: 11px; color: #9a405e; margin-top: 2px; }
  .fc-emp-totales  { text-align: right; }
  .fc-emp-total-val { font-size: 20px; font-weight: 800; color: #4a1228; }
  .fc-emp-extra    { font-size: 12px; font-weight: 700; color: #c8185a; margin-top: 2px; }
  .fc-emp-sinreg   { font-size: 12px; color: #bbb; margin-top: 2px; font-style: italic; }

  /* Tabla de días */
  .fc-dias-table {
    width: 100%; border-collapse: collapse; font-size: 12px;
  }
  .fc-dias-table th {
    background: #f8f0f4; padding: 8px 20px;
    text-align: left; font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px;
    color: #9a405e; border-bottom: 1px solid #f3d0de;
  }
  .fc-dias-table td {
    padding: 10px 20px; border-bottom: 1px solid #f7f0f3;
    vertical-align: top;
  }
  .fc-dias-table tr:last-child td { border-bottom: none; }
  .fc-dias-table tr.fc-domingo td { background: #fafafa; color: #aaa; }

  .fc-dia-fecha   { font-weight: 700; color: #333; white-space: nowrap; }
  .fc-dia-fecha small { display: block; font-weight: 400; color: #888; font-size: 10px; }

  .fc-pares-lista  { display: flex; flex-direction: column; gap: 3px; }
  .fc-par-row      { display: flex; gap: 6px; align-items: center; }
  .fc-par-ent      { color: #15803d; font-weight: 600; min-width: 78px; }
  .fc-par-sep      { color: #ccc; }
  .fc-par-sal      { color: #b91c1c; font-weight: 600; min-width: 78px; }
  .fc-par-dur      { color: #888; font-size: 11px; }
  .fc-par-tienda   { color: #b45309; font-style: italic; font-size: 11px; }
  .fc-dia-total    { font-weight: 800; color: #111; font-size: 13px; white-space: nowrap; }
  .fc-dia-extra    { font-size: 11px; color: #c8185a; font-weight: 700; }
  .fc-dia-deficit  { font-size: 11px; color: #bbb; }
  .fc-dia-sinreg   { color: #ccc; font-size: 13px; }
  .fc-prueba-tag   { font-size: 9px; background: #fff3cd; color: #856404;
                     border-radius: 3px; padding: 1px 4px; margin-left: 4px; }

  /* ── Pie del documento ── */
  .fc-doc-footer {
    border-top: 2px solid #c8185a;
    padding: 14px 24px;
    background: #fdf4f7;
    font-size: 10px; color: #9a405e;
    display: flex; justify-content: space-between; align-items: center;
  }

  /* ── Print ── */
  @media print {
    body { background: #fff !important; padding: 0; }
    .fc-print-actions { display: none !important; }
    .fc-doc { border: none; border-radius: 0; }
    .fc-doc:not(:last-child) { break-after: page; }
    @page { margin: 1.5cm 2cm; size: portrait; }
  }
</style>
</head>
<body>

<div class="fc-print-actions">
    <button class="fc-print-btn secondary" onclick="window.close()">✕ Cerrar</button>
    <button class="fc-print-btn primary"   onclick="window.print()">🖨 Imprimir / Guardar PDF</button>
</div>

<?php foreach ( $empleadas as $emp ) :
    $min_req   = (int) round( (float) $emp->horas_requeridas * 60 );
    $emp_datos = $datos[ $emp->id ] ?? [];
    $total_min = 0; $total_ext = 0;

    foreach ( $fechas as $f ) {
        $regs = $emp_datos[ $f ] ?? [];
        if ( ! $regs ) continue;
        $c = fc_calcular_dia( $regs );
        $total_min += $c['total_min'];
        $total_ext += max( 0, $c['total_min'] - $min_req );
    }
?>
<div class="fc-doc">

    <!-- Encabezado con período (se repite en cada hoja) -->
    <div class="fc-doc-header">
        <div>
            <h1>Florería Monarca</h1>
            <div class="fc-doc-subtitle">Reporte de Asistencia</div>
        </div>
        <div class="fc-doc-header-right">
            <div class="fc-doc-periodo"><?php echo esc_html( $t ); ?></div>
            <div class="fc-doc-generado">Generado el <?php echo esc_html( $generado ); ?></div>
        </div>
    </div>

    <div class="fc-doc-body">

        <!-- Cabecera de empleada -->
        <div class="fc-emp-header">
            <div class="fc-emp-foto">
                <?php if ( $emp->foto_url ) : ?>
                    <img src="<?php echo esc_url( $emp->foto_url ); ?>" alt="">
                <?php else : ?>👤<?php endif; ?>
            </div>
            <div class="fc-emp-info">
                <div class="fc-emp-nombre"><?php echo esc_html( $emp->nombre ); ?></div>
                <div class="fc-emp-meta">
                    <?php echo esc_html( $emp->posicion ?: 'Sin posición' ); ?>
                    &nbsp;·&nbsp;
                    Jornada: <?php echo esc_html( fc_fmt_minutos( $min_req ) ); ?>/día
                </div>
            </div>
            <div class="fc-emp-totales">
                <?php if ( $total_min > 0 ) : ?>
                    <div class="fc-emp-total-val"><?php echo esc_html( fc_fmt_minutos( $total_min ) ); ?></div>
                    <?php if ( $total_ext > 0 ) : ?>
                        <div class="fc-emp-extra">+<?php echo esc_html( fc_fmt_minutos( $total_ext ) ); ?> extra</div>
                    <?php endif; ?>
                <?php else : ?>
                    <div class="fc-emp-sinreg">Sin registros</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tabla de días -->
        <table class="fc-dias-table">
            <thead>
                <tr>
                    <th style="width:130px">Día</th>
                    <th>Registros</th>
                    <th style="width:110px;text-align:right">Total · Extra</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $fechas as $f ) :
                $dt      = new DateTime( $f );
                $dow_num = (int) $dt->format( 'w' );
                $dia_lbl = $dias_es[ $dow_num ] . ' ' . (int) $dt->format( 'j' );
                $mes_lbl = $meses_es[ (int) $dt->format( 'n' ) - 1 ];
                $regs    = $emp_datos[ $f ] ?? [];
                $calc    = $regs ? fc_calcular_dia( $regs ) : [ 'total_min'=>0,'pares'=>[],'en_tienda'=>false,'ent_abierta'=>null,'prueba'=>false ];
                $extra   = max( 0, $calc['total_min'] - $min_req );
                $is_dom  = $dow_num === 0;
            ?>
            <tr<?php echo $is_dom ? ' class="fc-domingo"' : ''; ?>>
                <td>
                    <div class="fc-dia-fecha">
                        <?php echo esc_html( $dia_lbl ); ?>
                        <small><?php echo esc_html( $mes_lbl . ' ' . $dt->format( 'Y' ) ); ?></small>
                    </div>
                </td>
                <td>
                    <?php if ( $regs ) : ?>
                        <div class="fc-pares-lista">
                        <?php foreach ( $calc['pares'] as $par ) : ?>
                            <div class="fc-par-row">
                                <span class="fc-par-ent">↑ <?php echo esc_html( fc_fmt_hora( $par['ent'] ) ); ?></span>
                                <span class="fc-par-sep">→</span>
                                <span class="fc-par-sal">↓ <?php echo esc_html( fc_fmt_hora( $par['sal'] ) ); ?></span>
                                <span class="fc-par-dur">(<?php echo esc_html( fc_fmt_minutos( $par['min'] ) ); ?>)</span>
                                <?php if ( $calc['prueba'] ) echo '<span class="fc-prueba-tag">prueba</span>'; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if ( $calc['en_tienda'] ) : ?>
                            <div class="fc-par-row">
                                <span class="fc-par-ent">↑ <?php echo esc_html( fc_fmt_hora( $calc['ent_abierta'] ) ); ?></span>
                                <span class="fc-par-sep">→</span>
                                <span class="fc-par-tienda">En tienda…</span>
                            </div>
                        <?php endif; ?>
                        </div>
                    <?php elseif ( $is_dom ) : ?>
                        <span style="color:#ddd;font-size:11px;">Domingo</span>
                    <?php else : ?>
                        <span class="fc-dia-sinreg">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;vertical-align:top;">
                    <?php if ( $calc['total_min'] > 0 ) : ?>
                        <div class="fc-dia-total"><?php echo esc_html( fc_fmt_minutos( $calc['total_min'] ) ); ?></div>
                        <?php if ( $extra > 0 ) : ?>
                            <div class="fc-dia-extra">+<?php echo esc_html( fc_fmt_minutos( $extra ) ); ?></div>
                        <?php else : ?>
                            <div class="fc-dia-deficit">—</div>
                        <?php endif; ?>
                    <?php elseif ( $regs ) : ?>
                        <div class="fc-dia-deficit" style="font-size:11px;">En tienda</div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    </div><!-- .fc-doc-body -->

    <!-- Pie con leyenda -->
    <div class="fc-doc-footer">
        <span>↑ Entrada &nbsp;·&nbsp; ↓ Salida &nbsp;·&nbsp; P = registro de prueba</span>
        <span><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
    </div>

</div><!-- .fc-doc -->
<?php endforeach; ?>

<script>
// Abrir diálogo de impresión al cargar
window.addEventListener('load', function() {
    setTimeout(function() { window.print(); }, 500);
});
</script>
</body>
</html>
<?php
    exit;
}
