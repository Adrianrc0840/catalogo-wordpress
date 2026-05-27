<?php
/**
 * Plugin Name: Florería Monarca
 * Plugin URI:  https://github.com/Adrianrc0840/catalogo-wordpress
 * Description: Sistema completo para florerías: catálogo, pedidos por WhatsApp, punto de venta, panel de floristas y gestión de caja.
 * Version:     4.2
 * Author:      Adrián Rodríguez
 * Text Domain: floreria-catalogo
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FC_PATH',    plugin_dir_path( __FILE__ ) );
define( 'FC_URL',     plugin_dir_url( __FILE__ ) );
define( 'FC_VERSION', '4.2' );

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

    // Respaldo CSS: oculta el FAB aunque el JS esté cacheado en el navegador
    if ( $hide_cart ) {
        wp_add_inline_style( 'fc-cart', '.fc-cart-fab{display:none!important;}' );
    }

    wp_localize_script( 'fc-cart', 'fcCartData', [
        'ajaxurl'          => admin_url( 'admin-ajax.php' ),
        'whatsappNonce'    => wp_create_nonce( 'fc_whatsapp_pedido' ),
        'schedules'        => fc_get_schedules(),
        'fechasEspeciales' => fc_get_fechas_especiales(),
        'fechasCerradas'   => fc_get_fechas_cerradas(),
        'whatsapp'         => get_option( 'fc_whatsapp', '' ),
        'gmapsKey'         => $gmaps_key,
        'hideCart'         => $hide_cart,
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
            'whatsappNonce'    => wp_create_nonce( 'fc_whatsapp_pedido' ),
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

    if ( has_shortcode( get_post()->post_content ?? '', 'floreria_politicas' ) ) {
        wp_enqueue_script( 'fc-politicas', FC_URL . 'assets/js/politicas.js', [], FC_VERSION, true );
    }
}

// Ocultar barra de administrador en PDV y panel de floristas
add_filter( 'show_admin_bar', function( $show ) {
    if ( get_query_var( 'fc_pdv' ) || get_query_var( 'fc_panel_florista' ) ) {
        return false;
    }
    return $show;
} );

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
