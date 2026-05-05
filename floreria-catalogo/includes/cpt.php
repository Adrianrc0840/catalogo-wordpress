<?php
if ( ! defined( 'ABSPATH' ) ) exit;

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
