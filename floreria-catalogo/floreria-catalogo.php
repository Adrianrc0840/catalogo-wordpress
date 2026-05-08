<?php
/**
 * Plugin Name: Florería Catálogo
 * Plugin URI:  https://github.com/Adrianrc0840/catalogo-wordpress
 * Description: Catálogo de arreglos florales con pedidos por WhatsApp
 * Version:     1.0.0
 * Author:      Adrian
 * Text Domain: floreria-catalogo
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FC_PATH',    plugin_dir_path( __FILE__ ) );
define( 'FC_URL',     plugin_dir_url( __FILE__ ) );
define( 'FC_VERSION', '1.0.0' );

require_once FC_PATH . 'includes/cpt.php';
require_once FC_PATH . 'includes/meta-boxes.php';
require_once FC_PATH . 'includes/shortcode.php';
require_once FC_PATH . 'includes/schedules.php';
require_once FC_PATH . 'includes/settings.php';
require_once FC_PATH . 'includes/admin-list.php';
require_once FC_PATH . 'includes/csv-tools.php';
require_once FC_PATH . 'includes/politicas.php';
require_once FC_PATH . 'includes/cpt-pedido.php';
require_once FC_PATH . 'includes/panel-florista.php';

add_action( 'wp_enqueue_scripts', 'fc_enqueue_frontend' );
function fc_enqueue_frontend() {
    wp_enqueue_style( 'fc-catalogo', FC_URL . 'assets/css/catalogo.css', [], FC_VERSION );

    if ( is_singular( 'arreglo' ) ) {
        wp_enqueue_style( 'fc-detalle', FC_URL . 'assets/css/detalle.css', [], FC_VERSION );

        wp_enqueue_script( 'fc-detalle', FC_URL . 'assets/js/detalle.js', [], FC_VERSION, true );

        global $post;
        $tamanos = get_post_meta( $post->ID, '_fc_tamanos', true );
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
            'tamanos'          => $tamanos,
            'tamano_principal' => $tamano_principal,
            'whatsapp'  => get_option( 'fc_whatsapp', '' ),
            'schedules' => fc_get_schedules(),
            'permalink' => get_permalink( $post->ID ),
            'titulo'    => get_the_title( $post->ID ),
            'especial'      => get_post_meta( $post->ID, '_fc_especial', true ) === '1',
            'politicas_url' => get_option( 'fc_politicas_url', '' ),
        ] );
    } else {
        wp_enqueue_script( 'fc-catalogo', FC_URL . 'assets/js/catalogo.js', [], FC_VERSION, true );
    }

    if ( has_shortcode( get_post()->post_content ?? '', 'floreria_politicas' ) ) {
        wp_enqueue_script( 'fc-politicas', FC_URL . 'assets/js/politicas.js', [], FC_VERSION, true );
    }
}

add_filter( 'template_include', 'fc_template_include' );
function fc_template_include( $template ) {
    if ( is_singular( 'arreglo' ) ) {
        $custom = FC_PATH . 'templates/single-arreglo.php';
        if ( file_exists( $custom ) ) return $custom;
    }
    return $template;
}
