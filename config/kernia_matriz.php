<?php

/*
 * (09-oct-2026) Árbol del panel de Kernia — ÚNICA fuente del menú lateral y
 * de la matriz "Roles y accesos" (ESTANDAR_ACL_Y_MENU_APPS_KERNIA.md §1.2 y §2).
 * Decisión del dueño del 09-oct: los roles de Kernia siguen fijos
 * (config/kernia_acl.php); la matriz es de consulta.
 *
 * Cada nodo: etiqueta, icono (catálogo común) y, si es opción: ruta del panel
 * y `permisos` por columna (acceso, crear, editar, cancelar) con la clave de
 * kernia_acl. `especiales`: permisos que no caben en las columnas. `nota`:
 * una regla que la casilla no dice (p. ej. la cartera del vendedor).
 * Una opción aparece en el menú si el operador tiene su permiso de `acceso`.
 */
return [
    'columnas' => ['acceso' => 'Acceso', 'crear' => 'Crear', 'editar' => 'Editar', 'cancelar' => 'Cancelar'],

    'modulos' => [
        [
            'etiqueta' => 'Kernia', 'icono' => 'kernia',
            'hijos' => [
                ['etiqueta' => 'Catálogos', 'icono' => 'libro', 'hijos' => [
                    ['etiqueta' => 'Productos y planes', 'icono' => 'caja', 'ruta' => '/catalogo/productos',
                        'permisos' => ['acceso' => 'clientes.ver', 'crear' => 'catalogo.gestionar', 'editar' => 'catalogo.gestionar']],
                ]],
                ['etiqueta' => 'Movimientos', 'icono' => 'flechas', 'hijos' => [
                    ['etiqueta' => 'Clientes', 'icono' => 'personas', 'ruta' => '/clientes',
                        'permisos' => ['acceso' => 'clientes.ver', 'crear' => 'clientes.crear', 'editar' => 'clientes.editar'],
                        'nota' => 'El vendedor solo ve su cartera, más los clientes de demo y capacitación.',
                        'especiales' => [
                            ['etiqueta' => 'Contratar apps y extras', 'permiso' => 'suscripciones.gestionar'],
                            ['etiqueta' => 'Suspender y reactivar', 'permiso' => 'suscripciones.estatus'],
                            ['etiqueta' => 'Restablecer al administrador', 'permiso' => 'suscripciones.restablecer_admin'],
                            ['etiqueta' => 'Solicitar cambio de plan y desbloquear empresas', 'permiso' => 'planes.solicitar'],
                            ['etiqueta' => 'Cambio directo de plan (correcciones)', 'permiso' => 'planes.aplicar_directo'],
                            ['etiqueta' => 'Solicitar retiro, reactivación o finiquito', 'permiso' => 'salidas.solicitar'],
                            ['etiqueta' => 'Solicitar reenvío de la contraseña o entrega del respaldo', 'permiso' => 'respaldos.solicitar'],
                        ]],
                    ['etiqueta' => 'Vigencias', 'icono' => 'calendario', 'ruta' => '/vigencias',
                        'permisos' => ['acceso' => 'clientes.ver', 'editar' => 'vigencias.gestionar'],
                        'especiales' => [['etiqueta' => 'Registrar pago', 'permiso' => 'pagos.registrar']]],
                    ['etiqueta' => 'Autorizaciones', 'icono' => 'verificado', 'ruta' => '/prorrogas',
                        'permisos' => ['acceso' => 'prorrogas.solicitar'],
                        'nota' => 'Autoriza quien tenga nivel en el escalafón y teclee su usuario y contraseña; nadie autoriza lo suyo.',
                        'especiales' => [['etiqueta' => 'Solicitar prórroga', 'permiso' => 'prorrogas.solicitar']]],
                ]],
                ['etiqueta' => 'Reportes', 'icono' => 'grafica', 'hijos' => [
                    ['etiqueta' => 'Motivos de salida', 'icono' => 'grafica', 'ruta' => '/motivos-salida',
                        'permisos' => ['acceso' => 'salidas.motivos']],
                ]],
                ['etiqueta' => 'Seguridad', 'icono' => 'escudo', 'hijos' => [
                    ['etiqueta' => 'Bitácora', 'icono' => 'historial', 'ruta' => '/bitacora', 'permisos' => ['acceso' => 'auditoria.ver']],
                    ['etiqueta' => 'Correos enviados', 'icono' => 'correo', 'ruta' => '/correos', 'permisos' => ['acceso' => 'correos.ver']],
                    ['etiqueta' => 'Tenants (heredado)', 'icono' => 'historial', 'ruta' => '/tenants', 'permisos' => ['acceso' => 'auditoria.ver'],
                        'nota' => 'Modelo anterior, solo consulta.'],
                ]],
            ],
        ],
        [
            'etiqueta' => 'Administración', 'icono' => 'engrane',
            'hijos' => [
                ['etiqueta' => 'Operadores', 'icono' => 'persona', 'ruta' => '/operadores',
                    'permisos' => ['acceso' => 'operadores.gestionar', 'crear' => 'operadores.gestionar', 'editar' => 'operadores.gestionar'],
                    'nota' => 'Nadie asigna un rol por encima del suyo; siempre queda al menos un superadministrador activo.'],
                ['etiqueta' => 'Escalafón', 'icono' => 'capas', 'ruta' => '/escalafon',
                    'permisos' => ['acceso' => 'escalafon.gestionar', 'crear' => 'escalafon.gestionar', 'editar' => 'escalafon.gestionar']],
                ['etiqueta' => 'Roles y accesos', 'icono' => 'llave', 'ruta' => '/roles', 'permisos' => ['acceso' => 'operadores.gestionar'],
                    'nota' => 'Los roles de Kernia son fijos; esta pantalla es de consulta.'],
                ['etiqueta' => 'Plantillas de correo', 'icono' => 'documento', 'ruta' => '/correos/plantillas', 'permisos' => ['acceso' => 'correos.ver']],
                ['etiqueta' => 'Bóveda', 'icono' => 'llave', 'ruta' => '/boveda',
                    'permisos' => ['acceso' => 'boveda.gestionar', 'editar' => 'boveda.gestionar'],
                    'nota' => 'Ningún secreto se muestra; guardar exige teclear de nuevo la contraseña.'],
            ],
        ],
    ],
];
