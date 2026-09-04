<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Condición para dejar fuera los arreglos ocultos (`_fc_oculto`).
 *
 * La usan las cuatro consultas que listan arreglos de cara a una venta: el
 * catálogo, los dos bloques de recomendados y el catálogo del PDV. Está aquí en
 * un solo lugar para que agregar una pantalla nueva no signifique volver a
 * razonar la condición.
 *
 * La excepción deliberada es el buscador de arreglos del panel de floristas
 * (`panel-florista.php`): es interno y a veces hay que meter a un pedido un
 * arreglo ya retirado del catálogo, así que ahí sí siguen apareciendo.
 *
 * El `NOT EXISTS` no es opcional: los arreglos creados antes de que existiera
 * la casilla no tienen el meta, y en WordPress una comparación `!=` sobre un
 * meta inexistente NO empareja. Sin esa rama desaparecería todo el catálogo.
 */
function fc_meta_query_no_ocultos() {
    return [
        'relation' => 'OR',
        [ 'key' => '_fc_oculto', 'compare' => 'NOT EXISTS' ],
        [ 'key' => '_fc_oculto', 'value' => '1', 'compare' => '!=' ],
    ];
}

/**
 * Un arreglo oculto tampoco se abre por enlace directo.
 *
 * Sacarlo de las listas no basta: quien tenga el link guardado, o lo encuentre
 * en Google, entraría a pedir algo que ya no está a la venta. Se manda al
 * catálogo en lugar de a un 404, que para una tienda es un callejón sin salida.
 *
 * Corre en prioridad 2 porque la página envoltorio de Elementor define sus
 * globals en la prioridad 1; antes de eso no se sabría que se está viendo un
 * arreglo. Cubre las dos rutas: la URL propia del CPT y el envoltorio.
 *
 * Quien pueda editar el arreglo sí lo ve, para poder revisarlo antes de
 * mostrarlo al público.
 */
add_action( 'template_redirect', 'fc_bloquear_arreglo_oculto', 2 );
function fc_bloquear_arreglo_oculto() {
    $arreglo_id = 0;
    if ( is_singular( 'arreglo' ) ) {
        $arreglo_id = (int) get_queried_object_id();
    } elseif ( ! empty( $GLOBALS['fc_is_arreglo_detalle'] ) ) {
        $arreglo_id = (int) ( $GLOBALS['fc_arreglo_id'] ?? 0 );
    }
    if ( ! $arreglo_id ) return;

    if ( get_post_meta( $arreglo_id, '_fc_oculto', true ) !== '1' ) return;
    if ( current_user_can( 'edit_post', $arreglo_id ) ) return;

    wp_safe_redirect( get_option( 'fc_catalog_page_url', home_url() ), 302 );
    exit;
}

add_action( 'init', 'fc_register_cpt' );
function fc_register_cpt() {
    register_post_type( 'arreglo', [
        'labels' => [
            'name'               => 'Arreglos',
            'singular_name'      => 'Arreglo',
            'add_new'            => 'Añadir nuevo',
            'add_new_item'       => 'Añadir nuevo arreglo',
            'edit_item'          => 'Editar arreglo',
            'new_item'           => 'Nuevo arreglo',
            'view_item'          => 'Ver arreglo',
            'search_items'       => 'Buscar arreglos',
            'not_found'          => 'No se encontraron arreglos',
            'not_found_in_trash' => 'No hay arreglos en la papelera',
        ],
        'public'       => true,
        'has_archive'  => false,
        'show_in_menu' => true,
        'menu_icon'    => 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><ellipse cx="10" cy="5.5" rx="2" ry="3.5"/><ellipse cx="10" cy="5.5" rx="2" ry="3.5" transform="rotate(72 10 10)"/><ellipse cx="10" cy="5.5" rx="2" ry="3.5" transform="rotate(144 10 10)"/><ellipse cx="10" cy="5.5" rx="2" ry="3.5" transform="rotate(216 10 10)"/><ellipse cx="10" cy="5.5" rx="2" ry="3.5" transform="rotate(288 10 10)"/><circle cx="10" cy="10" r="3"/></svg>' ),
        'supports'     => [ 'title', 'thumbnail' ],
        'rewrite'      => [ 'slug' => 'arreglos' ],
    ] );

    register_taxonomy( 'categoria_arreglo', 'arreglo', [
        'labels' => [
            'name'          => 'Categorías',
            'singular_name' => 'Categoría',
            'all_items'     => 'Todas las categorías',
            'edit_item'     => 'Editar categoría',
            'update_item'   => 'Actualizar categoría',
            'add_new_item'  => 'Añadir nueva categoría',
            'new_item_name' => 'Nueva categoría',
            'menu_name'     => 'Categorías',
        ],
        'hierarchical'      => true,
        'show_ui'           => true,
        'show_admin_column' => true,
        'rewrite'           => [ 'slug' => 'categoria-arreglo' ],
    ] );
}
