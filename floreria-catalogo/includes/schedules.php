<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Horarios de entrega por día de la semana.
 * Clave: número de día JS (0=Domingo, 1=Lunes … 6=Sábado)
 * Sábado: último bloque disponible es 3:00pm – 5:00pm.
 * Domingo: cerrado.
 */
function fc_get_schedules() {
    $bloques_semana = [
        '10:00am – 12:00pm',
        '11:00am – 1:00pm',
        '12:00pm – 2:00pm',
        '1:00pm – 3:00pm',
        '2:00pm – 4:00pm',
        '3:00pm – 5:00pm',
        '4:00pm – 6:00pm',
        '5:00pm – 7:00pm',
    ];

    $bloques_sabado = [
        '10:00am – 12:00pm',
        '11:00am – 1:00pm',
        '12:00pm – 2:00pm',
        '1:00pm – 3:00pm',
        '2:00pm – 4:00pm',
        '3:00pm – 5:00pm',
    ];

    return [
        '0' => [],                // Domingo  — cerrado
        '1' => $bloques_semana,   // Lunes
        '2' => $bloques_semana,   // Martes
        '3' => $bloques_semana,   // Miércoles
        '4' => $bloques_semana,   // Jueves
        '5' => $bloques_semana,   // Viernes
        '6' => $bloques_sabado,   // Sábado
    ];
}
