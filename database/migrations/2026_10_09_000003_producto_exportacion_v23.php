<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (09-oct-2026) El finiquito necesita que la app ya exporte y tenga la sección
 * Descargas (estándar v2.3). Se separa del interruptor de estatus de salida:
 * una app puede reconocer `retirado` (paso 2) antes de tener v2.3 (paso 4).
 * Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->boolean('exportacion_v23')->default(false)->after('empresas_v22');
        });
    }

    public function down(): void
    {
        Schema::table('productos', fn (Blueprint $t) => $t->dropColumn('exportacion_v23'));
    }
};
