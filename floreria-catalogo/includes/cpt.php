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
        'menu_icon'    => 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M10 2 C12 4 12 8 10 10 C8 8 8 4 10 2Z"/><path d="M10 2 C12 4 12 8 10 10 C8 8 8 4 10 2Z" transform="rotate(60 10 10)"/><path d="M10 2 C12 4 12 8 10 10 C8 8 8 4 10 2Z" transform="rotate(120 10 10)"/><path d="M10 2 C12 4 12 8 10 10 C8 8 8 4 10 2Z" transform="rotate(180 10 10)"/><path d="M10 2 C12 4 12 8 10 10 C8 8 8 4 10 2Z" transform="rotate(240 10 10)"/><path d="M10 2 C12 4 12 8 10 10 C8 8 8 4 10 2Z" transform="rotate(300 10 10)"/><circle cx="10" cy="10" r="3"/></svg>' ),
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
