<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aviso de estatus pendiente de confirmar por la app (01-oct-2026). Si el
 * PATCH .../estatus falla, el cambio local ya aplica pero la app no se
 * entera -- crítico para apps que no consultan `resolve` en cada login
 * (HRM). Aditiva: no toca columnas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suscripciones', function (Blueprint $table) {
            $table->string('estatus_por_notificar', 30)->nullable()->after('estatus');
            $table->string('estatus_notificacion_motivo', 255)->nullable()->after('estatus_por_notificar');
            $table->unsignedSmallInteger('estatus_notificacion_intentos')->default(0)->after('estatus_notificacion_motivo');
            $table->string('estatus_notificacion_error', 500)->nullable()->after('estatus_notificacion_intentos');
            $table->timestamp('estatus_notificacion_ultimo_intento')->nullable()->after('estatus_notificacion_error');
        });
    }

    public function down(): void
    {
        Schema::table('suscripciones', function (Blueprint $table) {
            $table->dropColumn([
                'estatus_por_notificar',
                'estatus_notificacion_motivo',
                'estatus_notificacion_intentos',
                'estatus_notificacion_error',
                'estatus_notificacion_ultimo_intento',
            ]);
        });
    }
};
