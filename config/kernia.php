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

    // Retención del respaldo tras el finiquito o el archivo (decisión del dueño del 05-oct): después se elimina.
    'retencion_dias' => 90,

    // Avisos de vencimiento por correo (09-oct-2026, fase 4; criterio del
    // orquestador, corregible): días antes de la fecha de próximo pago en los
    // que se avisa, y desde cuántos días va copia a Dirección.
    'avisos_vencimiento_dias' => [30, 15, 7, 3, 1, 0],
    'avisos_vencimiento_direccion_desde' => 3,

    // Formulario de salida (08-oct-2026, aprobado por el dueño). El asesor
    // elige uno al solicitar un retiro, un finiquito o una baja de plan; el
    // cliente, desde su enlace. "otro" exige detalle.
    'motivos_salida' => [
        'precio' => 'Precio',
        'cambio_sistema' => 'Cambio a otro sistema',
        'faltan_funciones' => 'Le faltan funciones',
        'servicio' => 'Problemas de servicio',
        'cierre_negocio' => 'Cierre o venta del negocio',
        'ya_no_necesita' => 'Ya no lo necesita',
        'falta_pago' => 'Falta de pago',
        'otro' => 'Otro',
    ],

    // Motivos que el cliente NO ve en su formulario (los decide Kernia).
    'motivos_salida_solo_asesor' => ['falta_pago'],

    // Vigencia del enlace de un solo uso para el cliente.
    'formulario_salida_dias' => 30,

    // Dirección pública del panel; el enlace del cliente es {url_panel}/salida/{token}.
    'url_panel' => env('KERNIA_URL_PANEL', 'http://localhost:4400'),
];
