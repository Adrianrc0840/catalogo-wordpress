<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────
// Register CPT pedido
// ─────────────────────────────────────────────
add_action( 'init', 'fc_register_cpt_pedido' );
function fc_register_cpt_pedido() {
    register_post_type( 'pedido', [
        'labels' => [
            'name'               => 'Pedidos',
            'singular_name'      => 'Pedido',
            'add_new'            => 'Añadir nuevo',
            'add_new_item'       => 'Añadir nuevo pedido',
            'edit_item'          => 'Editar pedido',
            'new_item'           => 'Nuevo pedido',
            'view_item'          => 'Ver pedido',
            'search_items'       => 'Buscar pedidos',
            'not_found'          => 'No se encontraron pedidos',
            'not_found_in_trash' => 'No hay pedidos en la papelera',
            'menu_name'          => 'Pedidos',
        ],
        'public'        => false,
        'show_ui'       => true,
        'show_in_menu'  => false,
        'show_in_rest'  => false,
        'has_archive'   => false,
        'rewrite'       => [ 'slug' => 'pedido' ],
        'supports'      => [ 'title' ],
        'capability_type' => 'post',
        'map_meta_cap'  => true,
    ] );
}

// ─────────────────────────────────────────────
// Register meta fields
// ─────────────────────────────────────────────
add_action( 'init', 'fc_register_pedido_meta' );
function fc_register_pedido_meta() {
    $string_fields = [
        '_fc_pedido_numero',
        '_fc_pedido_token',
        '_fc_pedido_status',
        '_fc_pedido_tipo',
        '_fc_pedido_fecha',
        '_fc_pedido_horario',
        '_fc_pedido_direccion',
        '_fc_pedido_hora_recoleccion',
        '_fc_pedido_cliente_nombre',
        '_fc_pedido_cliente_telefono',
        '_fc_pedido_destinatario',
        '_fc_pedido_mensaje_tarjeta',
        '_fc_pedido_nota',
        '_fc_pedido_arreglo_nombre',
        '_fc_pedido_tamano',
        '_fc_pedido_color',
        '_fc_pedido_nota_floreria',
    ];
    foreach ( $string_fields as $field ) {
        register_post_meta( 'pedido', $field, [
            'single'       => true,
            'type'         => 'string',
            'show_in_rest' => false,
        ] );
    }

    $int_fields = [
        '_fc_pedido_arreglo_id',
        '_fc_pedido_registrado_por',
    ];
    foreach ( $int_fields as $field ) {
        register_post_meta( 'pedido', $field, [
            'single'       => true,
            'type'         => 'integer',
            'show_in_rest' => false,
        ] );
    }

    register_post_meta( 'pedido', '_fc_pedido_historial', [
        'single'       => true,
        'type'         => 'string',
        'show_in_rest' => false,
    ] );
}

// ─────────────────────────────────────────────
// Rewrite rules for client status page
// ─────────────────────────────────────────────
add_action( 'init', 'fc_pedido_rewrite_rules' );
function fc_pedido_rewrite_rules() {
    add_rewrite_rule(
        '^pedido/([A-Za-z0-9\-]+)/?$',
        'index.php?fc_pedido_ref=$matches[1]',
        'top'
    );
}

add_filter( 'query_vars', 'fc_pedido_query_vars' );
function fc_pedido_query_vars( $vars ) {
    $vars[] = 'fc_pedido_ref';
    return $vars;
}

add_filter( 'template_include', 'fc_pedido_template_include' );
function fc_pedido_template_include( $template ) {
    if ( get_query_var( 'fc_pedido_ref' ) ) {
        $custom = FC_PATH . 'templates/single-pedido.php';
        if ( file_exists( $custom ) ) {
            return $custom;
        }
    }
    return $template;
}

// ─────────────────────────────────────────────
// Helper functions
// ─────────────────────────────────────────────

function fc_generar_numero_pedido( $fecha = '' ) {
    // Usar la fecha de entrega del pedido; si no viene, usar hoy como fallback
    if ( $fecha && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) ) {
        $date_key = str_replace( '-', '', $fecha ); // YYYY-MM-DD → YYYYMMDD
    } else {
        $date_key = date( 'Ymd' );
    }
    $option  = 'fc_pedido_counter_' . $date_key;
    $counter = (int) get_option( $option, 0 ) + 1;
    update_option( $option, $counter, false );
    return 'FL-' . $date_key . '-' . str_pad( $counter, 3, '0', STR_PAD_LEFT );
}

function fc_generar_token() {
    $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $token  = '';
    for ( $i = 0; $i < 8; $i++ ) {
        $token .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
    }
    return $token;
}

function fc_pedido_status_label( $status ) {
    $labels = fc_pedido_status_labels();
    return $labels[ $status ] ?? $status;
}

/**
 * Obtiene la URL de la imagen del arreglo asociado a un pedido.
 *
 * Prioridad:
 *   1. Color exacto dentro del tamaño exacto del pedido
 *   2. Imagen del tamaño exacto del pedido (sin color)
 *   3. Tamaño marcado como foto de catálogo
 *   4. Primer tamaño con imagen
 *   5. Featured image del post del arreglo
 */
function fc_get_pedido_arreglo_thumb( $pedido_id ) {
    $arreglo_id    = (int) get_post_meta( $pedido_id, '_fc_pedido_arreglo_id',    true );
    if ( ! $arreglo_id ) return '';

    $tamano_nombre = get_post_meta( $pedido_id, '_fc_pedido_tamano', true );
    $color_nombre  = get_post_meta( $pedido_id, '_fc_pedido_color',  true );
    $tamanos       = get_post_meta( $arreglo_id, '_fc_tamanos',      true );
    if ( ! is_array( $tamanos ) ) $tamanos = [];

    // Limpiar sufijo de precio que el panel antiguo incluía en el nombre
    // ej. "6 rosas ($380)" → "6 rosas"
    $tamano_nombre_clean = trim( preg_replace( '/\s*\(\$[^)]+\)$/', '', $tamano_nombre ?? '' ) );

    $img_url       = '';
    $tamano_img    = '';   // imagen del tamaño exacto (sin color), como fallback

    // 1 y 2 — Buscar tamaño exacto; dentro de él, color exacto
    foreach ( $tamanos as $t ) {
        $t_nombre = trim( $t['nombre'] ?? '' );
        if ( $tamano_nombre_clean && $t_nombre !== $tamano_nombre_clean ) continue;

        // Guardamos la imagen del tamaño como respaldo
        if ( ! $tamano_img && ! empty( $t['imagen_url'] ) ) {
            $tamano_img = $t['imagen_url'];
        }

        // 1. Buscar el color exacto dentro de este tamaño
        if ( $color_nombre && ! empty( $t['colores'] ) && is_array( $t['colores'] ) ) {
            foreach ( $t['colores'] as $c ) {
                if ( ( $c['nombre'] ?? '' ) === $color_nombre && ! empty( $c['imagen_url'] ) ) {
                    $img_url = $c['imagen_url'];
                    break 2;
                }
            }
        }

        // 2. Si no hay color (o no tiene imagen), usar la del tamaño
        if ( ! $img_url && $tamano_img ) {
            $img_url = $tamano_img;
            break;
        }
    }

    // 3. Tamaño marcado como foto de catálogo
    if ( ! $img_url ) {
        foreach ( $tamanos as $t ) {
            if ( ! empty( $t['foto_catalogo'] ) && $t['foto_catalogo'] === '1' && ! empty( $t['imagen_url'] ) ) {
                $img_url = $t['imagen_url'];
                break;
            }
        }
    }

    // 4. Primer tamaño con imagen
    if ( ! $img_url ) {
        foreach ( $tamanos as $t ) {
            if ( ! empty( $t['imagen_url'] ) ) { $img_url = $t['imagen_url']; break; }
        }
    }

    // 5. Featured image del post
    if ( ! $img_url ) {
        $img_url = get_the_post_thumbnail_url( $arreglo_id, 'medium' ) ?: '';
    }

    return $img_url;
}

function fc_pedido_status_labels() {
    return [
        'recibido'          => 'Recibido',
        'en_preparacion'    => 'En preparación',
        'en_camino'         => 'En camino',
        'listo_recoleccion' => 'Listo para recolección',
        'entregado'         => 'Entregado',
    ];
}

// ─────────────────────────────────────────────
// Register florista role
// ─────────────────────────────────────────────
add_action( 'init', 'fc_register_florista_role' );
function fc_register_florista_role() {
    if ( ! get_role( 'florista' ) ) {
        add_role( 'florista', 'Florista', [
            'read'                => true,
            'fc_ver_pedidos'      => true,
            'fc_actualizar_pedidos' => true,
        ] );
    } else {
        // Ensure caps are always present on the role
        $role = get_role( 'florista' );
        $role->add_cap( 'fc_ver_pedidos' );
        $role->add_cap( 'fc_actualizar_pedidos' );
    }
}

// ─────────────────────────────────────────────
// Admin submenu: Pedidos list under Arreglos CPT
// ─────────────────────────────────────────────
add_action( 'admin_menu', 'fc_add_pedidos_submenu' );
function fc_add_pedidos_submenu() {
    add_submenu_page(
        'edit.php?post_type=arreglo',
        'Pedidos',
        'Pedidos',
        'manage_options',
        'fc-pedidos',
        'fc_render_pedidos_admin_page'
    );

    // Enlace al panel de floristas (redirige a la URL pública)
    add_submenu_page(
        'edit.php?post_type=arreglo',
        'Panel Floristas',
        'Panel Floristas ↗',
        'read',
        'fc-panel-florista-link',
        '__return_false'
    );
}

// Redirigir al hacer clic en "Panel Floristas ↗"
add_action( 'admin_init', 'fc_redirect_panel_link' );
function fc_redirect_panel_link() {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'fc-panel-florista-link' ) {
        wp_safe_redirect( home_url( '/panel-florista/' ) );
        exit;
    }
}

add_action( 'admin_enqueue_scripts', 'fc_enqueue_pedidos_admin' );
function fc_enqueue_pedidos_admin( $hook ) {
    if ( $hook !== 'arreglo_page_fc-pedidos' ) return;
    wp_enqueue_style(  'fc-panel', FC_URL . 'assets/css/panel.css', [], FC_VERSION );
    wp_enqueue_script( 'fc-panel', FC_URL . 'assets/js/panel.js',   [], FC_VERSION, true );
    wp_localize_script( 'fc-panel', 'fcPanel', [
        'ajaxurl'   => admin_url( 'admin-ajax.php' ),
        'nonce'     => wp_create_nonce( 'fc_panel_nonce' ),
        'siteurl'   => home_url(),
        'schedules' => fc_get_schedules(),
        'isAdmin'   => true,
    ] );
}

// ─────────────────────────────────────────────
// Admin POST handlers — corren en admin_init,
// antes de que WordPress envíe cualquier output,
// para que wp_safe_redirect() funcione sin warnings.
// ─────────────────────────────────────────────
add_action( 'admin_init', 'fc_handle_pedido_admin_actions' );
function fc_handle_pedido_admin_actions() {
    // Solo aplica a nuestra página
    if (
        ! isset( $_GET['page'] ) ||
        $_GET['page'] !== 'fc-pedidos' ||
        ! current_user_can( 'manage_options' )
    ) {
        return;
    }

    $base = admin_url( 'edit.php?post_type=arreglo&page=fc-pedidos' );

    // ── Mover a papelera ──
    if ( isset( $_POST['fc_admin_delete'], $_POST['pedido_id'] ) && check_admin_referer( 'fc_admin_delete' ) ) {
        $pedido_id = (int) $_POST['pedido_id'];
        if ( $pedido_id && get_post_type( $pedido_id ) === 'pedido' ) {
            wp_trash_post( $pedido_id );
        }
        // Preservar filtros de estado/fecha activos
        $qs = [];
        if ( ! empty( $_GET['status'] ) ) $qs['status'] = sanitize_key( $_GET['status'] );
        if ( ! empty( $_GET['fecha'] ) )  $qs['fecha']  = sanitize_text_field( wp_unslash( $_GET['fecha'] ) );
        $qs['trashed'] = '1';
        wp_safe_redirect( add_query_arg( $qs, $base ) );
        exit;
    }

    // ── Restaurar desde papelera ──
    if ( isset( $_POST['fc_admin_restore'], $_POST['pedido_id'] ) && check_admin_referer( 'fc_admin_restore' ) ) {
        $pedido_id = (int) $_POST['pedido_id'];
        if ( $pedido_id ) {
            wp_untrash_post( $pedido_id );
            wp_update_post( [ 'ID' => $pedido_id, 'post_status' => 'publish' ] );
        }
        wp_safe_redirect( add_query_arg( 'restored', '1', $base ) );
        exit;
    }

    // ── Eliminar permanentemente ──
    if ( isset( $_POST['fc_admin_delete_permanent'], $_POST['pedido_id'] ) && check_admin_referer( 'fc_admin_delete_permanent' ) ) {
        $pedido_id = (int) $_POST['pedido_id'];
        if ( $pedido_id ) {
            wp_delete_post( $pedido_id, true );
        }
        wp_safe_redirect( add_query_arg( [ 'view' => 'trash', 'permanently_deleted' => '1' ], $base ) );
        exit;
    }

    // ── Guardar edición de pedido ──
    if ( isset( $_POST['fc_admin_save_edit'], $_POST['pedido_id'] ) && check_admin_referer( 'fc_admin_save_edit' ) ) {
        $pedido_id = (int) $_POST['pedido_id'];
        if ( $pedido_id && get_post_type( $pedido_id ) === 'pedido' ) {
            $fields = [
                '_fc_pedido_tipo'             => sanitize_key( $_POST['tipo'] ?? 'envio' ),
                '_fc_pedido_fecha'            => sanitize_text_field( $_POST['fecha'] ?? '' ),
                '_fc_pedido_horario'          => sanitize_text_field( $_POST['horario'] ?? '' ),
                '_fc_pedido_direccion'        => sanitize_text_field( $_POST['direccion'] ?? '' ),
                '_fc_pedido_hora_recoleccion' => sanitize_text_field( $_POST['hora_recoleccion'] ?? '' ),
                '_fc_pedido_cliente_nombre'   => sanitize_text_field( $_POST['cliente_nombre'] ?? '' ),
                '_fc_pedido_cliente_telefono' => sanitize_text_field( $_POST['cliente_telefono'] ?? '' ),
                '_fc_pedido_destinatario'     => sanitize_text_field( $_POST['destinatario'] ?? '' ),
                '_fc_pedido_mensaje_tarjeta'  => sanitize_textarea_field( $_POST['mensaje_tarjeta'] ?? '' ),
                '_fc_pedido_nota'             => sanitize_textarea_field( $_POST['nota'] ?? '' ),
                '_fc_pedido_arreglo_nombre'   => sanitize_text_field( $_POST['arreglo_nombre'] ?? '' ),
                '_fc_pedido_tamano'           => sanitize_text_field( $_POST['tamano'] ?? '' ),
                '_fc_pedido_color'            => sanitize_text_field( $_POST['color'] ?? '' ),
            ];
            foreach ( $fields as $key => $val ) {
                update_post_meta( $pedido_id, $key, $val );
            }
        }
        wp_safe_redirect( add_query_arg( 'updated', '1', $base ) );
        exit;
    }

    // ── Acción masiva ──
    if ( isset( $_POST['fc_admin_bulk'] ) && check_admin_referer( 'fc_admin_bulk' ) ) {
        $ids        = array_map( 'intval', (array) ( $_POST['pedido_ids'] ?? [] ) );
        $action     = sanitize_key( $_POST['bulk_action'] ?? '' );
        $new_status = sanitize_key( $_POST['bulk_new_status'] ?? '' );
        $valid_s    = array_keys( fc_pedido_status_labels() );
        $count      = 0;
        $current_u  = wp_get_current_user();

        foreach ( $ids as $id ) {
            if ( ! $id || get_post_type( $id ) !== 'pedido' ) continue;
            if ( $action === 'trash' ) {
                wp_trash_post( $id );
                $count++;
            } elseif ( $action === 'change_status' && in_array( $new_status, $valid_s, true ) ) {
                update_post_meta( $id, '_fc_pedido_status', $new_status );
                $hist   = maybe_unserialize( get_post_meta( $id, '_fc_pedido_historial', true ) );
                $hist   = is_array( $hist ) ? $hist : [];
                $hist[] = [
                    'status'    => $new_status,
                    'user_id'   => get_current_user_id(),
                    'user_name' => $current_u->display_name,
                    'timestamp' => current_time( 'mysql' ),
                ];
                update_post_meta( $id, '_fc_pedido_historial', maybe_serialize( $hist ) );
                $count++;
            }
        }
        wp_safe_redirect( add_query_arg( [ 'bulk_done' => $count, 'bulk_act' => $action ], $base ) );
        exit;
    }
}

function fc_render_pedidos_admin_page() {

    // ── Handle status update from admin ──
    // (permanece aquí porque solo hace echo, no redirect)
    if (
        isset( $_POST['fc_update_status'], $_POST['pedido_id'], $_POST['new_status'] ) &&
        check_admin_referer( 'fc_admin_update_status' )
    ) {
        $pedido_id  = (int) $_POST['pedido_id'];
        $new_status = sanitize_key( $_POST['new_status'] );
        $valid      = array_keys( fc_pedido_status_labels() );

        if ( in_array( $new_status, $valid, true ) && get_post_type( $pedido_id ) === 'pedido' ) {
            $current_user = wp_get_current_user();
            update_post_meta( $pedido_id, '_fc_pedido_status', $new_status );

            $historial   = maybe_unserialize( get_post_meta( $pedido_id, '_fc_pedido_historial', true ) );
            $historial   = is_array( $historial ) ? $historial : [];
            $historial[] = [
                'status'    => $new_status,
                'user_id'   => get_current_user_id(),
                'user_name' => $current_user->display_name,
                'timestamp' => current_time( 'mysql' ),
            ];
            update_post_meta( $pedido_id, '_fc_pedido_historial', maybe_serialize( $historial ) );

            echo '<div class="notice notice-success is-dismissible"><p>Estado actualizado correctamente.</p></div>';
        }
    }

    // Filters
    $view          = isset( $_GET['view'] )   ? sanitize_key( $_GET['view'] )                        : '';
    $filter_status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
    $filter_fecha  = isset( $_GET['fecha'] )  ? sanitize_text_field( wp_unslash( $_GET['fecha'] ) ) : '';
    $filter_q      = isset( $_GET['q'] )      ? sanitize_text_field( wp_unslash( $_GET['q'] ) )     : '';

    // Counts for tab badges
    $count_active = (int) wp_count_posts( 'pedido' )->publish;
    $count_trash  = (int) wp_count_posts( 'pedido' )->trash;

    if ( $filter_q ) {
        // ── Búsqueda global — OR entre todos los campos ──
        $search_fields = [
            '_fc_pedido_numero', '_fc_pedido_cliente_nombre', '_fc_pedido_cliente_telefono',
            '_fc_pedido_destinatario', '_fc_pedido_mensaje_tarjeta', '_fc_pedido_nota',
            '_fc_pedido_arreglo_nombre', '_fc_pedido_direccion', '_fc_pedido_color', '_fc_pedido_tamano',
        ];
        $search_query = [ 'relation' => 'OR' ];
        foreach ( $search_fields as $field ) {
            $search_query[] = [ 'key' => $field, 'value' => $filter_q, 'compare' => 'LIKE' ];
        }
        $raw = get_posts( [
            'post_type'      => 'pedido',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'meta_query'     => $search_query,
        ] );
        // Ordenar en PHP por fecha ASC, entregados al final
        usort( $raw, function ( $a, $b ) {
            $sa = get_post_meta( $a->ID, '_fc_pedido_status', true );
            $sb = get_post_meta( $b->ID, '_fc_pedido_status', true );
            $ae = ( $sa === 'entregado' ) ? 1 : 0;
            $be = ( $sb === 'entregado' ) ? 1 : 0;
            if ( $ae !== $be ) return $ae - $be;
            $fa = get_post_meta( $a->ID, '_fc_pedido_fecha', true );
            $fb = get_post_meta( $b->ID, '_fc_pedido_fecha', true );
            return strcmp( $fa, $fb );
        } );
        $pedidos = $raw;
    } else {
        // ── Vista normal ──
        $base_args = [
            'post_type'      => 'pedido',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'meta_key'       => '_fc_pedido_fecha',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
        ];

        $meta_conditions = [];

        if ( $filter_fecha ) {
            $meta_conditions[] = [
                'key'     => '_fc_pedido_fecha',
                'value'   => $filter_fecha,
                'compare' => '=',
            ];
        }

        if ( $filter_status && array_key_exists( $filter_status, fc_pedido_status_labels() ) ) {
            $meta_conditions[] = [ 'key' => '_fc_pedido_status', 'value' => $filter_status ];
            $args              = $base_args;
            $args['meta_query'] = array_merge( [ 'relation' => 'AND' ], $meta_conditions );
            $pedidos = get_posts( $args );
        } else {
            $excluir = array_merge( [ 'relation' => 'AND' ], $meta_conditions, [ [
                'key' => '_fc_pedido_status', 'value' => 'entregado', 'compare' => '!=',
            ] ] );
            $solo_entregado = array_merge( [ 'relation' => 'AND' ], $meta_conditions, [ [
                'key' => '_fc_pedido_status', 'value' => 'entregado',
            ] ] );
            $args1 = $base_args; $args1['meta_query'] = $excluir;
            $args2 = $base_args; $args2['meta_query'] = $solo_entregado;
            $pedidos = array_merge( get_posts( $args1 ), get_posts( $args2 ) );
        }
    }
    $labels  = fc_pedido_status_labels();

    $status_colors = [
        'recibido'          => '#3b82f6',
        'en_preparacion'    => '#f59e0b',
        'en_camino'         => '#8b5cf6',
        'listo_recoleccion' => '#06b6d4',
        'entregado'         => '#10b981',
    ];

    // ── Inline edit form data ──
    $edit_id     = isset( $_GET['edit_id'] ) ? (int) $_GET['edit_id'] : 0;
    $edit_pedido = ( $edit_id && get_post_type( $edit_id ) === 'pedido' ) ? get_post( $edit_id ) : null;
    $base_url_admin = admin_url( 'edit.php?post_type=arreglo&page=fc-pedidos' );
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Pedidos</h1>
        <?php if ( $view !== 'trash' ) : ?>
        <button class="page-title-action" id="fc-btn-new-pedido">+ Nuevo pedido</button>
        <?php endif; ?>
        <hr class="wp-header-end">

        <?php if ( isset( $_GET['updated'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Pedido actualizado correctamente.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['trashed'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Pedido movido a la papelera.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['restored'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Pedido restaurado correctamente.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['permanently_deleted'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Pedido eliminado permanentemente.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['bulk_done'] ) ) :
            $bd  = (int) $_GET['bulk_done'];
            $bact = sanitize_key( $_GET['bulk_act'] ?? '' );
            $bmsg = $bact === 'trash'
                ? "$bd pedido(s) movido(s) a la papelera."
                : "$bd pedido(s) actualizados.";
        ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $bmsg ); ?></p></div>
        <?php endif; ?>

        <!-- View tabs: Activos | Papelera -->
        <ul class="subsubsub" style="margin-bottom:12px;">
            <li>
                <a href="<?php echo esc_url( $base_url_admin ); ?>"
                   class="<?php echo $view !== 'trash' ? 'current' : ''; ?>">
                    Activos <span class="count">(<?php echo $count_active; ?>)</span>
                </a> |
            </li>
            <li>
                <a href="<?php echo esc_url( add_query_arg( 'view', 'trash', $base_url_admin ) ); ?>"
                   class="<?php echo $view === 'trash' ? 'current' : ''; ?>">
                    🗑 Papelera <span class="count">(<?php echo $count_trash; ?>)</span>
                </a>
            </li>
        </ul>

        <?php if ( $view === 'trash' ) :
            // ── PAPELERA ──
            $trashed_posts = get_posts( [
                'post_type'      => 'pedido',
                'post_status'    => 'trash',
                'posts_per_page' => 200,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ] );
            if ( empty( $trashed_posts ) ) : ?>
            <p>La papelera está vacía.</p>
            <?php else : ?>
            <table class="wp-list-table widefat striped" style="table-layout:auto;">
                <thead>
                    <tr>
                        <th>Número</th>
                        <th>Cliente</th>
                        <th>Arreglo</th>
                        <th>Fecha entrega</th>
                        <th>Eliminado el</th>
                        <th style="width:240px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $trashed_posts as $tp ) :
                        $t_num    = get_post_meta( $tp->ID, '_fc_pedido_numero',         true );
                        $t_cli    = get_post_meta( $tp->ID, '_fc_pedido_cliente_nombre', true );
                        $t_arr    = get_post_meta( $tp->ID, '_fc_pedido_arreglo_nombre', true );
                        $t_fecha  = get_post_meta( $tp->ID, '_fc_pedido_fecha',          true );
                        $t_del    = get_the_date( 'd/m/Y H:i', $tp );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $t_num ); ?></strong></td>
                        <td><?php echo esc_html( $t_cli ); ?></td>
                        <td><?php echo esc_html( $t_arr ); ?></td>
                        <td><?php echo esc_html( $t_fecha ); ?></td>
                        <td><?php echo esc_html( $t_del ); ?></td>
                        <td>
                            <!-- Restaurar -->
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'fc_admin_restore' ); ?>
                                <input type="hidden" name="pedido_id" value="<?php echo esc_attr( $tp->ID ); ?>" />
                                <button type="submit" name="fc_admin_restore" class="button button-small" style="color:#0a7a0a;border-color:#0a7a0a;">&#8635; Restaurar</button>
                            </form>
                            <!-- Eliminar permanentemente -->
                            <form method="post" style="display:inline;margin-left:6px;"
                                  onsubmit="return confirm('¿Eliminar permanentemente el pedido <?php echo esc_js( $t_num ); ?>?\nEsta acción NO se puede deshacer.')">
                                <?php wp_nonce_field( 'fc_admin_delete_permanent' ); ?>
                                <input type="hidden" name="pedido_id" value="<?php echo esc_attr( $tp->ID ); ?>" />
                                <button type="submit" name="fc_admin_delete_permanent" class="button button-small" style="color:#c0392b;border-color:#c0392b;">✕ Eliminar permanentemente</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

        <?php else : // ── VISTA NORMAL ── ?>

        <?php if ( $edit_pedido ) :
            $e = $edit_id;
            $ef = [
                'tipo'             => get_post_meta( $e, '_fc_pedido_tipo',             true ),
                'fecha'            => get_post_meta( $e, '_fc_pedido_fecha',            true ),
                'horario'          => get_post_meta( $e, '_fc_pedido_horario',          true ),
                'direccion'        => get_post_meta( $e, '_fc_pedido_direccion',        true ),
                'hora_recoleccion' => get_post_meta( $e, '_fc_pedido_hora_recoleccion', true ),
                'cliente_nombre'   => get_post_meta( $e, '_fc_pedido_cliente_nombre',   true ),
                'cliente_telefono' => get_post_meta( $e, '_fc_pedido_cliente_telefono', true ),
                'destinatario'     => get_post_meta( $e, '_fc_pedido_destinatario',     true ),
                'mensaje_tarjeta'  => get_post_meta( $e, '_fc_pedido_mensaje_tarjeta',  true ),
                'nota'             => get_post_meta( $e, '_fc_pedido_nota',             true ),
                'arreglo_nombre'   => get_post_meta( $e, '_fc_pedido_arreglo_nombre',   true ),
                'tamano'           => get_post_meta( $e, '_fc_pedido_tamano',           true ),
                'color'            => get_post_meta( $e, '_fc_pedido_color',            true ),
                'numero'           => get_post_meta( $e, '_fc_pedido_numero',           true ),
            ];
            $cancel_url = remove_query_arg( 'edit_id' );
        ?>
        <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:24px;margin-bottom:24px;max-width:860px;">
            <h2 style="margin-top:0;">Editando pedido: <strong><?php echo esc_html( $ef['numero'] ); ?></strong></h2>
            <form method="post">
                <?php wp_nonce_field( 'fc_admin_save_edit' ); ?>
                <input type="hidden" name="pedido_id" value="<?php echo esc_attr( $e ); ?>" />
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

                    <div><label style="font-weight:600;display:block;margin-bottom:4px;">Arreglo</label>
                    <input type="text" name="arreglo_nombre" value="<?php echo esc_attr( $ef['arreglo_nombre'] ); ?>" style="width:100%;padding:7px 10px;border:1px solid #c3c4c7;border-radius:4px;" /></div>

                    <div><label style="font-weight:600;display:block;margin-bottom:4px;">Tamaño</label>
                    <input type="text" name="tamano" value="<?php echo esc_attr( $ef['tamano'] ); ?>" style="width:100%;padding:7px 10px;border:1px solid #c3c4c7;border-radius:4px;" /></div>

                    <div><label style="font-weight:600;display:block;margin-bottom:4px;">Color</label>
                    <input type="text" name="color" value="<?php echo esc_attr( $ef['color'] ); ?>" style="width:100%;padding:7px 10px;border:1px solid #c3c4c7;border-radius:4px;" /></div>

                    <div><label style="font-weight:600;display:block;margin-bottom:4px;">Tipo de entrega</label>
                    <select name="tipo" style="width:100%;padding:7px 10px;border:1px solid #c3c4c7;border-radius:4px;">
                        <option value="envio" <?php selected( $ef['tipo'], 'envio' ); ?>>Envío a domicilio</option>
                        <option value="recoleccion" <?php selected( $ef['tipo'], 'recoleccion' ); ?>>Recolección en tienda</option>
                    </select></div>

                    <div><label style="font-weight:600;display:block;margin-bottom:4px;">Fecha de entrega</label>
                    <input type="date" name="fecha" value="<?php echo esc_attr( $ef['fecha'] ); ?>" style="width:100%;padding:7px 10px;border:1px solid #c3c4c7;border-radius:4px;" /></div>

                    <div><label style="font-weight:600;display:block;margin-bottom:4px;">Horario de entrega</label>
                    <input type="text" name="horario" value="<?php echo esc_attr( $ef['horario'] ); ?>" style="width:100%;padding:7px 10px;border:1px solid #c3c4c7;border-radius:4px;" /></div>

                    <div><label style="font-weight:600;display:block;margin-bottom:4px;">Hora de recolección</label>
                    <input type="time" name="hora_recoleccion" value="<?php echo esc_attr( $ef['hora_recoleccion'] ); ?>" style="width:100%;padding:7px 10px;border:1px solid #c3c4c7;border-radius:4px;" /></div>

                    <div><label style="font-weight:600;display:block;margin-bottom:4px;">Dirección</label>
                    <input type="text" name="direccion" value="<?php echo esc_attr( $ef['direccion'] ); ?>" style="width:100%;padding:7px 10px;border:1px solid #c3c4c7;border-radius:4px;" /></div>

                    <div><label style="font-weight:600;display:block;margin-bottom:4px;">Nombre del cliente</label>
                    <input type="text" name="cliente_nombre" value="<?php echo esc_attr( $ef['cliente_nombre'] ); ?>" style="width:100%;padding:7px 10px;border:1px solid #c3c4c7;border-radius:4px;" /></div>

                    <div><label style="font-weight:600;display:block;margin-bottom:4px;">Teléfono del cliente</label>
                    <input type="tel" name="cliente_telefono" value="<?php echo esc_attr( $ef['cliente_telefono'] ); ?>" inputmode="numeric" style="width:100%;padding:7px 10px;border:1px solid #c3c4c7;border-radius:4px;" /></div>

                    <div><label style="font-weight:600;display:block;margin-bottom:4px;">Destinatario</label>
                    <input type="text" name="destinatario" value="<?php echo esc_attr( $ef['destinatario'] ); ?>" style="width:100%;padding:7px 10px;border:1px solid #c3c4c7;border-radius:4px;" /></div>

                    <div><label style="font-weight:600;display:block;margin-bottom:4px;">Mensaje de tarjeta</label>
                    <textarea name="mensaje_tarjeta" rows="2" style="width:100%;padding:7px 10px;border:1px solid #c3c4c7;border-radius:4px;"><?php echo esc_textarea( $ef['mensaje_tarjeta'] ); ?></textarea></div>

                    <div style="grid-column:1/-1;"><label style="font-weight:600;display:block;margin-bottom:4px;">Nota especial del cliente</label>
                    <textarea name="nota" rows="2" style="width:100%;padding:7px 10px;border:1px solid #c3c4c7;border-radius:4px;"><?php echo esc_textarea( $ef['nota'] ); ?></textarea></div>

                </div>
                <div style="margin-top:20px;display:flex;gap:10px;">
                    <button type="submit" name="fc_admin_save_edit" class="button button-primary">Guardar cambios</button>
                    <a href="<?php echo esc_url( $cancel_url ); ?>" class="button">Cancelar</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php
        $base_url  = admin_url( 'edit.php?post_type=arreglo&page=fc-pedidos' );
        $fecha_qs  = $filter_fecha  ? '&fecha='  . rawurlencode( $filter_fecha )  : '';
        $status_qs = $filter_status ? '&status=' . $filter_status : '';
        ?>

        <!-- Search bar -->
        <div style="margin-bottom:12px;">
            <form method="get" style="display:flex;align-items:center;gap:6px;max-width:480px;">
                <input type="hidden" name="post_type" value="arreglo" />
                <input type="hidden" name="page" value="fc-pedidos" />
                <input type="search" name="q" value="<?php echo esc_attr( $filter_q ); ?>"
                       placeholder="Buscar por número, nombre, teléfono, tarjeta..."
                       style="flex:1;padding:7px 10px;border:1px solid #c3c4c7;border-radius:3px;font-size:13px;" />
                <button type="submit" class="button">Buscar</button>
                <?php if ( $filter_q ) : ?>
                <a href="<?php echo esc_url( $base_url ); ?>" class="button">&#215; Limpiar</a>
                <?php endif; ?>
            </form>
            <?php if ( $filter_q ) : ?>
            <p style="margin:6px 0 0;font-size:13px;color:#666;">
                Resultados para: <strong><?php echo esc_html( $filter_q ); ?></strong>
            </p>
            <?php endif; ?>
        </div>

        <!-- Status & date filters (hidden during search) -->
        <?php if ( ! $filter_q ) : ?>
        <div style="margin-bottom:16px;display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <strong>Estado:</strong>
                <a href="<?php echo esc_url( $base_url . $fecha_qs ); ?>" class="button <?php echo ! $filter_status ? 'button-primary' : ''; ?>">Todos</a>
                <?php foreach ( $labels as $key => $label ) : ?>
                <a href="<?php echo esc_url( $base_url . '&status=' . $key . $fecha_qs ); ?>" class="button <?php echo $filter_status === $key ? 'button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </div>

            <div style="display:flex;align-items:center;gap:6px;margin-left:12px;">
                <strong>Fecha entrega:</strong>
                <form method="get" style="display:flex;align-items:center;gap:6px;margin:0;">
                    <input type="hidden" name="post_type" value="arreglo" />
                    <input type="hidden" name="page" value="fc-pedidos" />
                    <?php if ( $filter_status ) : ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr( $filter_status ); ?>" />
                    <?php endif; ?>
                    <input type="date" name="fecha" value="<?php echo esc_attr( $filter_fecha ); ?>" style="padding:5px 8px;border:1px solid #c3c4c7;border-radius:3px;font-size:13px;" />
                    <button type="submit" class="button">Filtrar</button>
                </form>
                <?php if ( $filter_fecha ) : ?>
                <a href="<?php echo esc_url( $base_url . $status_qs ); ?>" class="button">Todos los días</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( empty( $pedidos ) ) : ?>
        <p>No se encontraron pedidos.</p>
        <?php else : ?>

        <!-- ── Bulk action bar ── -->
        <form method="post" id="fc-bulk-form" style="margin-bottom:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <?php wp_nonce_field( 'fc_admin_bulk' ); ?>
            <select name="bulk_action" id="fc-bulk-action" style="font-size:13px;padding:5px 8px;">
                <option value="">— Acción masiva —</option>
                <option value="trash">🗑 Mover a papelera</option>
                <option value="change_status">✎ Cambiar estado a...</option>
            </select>
            <select name="bulk_new_status" id="fc-bulk-status" style="font-size:13px;padding:5px 8px;display:none;">
                <?php foreach ( $labels as $k => $l ) : ?>
                <option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="fc_admin_bulk" class="button button-primary" id="fc-bulk-apply" disabled>Aplicar</button>
            <span id="fc-bulk-count" style="font-size:13px;color:#666;"></span>
        </form>

        <table class="wp-list-table widefat striped" style="table-layout:auto;">
            <thead>
                <tr>
                    <th style="width:32px;"><input type="checkbox" id="fc-select-all" title="Seleccionar todos" /></th>
                    <th style="width:150px;">Número</th>
                    <th style="width:130px;">Estado</th>
                    <th>Cliente</th>
                    <th style="width:110px;">Teléfono</th>
                    <th>Arreglo</th>
                    <th style="width:95px;">Tamaño</th>
                    <th style="width:75px;">Color</th>
                    <th style="width:85px;">Tipo</th>
                    <th style="width:105px;">Fecha entrega</th>
                    <th style="width:105px;">Registrado</th>
                    <th style="width:210px;">Cambiar estado</th>
                    <th style="width:80px;">Ver / 🖨</th>
                    <th style="width:150px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $pedidos as $pedido ) :
                    $num      = get_post_meta( $pedido->ID, '_fc_pedido_numero',           true );
                    $status   = get_post_meta( $pedido->ID, '_fc_pedido_status',           true );
                    $cliente  = get_post_meta( $pedido->ID, '_fc_pedido_cliente_nombre',   true );
                    $tel      = get_post_meta( $pedido->ID, '_fc_pedido_cliente_telefono', true );
                    $arreglo  = get_post_meta( $pedido->ID, '_fc_pedido_arreglo_nombre',   true );
                    $tamano   = get_post_meta( $pedido->ID, '_fc_pedido_tamano',           true );
                    $color    = get_post_meta( $pedido->ID, '_fc_pedido_color',            true );
                    $tipo     = get_post_meta( $pedido->ID, '_fc_pedido_tipo',             true );
                    $fecha    = get_post_meta( $pedido->ID, '_fc_pedido_fecha',            true );
                    $color_badge = $status_colors[ $status ] ?? '#999';
                    $client_url  = home_url( '/pedido/' . $num );
                    $edit_url    = add_query_arg( 'edit_id', $pedido->ID, $base_url_admin );
                ?>
                <tr>
                    <td><input type="checkbox" name="pedido_ids[]" value="<?php echo esc_attr( $pedido->ID ); ?>"
                               form="fc-bulk-form" class="fc-row-cb" /></td>
                    <td><strong><?php echo esc_html( $num ); ?></strong></td>
                    <td>
                        <span style="background:<?php echo esc_attr( $color_badge ); ?>;color:#fff;padding:3px 8px;border-radius:20px;font-size:12px;white-space:nowrap;">
                            <?php echo esc_html( fc_pedido_status_label( $status ) ); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html( $cliente ); ?></td>
                    <td style="white-space:nowrap;"><?php echo esc_html( $tel ); ?></td>
                    <td><?php echo esc_html( $arreglo ); ?></td>
                    <td><?php echo esc_html( $tamano ); ?></td>
                    <td><?php echo esc_html( $color ); ?></td>
                    <td style="white-space:nowrap;"><?php echo esc_html( $tipo === 'envio' ? 'Envío' : 'Recolección' ); ?></td>
                    <td style="white-space:nowrap;"><?php echo esc_html( $fecha ); ?></td>
                    <td style="white-space:nowrap;"><?php echo esc_html( get_the_date( 'd/m/Y H:i', $pedido ) ); ?></td>
                    <td>
                        <form method="post" style="display:flex;gap:4px;align-items:center;flex-wrap:nowrap;">
                            <?php wp_nonce_field( 'fc_admin_update_status' ); ?>
                            <input type="hidden" name="pedido_id" value="<?php echo esc_attr( $pedido->ID ); ?>" />
                            <select name="new_status" style="font-size:12px;min-width:130px;">
                                <?php foreach ( $labels as $k => $l ) :
                                    if ( $tipo === 'envio'       && $k === 'listo_recoleccion' ) continue;
                                    if ( $tipo === 'recoleccion' && $k === 'en_camino'         ) continue;
                                ?>
                                <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $status, $k ); ?>><?php echo esc_html( $l ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="fc_update_status" class="button button-small" style="white-space:nowrap;">Guardar</button>
                        </form>
                    </td>
                    <td style="white-space:nowrap;">
                        <a href="<?php echo esc_url( $client_url ); ?>" target="_blank" class="button button-small">Ver</a>
                        <a href="<?php echo esc_url( add_query_arg( 'fc_print_pedido', $pedido->ID, home_url( '/' ) ) ); ?>"
                           target="_blank" class="button button-small" style="margin-left:4px;">🖨</a>
                    </td>
                    <td style="white-space:nowrap;">
                        <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">Editar</a>
                        <form method="post" style="display:inline-block;margin-left:4px;"
                              onsubmit="return confirm('¿Mover el pedido <?php echo esc_js( $num ); ?> a la papelera?')">
                            <?php wp_nonce_field( 'fc_admin_delete' ); ?>
                            <input type="hidden" name="pedido_id" value="<?php echo esc_attr( $pedido->ID ); ?>" />
                            <button type="submit" name="fc_admin_delete" class="button button-small" style="color:#b45309;border-color:#b45309;">🗑</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php endif; // end if ($view === 'trash') / else ?>
    </div>

    <!-- Modal nuevo pedido (visibility controlada por clase .open vía panel.js) -->
    <div class="fc-modal-overlay" id="fc-modal-overlay">
        <div class="fc-modal" role="dialog" style="background:#fff;border-radius:12px;width:560px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div class="fc-modal-header" style="display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid #eee;">
                <h2 style="margin:0;font-size:18px;">Nuevo pedido</h2>
                <button class="fc-modal-close" id="fc-modal-close" style="background:none;border:none;font-size:24px;cursor:pointer;color:#666;">&times;</button>
            </div>
            <div class="fc-modal-body" style="padding:24px;">
                <form id="fc-new-pedido-form">
                    <div class="fc-form-group"><label>Arreglo</label>
                        <div class="fc-autocomplete-wrap">
                            <input type="text" id="fc-arreglo-search" placeholder="Buscar por nombre..." autocomplete="off" style="width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;" />
                            <input type="hidden" id="fc-arreglo-id" /><input type="hidden" id="fc-arreglo-nombre" />
                            <div class="fc-autocomplete-dropdown" id="fc-arreglo-dropdown"></div>
                        </div>
                    </div>
                    <div class="fc-form-group"><label>Tamaño</label>
                        <select id="fc-tamano-select" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;"><option value="">-- Selecciona tamaño --</option></select>
                    </div>
                    <div class="fc-form-group"><label>Color</label>
                        <select id="fc-color-select" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;"><option value="">-- Selecciona color --</option></select>
                    </div>
                    <div class="fc-form-group"><label>Tipo de entrega</label>
                        <div class="fc-tipo-toggle">
                            <button type="button" class="fc-tipo-option active" data-tipo="envio">Envío a domicilio</button>
                            <button type="button" class="fc-tipo-option" data-tipo="recoleccion">Recolección en tienda</button>
                        </div>
                    </div>
                    <div class="fc-form-group"><label>Fecha de entrega</label>
                        <input type="date" id="fc-modal-fecha" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;" />
                    </div>
                    <div id="fc-modal-envio-section">
                        <div class="fc-form-group"><label>Horario de entrega</label>
                            <select id="fc-modal-horario" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;">
                                <option value="">-- Selecciona fecha primero --</option>
                            </select>
                        </div>
                        <div class="fc-form-group"><label>Dirección</label>
                            <input type="text" id="fc-modal-direccion" placeholder="Calle, número, colonia..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;" />
                        </div>
                    </div>
                    <div id="fc-modal-recoleccion-section" style="display:none;">
                        <div class="fc-form-group"><label>Hora de recolección</label>
                            <input type="time" id="fc-modal-hora-recoleccion" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;" />
                        </div>
                    </div>
                    <div class="fc-form-group"><label>Nombre del cliente</label>
                        <input type="text" id="fc-modal-cliente-nombre" placeholder="Nombre completo" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;" />
                    </div>
                    <div class="fc-form-group"><label>Teléfono</label>
                        <input type="tel" id="fc-modal-cliente-telefono" placeholder="10 dígitos"
                               inputmode="numeric" pattern="[0-9]*" maxlength="15"
                               style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;" />
                    </div>
                    <div class="fc-form-group"><label>Nombre del destinatario</label>
                        <input type="text" id="fc-modal-destinatario" placeholder="¿A quién va dirigido?" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;" />
                    </div>
                    <div class="fc-form-group"><label>Mensaje de tarjeta</label>
                        <textarea id="fc-modal-mensaje-tarjeta" rows="2" placeholder="Mensaje para la tarjeta..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;resize:vertical;"></textarea>
                    </div>
                    <div class="fc-form-group"><label>Nota especial</label>
                        <textarea id="fc-modal-nota" rows="2" placeholder="Indicaciones especiales..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;resize:vertical;"></textarea>
                    </div>
                </form>
            </div>
            <div class="fc-modal-footer" style="padding:16px 24px;border-top:1px solid #eee;">
                <div class="fc-success-box" id="fc-pedido-success" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:16px;margin-bottom:16px;">
                    <h3 style="margin:0 0 8px;color:#166534;">¡Pedido registrado!</h3>
                    <p style="margin:0 0 4px;">Número: <strong id="fc-pedido-num-result"></strong></p>
                    <p style="margin:0 0 8px;">Link para el cliente:</p>
                    <div id="fc-pedido-link" style="font-size:13px;word-break:break-all;background:#fff;padding:8px;border-radius:4px;border:1px solid #ddd;margin-bottom:8px;"></div>
                    <button class="button" id="fc-copy-link-btn">Copiar link</button>
                </div>
                <button type="submit" form="fc-new-pedido-form" class="button button-primary" id="fc-submit-pedido" style="width:100%;padding:10px;">Registrar pedido</button>
            </div>
        </div>
    </div>

    <script>
    // El modal de nuevo pedido lo maneja panel.js (initNewOrderModal),
    // que ya corre en el admin tras mover la llamada fuera del guard isPanel.

    // ── Bulk actions JS ──
    (function() {
        var selectAll  = document.getElementById('fc-select-all');
        var bulkAction = document.getElementById('fc-bulk-action');
        var bulkStatus = document.getElementById('fc-bulk-status');
        var bulkApply  = document.getElementById('fc-bulk-apply');
        var bulkCount  = document.getElementById('fc-bulk-count');
        var bulkForm   = document.getElementById('fc-bulk-form');

        if (!selectAll || !bulkForm) return;

        function getChecked() {
            return Array.from(document.querySelectorAll('.fc-row-cb:checked'));
        }

        function updateBulkUI() {
            var checked = getChecked();
            var n = checked.length;
            if (bulkCount) bulkCount.textContent = n > 0 ? n + ' seleccionado' + (n > 1 ? 's' : '') : '';
            if (bulkApply) bulkApply.disabled = (n === 0 || !bulkAction.value);
        }

        // Select-all toggle
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.fc-row-cb').forEach(function(cb) {
                cb.checked = selectAll.checked;
            });
            updateBulkUI();
        });

        // Individual checkbox — also update select-all indeterminate state
        document.querySelectorAll('.fc-row-cb').forEach(function(cb) {
            cb.addEventListener('change', function() {
                var all  = document.querySelectorAll('.fc-row-cb');
                var done = getChecked();
                selectAll.indeterminate = done.length > 0 && done.length < all.length;
                selectAll.checked = done.length === all.length && all.length > 0;
                updateBulkUI();
            });
        });

        // Show/hide status select based on chosen action
        if (bulkAction) {
            bulkAction.addEventListener('change', function() {
                if (bulkStatus) {
                    bulkStatus.style.display = (this.value === 'change_status') ? '' : 'none';
                }
                updateBulkUI();
            });
        }

        // Confirm before trashing
        if (bulkForm) {
            bulkForm.addEventListener('submit', function(e) {
                var action = bulkAction ? bulkAction.value : '';
                var n = getChecked().length;
                if (n === 0) { e.preventDefault(); return; }
                if (action === 'trash') {
                    if (!confirm('¿Mover ' + n + ' pedido' + (n > 1 ? 's' : '') + ' a la papelera?')) {
                        e.preventDefault();
                    }
                }
            });
        }
    })();
    </script>
    <?php
}
