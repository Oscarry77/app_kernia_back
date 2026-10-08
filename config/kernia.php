<?php

return [
    // Zona horaria del negocio: "hoy", días restantes y el corte de las 00:00
    // se calculan aquí, no en la zona del servidor (02-oct-2026).
    'zona_horaria' => env('KERNIA_ZONA_HORARIA', 'America/Mexico_City'),

    // Modalidades de pago y cuántos meses cubre cada periodo.
    'modalidades' => [
        'mensual' => 1,
        'trimestral' => 3,
        'semestral' => 6,
        'anual' => 12,
    ],

    // Banner en las apps (`aviso` del resolve).
    'aviso_info_dias' => 30,
    'aviso_advertencia_dias_habiles' => 5,

    // Finiquito (08-oct-2026, decisión del dueño del 05-oct): días que el
    // administrador del cliente tiene para descargar su respaldo.
    'finiquito_descarga_dias' => 15,
];
