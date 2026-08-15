<?php
/**
 * Plugin Name: Florería Monarca
 * Plugin URI:  https://github.com/Adrianrc0840/catalogo-wordpress
 * Description: Sistema completo para florerías: catálogo, pedidos por WhatsApp, punto de venta, panel de floristas y gestión de caja.
 * Version:     5.3.2
 * Author:      Adrián Rodríguez
 * Text Domain: floreria-catalogo
 * Requires at least: 6.0
 * Tested up to:      6.8
 * Requires PHP:      8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FC_PATH',    plugin_dir_path( __FILE__ ) );
define( 'FC_URL',     plugin_dir_url( __FILE__ ) );
define( 'FC_VERSION', '5.3.2' );

require_once FC_PATH . 'includes/cpt.php';
require_once FC_PATH . 'includes/meta-boxes.php';
require_once FC_PATH . 'includes/shortcode.php';
require_once FC_PATH . 'includes/admin-horarios.php';
require_once FC_PATH . 'includes/schedules.php';
require_once FC_PATH . 'includes/settings.php';
require_once FC_PATH . 'includes/admin-list.php';
require_once FC_PATH . 'includes/csv-tools.php';
require_once FC_PATH . 'includes/politicas.php';
require_once FC_PATH . 'includes/cpt-pedido.php';
require_once FC_PATH . 'includes/panel-florista.php';
require_once FC_PATH . 'includes/cpt-caja.php';
require_once FC_PATH . 'includes/pdv.php';
require_once FC_PATH . 'includes/push-onesignal.php';
require_once FC_PATH . 'includes/asistencia.php';

add_action( 'wp_enqueue_scripts', 'fc_enqueue_frontend' );
function fc_enqueue_frontend() {
    wp_enqueue_style( 'fc-catalogo', FC_URL . 'assets/css/catalogo.css', [], FC_VERSION );

    // CSS de rastreo de pedido (query var directo o wrapper Elementor)
    if ( get_query_var( 'fc_pedido_ref' ) || ! empty( $GLOBALS['fc_is_rastreo_pedido'] ) ) {
        wp_enqueue_style( 'fc-pedido', FC_URL . 'assets/css/pedido.css', [], FC_VERSION );
    }

    // Google Maps Places (si hay key configurada)
    $gmaps_key  = get_option( 'fc_gmaps_key', '' );
    $cart_deps  = [];
    if ( $gmaps_key ) {
        wp_enqueue_script(
            'google-places',
            'https://maps.googleapis.com/maps/api/js?key=' . urlencode( $gmaps_key ) . '&libraries=places',
            [],
            null,
            true
        );
        $cart_deps[] = 'google-places';
    }

    // Cart assets on all frontend pages
    wp_enqueue_style(  'fc-cart', FC_URL . 'assets/css/cart.css', [], FC_VERSION );
    wp_enqueue_script( 'fc-cart', FC_URL . 'assets/js/cart.js', $cart_deps, FC_VERSION, true );
    // Localize schedule/whatsapp data to cart script (always fresh, no stale localStorage)
    // Verificar si el carrito debe ocultarse en la página actual
    $cart_disabled_pages = get_option( 'fc_cart_disabled_pages', [] );
    if ( ! is_array( $cart_disabled_pages ) ) $cart_disabled_pages = [];
    $cart_disabled_pages = array_map( 'intval', $cart_disabled_pages );
    $current_page_id     = (int) get_queried_object_id();
    $hide_cart           = $current_page_id > 0 && in_array( $current_page_id, $cart_disabled_pages, true );

    // Pantallas internas donde no va ningún botón flotante (ni carrito ni WhatsApp):
    // herramientas del negocio y la página de rastreo del cliente.
    //
    // Se decide aquí, en un solo lugar, en vez de con una regla CSS por pantalla
    // apuntando a cada botón — así lo que se agregue después queda cubierto solo.
    // wp_enqueue_scripts corre dentro de wp_head, o sea después de template_redirect,
    // por eso los globals de wrapper ya están disponibles además de los query vars.
    $es_pantalla_interna = get_query_var( 'fc_pdv' )
                        || get_query_var( 'fc_panel_florista' )
                        || get_query_var( 'fc_asistencia' )
                        || get_query_var( 'fc_pedido_ref' )
                        || ! empty( $GLOBALS['fc_is_asistencia_wrapper'] )
                        || ! empty( $GLOBALS['fc_is_rastreo_pedido'] );

    $hide_floating = $hide_cart || $es_pantalla_interna;

    // Respaldo CSS: oculta los flotantes aunque el JS esté cacheado en el navegador
    if ( $hide_floating ) {
        wp_add_inline_style( 'fc-cart', '.fc-cart-fab,.fc-wa-fab{display:none!important;}' );
    }

    wp_localize_script( 'fc-cart', 'fcCartData', [
        'ajaxurl'          => admin_url( 'admin-ajax.php' ),
        'schedules'        => fc_get_schedules(),
        'fechasEspeciales' => fc_get_fechas_especiales(),
        'fechasCerradas'   => fc_get_fechas_cerradas(),
        'whatsapp'         => get_option( 'fc_whatsapp', '' ),
        'gmapsKey'         => $gmaps_key,
        'hideCart'         => $hide_floating,
        'waFabMensaje'     => '¡Hola! Vi su catálogo y me gustaría hacer un pedido',
        'politicasUrl'     => get_option( 'fc_politicas_url', '#' ),
    ] );

    // Detectar página de detalle de arreglo: ya sea URL directa (/arreglos/nombre/)
    // o redirigida a una página wrapper de Elementor (flag puesto en template_redirect).
    $is_arreglo_page = is_singular( 'arreglo' ) || ! empty( $GLOBALS['fc_is_arreglo_detalle'] );
    $arreglo_id      = $is_arreglo_page
        ? (int) ( $GLOBALS['fc_arreglo_id'] ?? ( is_singular( 'arreglo' ) ? get_the_ID() : 0 ) )
        : 0;

    if ( $is_arreglo_page && $arreglo_id ) {
        wp_enqueue_style( 'fc-detalle', FC_URL . 'assets/css/detalle.css', [], FC_VERSION );

        wp_enqueue_script( 'fc-detalle', FC_URL . 'assets/js/detalle.js', [ 'fc-cart' ], FC_VERSION, true );

        $tamanos = get_post_meta( $arreglo_id, '_fc_tamanos', true );
        if ( ! is_array( $tamanos ) ) $tamanos = [];

        // Índice del tamaño marcado como principal
        $tamano_principal = 0;
        foreach ( $tamanos as $i => $t ) {
            if ( ! empty( $t['foto_catalogo'] ) && $t['foto_catalogo'] === '1' ) {
                $tamano_principal = $i;
                break;
            }
        }

        wp_localize_script( 'fc-detalle', 'fcArreglo', [
            'ajaxurl'          => admin_url( 'admin-ajax.php' ),
            'arregloId'        => $arreglo_id,
            'tamanos'          => $tamanos,
            'tamano_principal' => $tamano_principal,
            'whatsapp'         => get_option( 'fc_whatsapp', '' ),
            'schedules'        => fc_get_schedules(),
            'fechasEspeciales' => fc_get_fechas_especiales(),
            'fechasCerradas'   => fc_get_fechas_cerradas(),
            'permalink'        => get_permalink( $arreglo_id ),
            'titulo'           => get_the_title( $arreglo_id ),
            'especial'         => get_post_meta( $arreglo_id, '_fc_especial', true ) === '1',
            'politicas_url'    => get_option( 'fc_politicas_url', '' ),
        ] );
    } else {
        wp_enqueue_script( 'fc-catalogo', FC_URL . 'assets/js/catalogo.js', [], FC_VERSION, true );
    }

    $post_content = get_post()->post_content ?? '';
    if ( has_shortcode( $post_content, 'floreria_politicas' ) || has_shortcode( $post_content, 'floreria_detalle_arreglo' ) || is_singular( 'arreglo' ) ) {
        wp_enqueue_script( 'fc-politicas', FC_URL . 'assets/js/politicas.js', [], FC_VERSION, true );
    }
}

// Ocultar barra de administrador en PDV, panel de floristas y kiosco de asistencia.
// Ojo: WordPress inicializa el admin bar en template_redirect prioridad 0, y los
// globals de wrapper (fc_is_asistencia_wrapper, etc.) se definen en prioridad 1 —
// o sea, aún no existen aquí. Solo sirven los query vars, que se resuelven antes.
add_filter( 'show_admin_bar', function( $show ) {
    if ( get_query_var( 'fc_pdv' ) || get_query_var( 'fc_panel_florista' ) || get_query_var( 'fc_asistencia' ) ) {
        return false;
    }
    return $show;
} );

// ── Aligerar el kiosco de asistencia ─────────────────────────────────────────
// Solo necesita sus propios assets, así que se descarga todo lo demás que
// WordPress le encola (Elementor y complementos, feed de Instagram, visor de
// PDF, React, Backbone, fuentes completas). Baja de ~120 peticiones a ~15.
//
// Se corre en prioridad alta para pasar después de que todos hayan encolado.
//
// NO se aplica al PDV ni al panel: ahí la hoja del tema aporta la base del
// layout (alto completo y scroll) y las fuentes, así que quitarla los rompe.
// Se probó y se revirtió — si algún día se quiere aligerarlos, hay que
// identificar en local qué handles del tema hacen falta y conservarlos.
//
// Tampoco al kiosco si se renderiza por wrapper de Elementor: en ese caso la
// página sí necesita los assets de Elementor para verse bien.
add_action( 'wp_enqueue_scripts', 'fc_aligerar_pantallas_internas', 9999 );
function fc_aligerar_pantallas_internas() {
    $asistencia_con_wrapper = ! empty( $GLOBALS['fc_is_asistencia_wrapper'] );

    $es_interna = get_query_var( 'fc_asistencia' ) && ! $asistencia_con_wrapper;

    if ( ! $es_interna ) return;

    // Se conserva lo del plugin y Google Maps, que es la única dependencia
    // externa que declaran estos scripts. No usan jQuery.
    $conservar = static function ( $handle ) {
        return strpos( $handle, 'fc-' ) === 0 || $handle === 'google-places';
    };

    global $wp_styles, $wp_scripts;

    if ( $wp_styles instanceof WP_Styles ) {
        foreach ( (array) $wp_styles->queue as $handle ) {
            if ( ! $conservar( $handle ) ) wp_dequeue_style( $handle );
        }
    }
    if ( $wp_scripts instanceof WP_Scripts ) {
        foreach ( (array) $wp_scripts->queue as $handle ) {
            if ( ! $conservar( $handle ) ) wp_dequeue_script( $handle );
        }
    }

    // Los emojis se imprimen por su propio hook, fuera de la cola
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
}

// ── Páginas privadas fuera de buscadores ─────────────────────────────────────
// Cubre PDV, panel de floristas, kiosco de asistencia y rastreo de pedido.
// Rastreo es el importante: muestra nombre, teléfono, dirección y mensaje de
// tarjeta del cliente.
//
// Se usa la cabecera HTTP y no <meta robots> porque el plugin de SEO inyecta su
// propio <meta name="robots" content="follow, index"> en estas páginas; dos
// etiquetas contradictorias dependen de que el buscador elija la más
// restrictiva. La cabecera no admite esa ambigüedad y además se envía aunque la
// página se renderice por wrapper de Elementor o por shortcode.
//
// send_headers corre después de resolver la query, así que los query vars ya
// están disponibles aquí (los globals de wrapper todavía no).
add_action( 'send_headers', function() {
    $privadas = get_query_var( 'fc_pdv' )
             || get_query_var( 'fc_panel_florista' )
             || get_query_var( 'fc_asistencia' )
             || get_query_var( 'fc_pedido_ref' );
    if ( $privadas && ! headers_sent() ) {
        header( 'X-Robots-Tag: noindex, nofollow', true );
    }
} );

// Asistencia: noindex + ocultar carrito
add_action( 'wp_head', function() {
    if ( ! get_query_var( 'fc_asistencia' ) && empty( $GLOBALS['fc_is_asistencia_wrapper'] ) ) return;
    echo '<meta name="robots" content="noindex, nofollow">' . "\n";
} );
add_action( 'wp_enqueue_scripts', function() {
    global $post;
    $tiene_shortcode = is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'floreria_kiosco_asistencia' );
    if ( ! get_query_var( 'fc_asistencia' ) && empty( $GLOBALS['fc_is_asistencia_wrapper'] ) && ! $tiene_shortcode ) return;
    wp_add_inline_style( 'fc-cart', '.fc-cart-fab{display:none!important;}' );
}, 99 );

// ── Wrapper de Elementor para detalle de arreglo ──────────────────────────────
// Cuando el visitante entra a /arreglos/nombre-del-arreglo/, si hay una página
// wrapper configurada, se cambia la query principal para que WordPress (y Elementor)
// carguen esa página en su lugar.  La URL visible no cambia.
// Prioridad 1 → corre antes de que Elementor registre su propio template_include.
add_action( 'template_redirect', 'fc_arreglo_wrapper_redirect', 1 );
function fc_arreglo_wrapper_redirect() {
    if ( ! is_singular( 'arreglo' ) ) return;

    $wrapper_id = (int) get_option( 'fc_arreglo_wrapper_page_id', 0 );
    if ( ! $wrapper_id ) return;

    $wrapper = get_post( $wrapper_id );
    if ( ! $wrapper || $wrapper->post_status !== 'publish' ) return;

    global $wp_query, $post;

    // Guardar el ID del arreglo real para el shortcode y para enqueue
    $GLOBALS['fc_arreglo_id']        = get_queried_object_id();
    $GLOBALS['fc_is_arreglo_detalle'] = true;

    // Hacer que la query principal apunte a la página wrapper
    $post                        = $wrapper;
    $wp_query->posts             = [ $wrapper ];
    $wp_query->post              = $wrapper;
    $wp_query->queried_object    = $wrapper;
    $wp_query->queried_object_id = $wrapper_id;
    $wp_query->found_posts       = 1;
    $wp_query->post_count        = 1;
    $wp_query->is_singular       = true;
    $wp_query->is_page           = true;
    $wp_query->is_single         = false;
    setup_postdata( $post );
    // A partir de aquí, is_singular('arreglo') = false → Elementor carga la
    // página wrapper con su canvas/nav/footer.  El shortcode [floreria_detalle_arreglo]
    // dentro de esa página usa $GLOBALS['fc_arreglo_id'] para saber qué mostrar.
}

// ── Wrapper de Elementor para rastreo de pedido ──────────────────────────────
// Cuando el cliente entra a /pedido/TOKEN, si hay una página wrapper configurada,
// se cambia la query principal para que WordPress (y Elementor) carguen esa página.
// La URL visible no cambia. El shortcode [floreria_rastreo_pedido] dentro de la
// página wrapper usa $GLOBALS['fc_pedido_ref'] para saber qué pedido mostrar.
// Prioridad 1 → corre antes de que Elementor registre su propio template_include.
add_action( 'template_redirect', 'fc_rastreo_wrapper_redirect', 1 );
function fc_rastreo_wrapper_redirect() {
    $ref = get_query_var( 'fc_pedido_ref' );
    if ( ! $ref ) return;

    $wrapper_id = (int) get_option( 'fc_rastreo_wrapper_page_id', 0 );
    if ( ! $wrapper_id ) return;

    $wrapper = get_post( $wrapper_id );
    if ( ! $wrapper || $wrapper->post_status !== 'publish' ) return;

    global $wp_query, $post;

    $GLOBALS['fc_pedido_ref']        = sanitize_text_field( $ref );
    $GLOBALS['fc_is_rastreo_pedido'] = true;

    $post                        = $wrapper;
    $wp_query->posts             = [ $wrapper ];
    $wp_query->post              = $wrapper;
    $wp_query->queried_object    = $wrapper;
    $wp_query->queried_object_id = $wrapper_id;
    $wp_query->found_posts       = 1;
    $wp_query->post_count        = 1;
    $wp_query->is_singular       = true;
    $wp_query->is_page           = true;
    $wp_query->is_single         = false;
    setup_postdata( $post );
}

// ── Wrapper de Elementor para kiosco de asistencia ───────────────────────────
// Cuando alguien entra a /asistencia/, si hay una página wrapper configurada,
// se cambia la query principal para que el tema/Elementor carguen esa página.
// La URL visible no cambia. El shortcode [floreria_kiosco_asistencia] dentro de
// esa página renderiza el login o el kiosco según el estado de la sesión.
// Prioridad 1 → corre antes de que Elementor registre su propio template_include.
add_action( 'template_redirect', 'fc_asistencia_wrapper_redirect', 1 );
function fc_asistencia_wrapper_redirect() {
    if ( ! get_query_var( 'fc_asistencia' ) ) return;

    $wrapper_id = (int) get_option( 'fc_asistencia_wrapper_page_id', 0 );
    if ( ! $wrapper_id ) return;

    $wrapper = get_post( $wrapper_id );
    if ( ! $wrapper || $wrapper->post_status !== 'publish' ) return;

    global $wp_query, $post;

    $GLOBALS['fc_is_asistencia_wrapper'] = true;

    $post                        = $wrapper;
    $wp_query->posts             = [ $wrapper ];
    $wp_query->post              = $wrapper;
    $wp_query->queried_object    = $wrapper;
    $wp_query->queried_object_id = $wrapper_id;
    $wp_query->found_posts       = 1;
    $wp_query->post_count        = 1;
    $wp_query->is_singular       = true;
    $wp_query->is_page           = true;
    $wp_query->is_single         = false;
    setup_postdata( $post );
}

add_filter( 'template_include', 'fc_template_include' );
function fc_template_include( $template ) {
    // Solo aplica cuando NO hay wrapper configurado (fallback al template propio).
    // Si hay wrapper, template_redirect ya cambió la query y Elementor/tema
    // elegirán el template de la página wrapper automáticamente.
    if ( is_singular( 'arreglo' ) ) {
        $custom = FC_PATH . 'templates/single-arreglo.php';
        if ( file_exists( $custom ) ) return $custom;
    }
    return $template;
}
