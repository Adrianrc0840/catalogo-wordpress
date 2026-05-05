<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Horarios de entrega por día de la semana.
 * Clave: número de día JS (0=Domingo, 1=Lunes … 6=Sábado)
 * Valor: array de bloques horarios disponibles.
 * Array vacío = día cerrado (sin entregas).
 *
 * PENDIENTE: reemplazar con los horarios reales de la florería.
 */
function fc_get_schedules() {
    return [
        '0' => [],                                              // Domingo  — cerrado
        '1' => [ '9:00am - 11:00am', '11:00am - 1:00pm', '1:00pm - 3:00pm', '3:00pm - 5:00pm' ], // Lunes
        '2' => [ '9:00am - 11:00am', '11:00am - 1:00pm', '1:00pm - 3:00pm', '3:00pm - 5:00pm' ], // Martes
        '3' => [ '9:00am - 11:00am', '11:00am - 1:00pm', '1:00pm - 3:00pm', '3:00pm - 5:00pm' ], // Miércoles
        '4' => [ '9:00am - 11:00am', '11:00am - 1:00pm', '1:00pm - 3:00pm', '3:00pm - 5:00pm' ], // Jueves
        '5' => [ '9:00am - 11:00am', '11:00am - 1:00pm', '1:00pm - 3:00pm', '3:00pm - 5:00pm' ], // Viernes
        '6' => [ '9:00am - 11:00am', '11:00am - 1:00pm' ],    // Sábado  — horario reducido
    ];
}
