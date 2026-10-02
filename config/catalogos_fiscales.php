<?php

/*
 * Catálogos fiscales para los datos del cliente (02-oct-2026), alineados a la
 * Constancia de Situación Fiscal y al catálogo c_RegimenFiscal del SAT
 * (CFDI 4.0). `aplica`: 'F' persona física, 'M' persona moral.
 */
return [

    'regimenes_fiscales' => [
        ['clave' => '601', 'nombre' => 'General de Ley Personas Morales', 'aplica' => ['M']],
        ['clave' => '603', 'nombre' => 'Personas Morales con Fines no Lucrativos', 'aplica' => ['M']],
        ['clave' => '605', 'nombre' => 'Sueldos y Salarios e Ingresos Asimilados a Salarios', 'aplica' => ['F']],
        ['clave' => '606', 'nombre' => 'Arrendamiento', 'aplica' => ['F']],
        ['clave' => '607', 'nombre' => 'Régimen de Enajenación o Adquisición de Bienes', 'aplica' => ['F']],
        ['clave' => '608', 'nombre' => 'Demás ingresos', 'aplica' => ['F']],
        ['clave' => '610', 'nombre' => 'Residentes en el Extranjero sin Establecimiento Permanente en México', 'aplica' => ['F', 'M']],
        ['clave' => '611', 'nombre' => 'Ingresos por Dividendos (socios y accionistas)', 'aplica' => ['F']],
        ['clave' => '612', 'nombre' => 'Personas Físicas con Actividades Empresariales y Profesionales', 'aplica' => ['F']],
        ['clave' => '614', 'nombre' => 'Ingresos por intereses', 'aplica' => ['F']],
        ['clave' => '615', 'nombre' => 'Régimen de los ingresos por obtención de premios', 'aplica' => ['F']],
        ['clave' => '616', 'nombre' => 'Sin obligaciones fiscales', 'aplica' => ['F']],
        ['clave' => '620', 'nombre' => 'Sociedades Cooperativas de Producción que optan por diferir sus ingresos', 'aplica' => ['M']],
        ['clave' => '621', 'nombre' => 'Incorporación Fiscal', 'aplica' => ['F']],
        ['clave' => '622', 'nombre' => 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras', 'aplica' => ['M']],
        ['clave' => '623', 'nombre' => 'Opcional para Grupos de Sociedades', 'aplica' => ['M']],
        ['clave' => '624', 'nombre' => 'Coordinados', 'aplica' => ['M']],
        ['clave' => '625', 'nombre' => 'Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas', 'aplica' => ['F']],
        ['clave' => '626', 'nombre' => 'Régimen Simplificado de Confianza', 'aplica' => ['F', 'M']],
    ],

    'regimenes_capital' => [
        ['clave' => 'SA DE CV', 'nombre' => 'Sociedad Anónima de Capital Variable'],
        ['clave' => 'SA', 'nombre' => 'Sociedad Anónima'],
        ['clave' => 'SAPI DE CV', 'nombre' => 'Sociedad Anónima Promotora de Inversión de Capital Variable'],
        ['clave' => 'SAB DE CV', 'nombre' => 'Sociedad Anónima Bursátil de Capital Variable'],
        ['clave' => 'SAS', 'nombre' => 'Sociedad por Acciones Simplificada'],
        ['clave' => 'S DE RL DE CV', 'nombre' => 'Sociedad de Responsabilidad Limitada de Capital Variable'],
        ['clave' => 'S DE RL', 'nombre' => 'Sociedad de Responsabilidad Limitada'],
        ['clave' => 'SC', 'nombre' => 'Sociedad Civil'],
        ['clave' => 'AC', 'nombre' => 'Asociación Civil'],
        ['clave' => 'IAP', 'nombre' => 'Institución de Asistencia Privada'],
        ['clave' => 'SC DE RL DE CV', 'nombre' => 'Sociedad Cooperativa de Responsabilidad Limitada de Capital Variable'],
        ['clave' => 'SPR DE RL', 'nombre' => 'Sociedad de Producción Rural de Responsabilidad Limitada'],
        ['clave' => 'SPR DE RI', 'nombre' => 'Sociedad de Producción Rural de Responsabilidad Ilimitada'],
        ['clave' => 'S EN C', 'nombre' => 'Sociedad en Comandita Simple'],
        ['clave' => 'S EN C POR A', 'nombre' => 'Sociedad en Comandita por Acciones'],
        ['clave' => 'SNC', 'nombre' => 'Sociedad en Nombre Colectivo'],
    ],

    'tipos_vialidad' => [
        'CALLE', 'AVENIDA', 'BOULEVARD', 'CALZADA', 'CIRCUITO', 'CERRADA', 'PRIVADA', 'ANDADOR',
        'CALLEJÓN', 'PROLONGACIÓN', 'PERIFÉRICO', 'EJE VIAL', 'VIADUCTO', 'RETORNO', 'PASAJE',
        'DIAGONAL', 'AMPLIACIÓN', 'CONTINUACIÓN', 'CARRETERA', 'CAMINO', 'BRECHA', 'TERRACERÍA',
        'VEREDA', 'CORREDOR', 'OTRO',
    ],

    'entidades_federativas' => [
        'AGUASCALIENTES', 'BAJA CALIFORNIA', 'BAJA CALIFORNIA SUR', 'CAMPECHE', 'CHIAPAS', 'CHIHUAHUA',
        'CIUDAD DE MÉXICO', 'COAHUILA DE ZARAGOZA', 'COLIMA', 'DURANGO', 'GUANAJUATO', 'GUERRERO',
        'HIDALGO', 'JALISCO', 'MÉXICO', 'MICHOACÁN DE OCAMPO', 'MORELOS', 'NAYARIT', 'NUEVO LEÓN',
        'OAXACA', 'PUEBLA', 'QUERÉTARO', 'QUINTANA ROO', 'SAN LUIS POTOSÍ', 'SINALOA', 'SONORA',
        'TABASCO', 'TAMAULIPAS', 'TLAXCALA', 'VERACRUZ DE IGNACIO DE LA LLAVE', 'YUCATÁN', 'ZACATECAS',
    ],
];
