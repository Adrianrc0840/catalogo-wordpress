<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Horarios de entrega por día de la semana.
 * Clave: número de día JS (0=Domingo, 1=Lunes … 6=Sábado)
 * Lee los bloques activos desde la opción 'fc_horarios' (administrable en
 * Arreglos → Horarios). Si la opción no existe usa fc_default_horarios().
 *
 * Domingo (clave '0') se devuelve con los slots marcados como 'domingo'.
 * El JS decide si mostrarlos o no según si la fecha es una "fecha especial".
 */
function fc_get_schedules() {
    // fc_default_horarios() se define en admin-horarios.php (cargado antes)
    $horarios = get_option( 'fc_horarios', fc_default_horarios() );

    $semana = array_values( array_column(
        array_filter( $horarios, fn( $h ) => ! empty( $h['semana'] ) ),
        'label'
    ) );

    $sabado = array_values( array_column(
        array_filter( $horarios, fn( $h ) => ! empty( $h['sabado'] ) ),
        'label'
    ) );

    $domingo = array_values( array_column(
        array_filter( $horarios, fn( $h ) => ! empty( $h['domingo'] ) ),
        'label'
    ) );

    return [
        '0' => $domingo,  // Domingo — solo en fechas especiales (filtrado en JS)
        '1' => $semana,   // Lunes
        '2' => $semana,   // Martes
        '3' => $semana,   // Miércoles
        '4' => $semana,   // Jueves
        '5' => $semana,   // Viernes
        '6' => $sabado,   // Sábado
    ];
}

/**
 * Devuelve el array de fechas especiales (formato 'DD/MM').
 * Estas fechas habilitan los horarios de domingo aunque el día sea domingo.
 */
function fc_get_fechas_especiales() {
    $fechas = get_option( 'fc_fechas_especiales', [] );
    return is_array( $fechas ) ? $fechas : [];
}
