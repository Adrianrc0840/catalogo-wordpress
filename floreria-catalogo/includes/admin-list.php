<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Columna Disponibilidad en la lista ──
add_filter( 'manage_arreglo_posts_columns', 'fc_add_disponibilidad_column' );
function fc_add_disponibilidad_column( $columns ) {
    $columns['fc_disponibilidad'] = 'Disponibilidad';
    return $columns;
}

add_action( 'manage_arreglo_posts_custom_column', 'fc_render_disponibilidad_column', 10, 2 );
function fc_render_disponibilidad_column( $column, $post_id ) {
    if ( $column !== 'fc_disponibilidad' ) return;

    $agotado = get_post_meta( $post_id, '_fc_agotado', true ) === '1';
    $nonce   = wp_create_nonce( 'fc_toggle_' . $post_id );
    $url     = admin_url( 'edit.php?post_type=arreglo&fc_toggle=' . $post_id . '&_wpnonce=' . $nonce );

    if ( $agotado ) {
        echo '<a href="' . esc_url( $url ) . '" style="color:#cc3344;font-weight:600;" title="Clic para marcar como Disponible">&#10007; Agotado</a>';
    } else {
        echo '<a href="' . esc_url( $url ) . '" style="color:#25a244;font-weight:600;" title="Clic para marcar como Agotado">&#10003; Disponible</a>';
    }
}

// ── Toggle individual desde la columna ──
add_action( 'admin_init', 'fc_handle_inline_toggle' );
function fc_handle_inline_toggle() {
    if ( ! isset( $_GET['fc_toggle'], $_GET['_wpnonce'] ) ) return;

    $post_id = intval( $_GET['fc_toggle'] );
    if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'fc_toggle_' . $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $actual = get_post_meta( $post_id, '_fc_agotado', true );
    update_post_meta( $post_id, '_fc_agotado', $actual === '1' ? '0' : '1' );

    wp_safe_redirect( admin_url( 'edit.php?post_type=arreglo&fc_toggled=1' ) );
    exit;
}

// ── Bulk actions ──
add_filter( 'bulk_actions-edit-arreglo', 'fc_add_bulk_actions' );
function fc_add_bulk_actions( $actions ) {
    $actions['fc_marcar_agotado']    = 'Marcar como Agotado';
    $actions['fc_marcar_disponible'] = 'Marcar como Disponible';
    return $actions;
}

add_filter( 'handle_bulk_actions-edit-arreglo', 'fc_handle_bulk_actions', 10, 3 );
function fc_handle_bulk_actions( $redirect_url, $action, $post_ids ) {
    if ( $action === 'fc_marcar_agotado' ) {
        foreach ( $post_ids as $id ) update_post_meta( $id, '_fc_agotado', '1' );
        $redirect_url = add_query_arg( 'fc_bulk_updated', count( $post_ids ), $redirect_url );
    } elseif ( $action === 'fc_marcar_disponible' ) {
        foreach ( $post_ids as $id ) update_post_meta( $id, '_fc_agotado', '0' );
        $redirect_url = add_query_arg( 'fc_bulk_updated', count( $post_ids ), $redirect_url );
    }
    return $redirect_url;
}

// ── Avisos después de acciones ──
add_action( 'admin_notices', 'fc_admin_notices' );
function fc_admin_notices() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'arreglo' ) return;

    if ( isset( $_GET['fc_bulk_updated'] ) ) {
        $count = intval( $_GET['fc_bulk_updated'] );
        echo '<div class="notice notice-success is-dismissible"><p>' .
             $count . ' arreglo' . ( $count !== 1 ? 's' : '' ) . ' actualizado' . ( $count !== 1 ? 's' : '' ) . ' correctamente.</p></div>';
    }

    if ( isset( $_GET['fc_toggled'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Disponibilidad actualizada.</p></div>';
    }
}
