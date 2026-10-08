<?php

/*
 * Roles y permisos de los operadores de Kernia (fase 3, 02-oct-2026).
 * Criterio en REGISTRO_DECISIONES_KERNIA.md §6. Denegación por defecto: un
 * permiso que no aparece aquí no se tiene. El superadmin tiene todos.
 */
return [
    'roles' => [
        'superadmin' => ['nombre' => 'Superadministrador', 'permisos' => ['*']],
        'direccion' => ['nombre' => 'Dirección', 'permisos' => [
            'clientes.ver', 'clientes.crear', 'clientes.editar',
            'suscripciones.gestionar', 'suscripciones.estatus', 'suscripciones.restablecer_admin',
            'vigencias.gestionar', 'pagos.registrar',
            'prorrogas.solicitar', 'prorrogas.autorizar', 'planes.solicitar',
            'catalogo.gestionar', 'escalafon.gestionar', 'operadores.gestionar', 'auditoria.ver', 'correos.ver',
        ]],
        'gerente' => ['nombre' => 'Gerente', 'permisos' => [
            'clientes.ver', 'clientes.crear', 'clientes.editar',
            'suscripciones.gestionar', 'suscripciones.estatus', 'suscripciones.restablecer_admin',
            'vigencias.gestionar', 'pagos.registrar',
            'prorrogas.solicitar', 'prorrogas.autorizar', 'planes.solicitar',
            'operadores.gestionar', 'correos.ver',
        ]],
        'vendedor' => ['nombre' => 'Vendedor', 'permisos' => [
            'clientes.ver', 'clientes.crear', 'clientes.editar',
            'prorrogas.solicitar', 'planes.solicitar',
        ]],
        'soporte' => ['nombre' => 'Soporte', 'permisos' => [
            'clientes.ver', 'suscripciones.restablecer_admin',
        ]],
    ],

    // Roles que cada rol puede asignar al dar de alta o editar operadores:
    // nadie crea un rol por encima del suyo; solo el superadmin crea superadmins.
    'roles_asignables' => [
        'superadmin' => ['superadmin', 'direccion', 'gerente', 'vendedor', 'soporte'],
        'direccion' => ['direccion', 'gerente', 'vendedor', 'soporte'],
        'gerente' => ['vendedor', 'soporte'],
    ],

    // Roles que solo ven su cartera (cliente_usuario).
    'roles_con_cartera' => ['vendedor'],

    'motivos_prorroga' => [
        'promesa_pago' => 'Promesa de pago',
        'pago_en_tramite' => 'Pago en trámite',
        'negociacion' => 'Negociación comercial',
        'error_administrativo' => 'Error administrativo',
        'otro' => 'Otro',
    ],

    'prorroga_max_dias' => 6,
];
