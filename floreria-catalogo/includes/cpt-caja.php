<?php
/**
 * CPT fc_caja — Sesiones de caja del PDV
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────
// Registrar post type fc_caja
// ─────────────────────────────────────────────
add_action( 'init', 'fc_register_cpt_caja' );
function fc_register_cpt_caja() {
    register_post_type( 'fc_caja', [
        'public'       => false,
        'label'        => 'Cajas',
        'supports'     => [ 'title' ],
        'show_in_rest' => false,
    ] );
}

// ─────────────────────────────────────────────
// Obtener cajas abiertas (puede haber más de una si se olvidó cerrar)
// ─────────────────────────────────────────────
function fc_get_cajas_abiertas() {
    return get_posts( [
        'post_type'      => 'fc_caja',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [ [
            'key'   => '_fc_caja_status',
            'value' => 'abierta',
        ] ],
    ] );
}

// ─────────────────────────────────────────────
// Obtener ventas PDV asociadas a una caja
// ─────────────────────────────────────────────
function fc_get_ventas_caja( $caja_id ) {
    return get_posts( [
        'post_type'      => 'pedido',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [ [
            'key'   => '_fc_pedido_caja_id',
            'value' => (int) $caja_id,
        ] ],
    ] );
}

// ─────────────────────────────────────────────
// Construir array de datos de caja para AJAX
// ─────────────────────────────────────────────
function fc_build_caja_data( $caja_id ) {
    $mov_raw  = get_post_meta( $caja_id, '_fc_caja_movimientos', true );
    $movs     = is_string( $mov_raw ) ? json_decode( $mov_raw, true ) : [];
    $movs     = is_array( $movs ) ? $movs : [];

    $saldo_inicial  = (float) get_post_meta( $caja_id, '_fc_caja_saldo_inicial', true );
    $saldo_final    = (float) get_post_meta( $caja_id, '_fc_caja_saldo_final',   true );
    $fecha_apertura = get_post_meta( $caja_id, '_fc_caja_apertura', true );
    $fecha_cierre   = get_post_meta( $caja_id, '_fc_caja_cierre',   true );
    $status         = get_post_meta( $caja_id, '_fc_caja_status',   true );

    // Sumar ventas por forma de pago
    $ventas         = fc_get_ventas_caja( $caja_id );
    $total_efectivo = 0.0;
    $total_tarjeta  = 0.0;
    $total_otro     = 0.0;
    $ventas_data    = [];

    foreach ( $ventas as $v ) {
        $monto = (float) get_post_meta( $v->ID, '_fc_pedido_monto',      true );
        $forma = get_post_meta( $v->ID, '_fc_pedido_forma_pago', true );
        $ts    = get_post_meta( $v->ID, '_fc_pedido_fecha_venta', true ) ?: $v->post_date;
        if ( $forma === 'efectivo' )    $total_efectivo += $monto;
        elseif ( $forma === 'tarjeta' ) $total_tarjeta  += $monto;
        else                            $total_otro      += $monto;
        $ventas_data[] = [
            'pedido_id' => $v->ID,
            'numero'    => get_post_meta( $v->ID, '_fc_pedido_numero', true ),
            'monto'     => $monto,
            'forma_pago'=> $forma,
            'timestamp' => $ts,
        ];
    }

    // Sumar movimientos manuales
    $total_entradas = 0.0;
    $total_salidas  = 0.0;
    foreach ( $movs as $m ) {
        if ( ( $m['tipo'] ?? '' ) === 'entrada' ) $total_entradas += (float) ( $m['monto'] ?? 0 );
        if ( ( $m['tipo'] ?? '' ) === 'salida' )  $total_salidas  += (float) ( $m['monto'] ?? 0 );
    }

    $saldo_actual = $saldo_inicial + $total_efectivo + $total_entradas - $total_salidas;

    return [
        'id'             => $caja_id,
        'status'         => $status,
        'fecha_apertura' => $fecha_apertura,
        'fecha_cierre'   => $fecha_cierre,
        'saldo_inicial'  => $saldo_inicial,
        'saldo_actual'   => round( $saldo_actual, 2 ),
        'saldo_final'    => $saldo_final,
        'total_ventas'   => round( $total_efectivo + $total_tarjeta + $total_otro, 2 ),
        'total_efectivo' => round( $total_efectivo, 2 ),
        'total_tarjeta'  => round( $total_tarjeta,  2 ),
        'total_otro'     => round( $total_otro,     2 ),
        'total_entradas' => round( $total_entradas, 2 ),
        'total_salidas'  => round( $total_salidas,  2 ),
        'count_ventas'   => count( $ventas ),
        'movimientos'    => $movs,
        'ventas'         => $ventas_data,
    ];
}
