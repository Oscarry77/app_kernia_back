<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (09-oct-2026) Orquestación de la baja de plan con el estándar v2.2
 * (REGISTRO_DECISIONES_KERNIA.md §3, 05-oct; ESTANDAR_v2.2 §3 y §4):
 *  - El asesor captura qué empresas conserva el cliente (o "sin lista").
 *  - A las 00:00: aviso en la app → 5 min → en_mantenimiento → ajuste-plan →
 *    polling → plan aplicado → activo → correo al administrador.
 *  - Kernia solo orquesta esto con una app que ya cumple v2.2 (`productos.empresas_v22`).
 * Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->boolean('empresas_v22')->default(false)->after('estatus_salida');
        });

        Schema::table('solicitudes_plan', function (Blueprint $table) {
            // null = el cliente aún no indica qué empresas conserva (al llegar la fecha: sin_seleccion).
            $table->json('empresas_conservar')->nullable()->after('motivo');
            $table->string('fase', 15)->nullable()->after('estado');       // aviso | mantenimiento | ajustando
            $table->timestamp('fase_desde')->nullable()->after('fase');
            $table->boolean('alerta_demora')->default(false)->after('fase_desde');
            $table->string('respaldo_id', 100)->nullable()->after('alerta_demora');
            $table->json('empresas_bloqueadas')->nullable()->after('respaldo_id');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_plan', fn (Blueprint $t) => $t->dropColumn(['empresas_conservar', 'fase', 'fase_desde', 'alerta_demora', 'respaldo_id', 'empresas_bloqueadas']));
        Schema::table('productos', fn (Blueprint $t) => $t->dropColumn('empresas_v22'));
    }
};
