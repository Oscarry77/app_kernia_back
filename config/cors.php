<?php

/*
 * (09-oct-2026) Mismos valores que el CORS por omisión de Laravel, más los
 * encabezados que el panel necesita leer al descargar un respaldo entregado a
 * soporte (nombre del archivo y huella SHA-256). Sin esto el navegador los
 * oculta, porque el panel y el API son orígenes distintos.
 */
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Disposition', 'X-Huella-SHA256'],
    'max_age' => 0,
    'supports_credentials' => false,
];
