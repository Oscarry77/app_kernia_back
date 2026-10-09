<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (09-oct-2026) Respaldo de la base de un cliente del patrón A (la creó Kernia)
 * antes de `ajuste-plan` — estándar v2.2 §3.3, Revisión 3; lineamientos de
 * seguridad §6.1: cifrado y por cliente. El archivo vive en el disco privado
 * de Kernia y su llave en la bóveda (`respaldo_base.{id}`).
 * Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('respaldos_base', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->foreignId('solicitud_plan_id')->nullable()->constrained('solicitudes_plan')->nullOnDelete();
            $table->string('motivo', 30);                 // ajuste_plan | manual
            $table->string('base', 120);
            $table->string('ruta', 255)->nullable();      // relativa al disco privado
            $table->unsignedBigInteger('tamano_bytes')->nullable();
            $table->char('sha256', 64)->nullable();       // del archivo cifrado
            $table->string('estado', 12);                 // listo | fallido | eliminado
            $table->string('error', 300)->nullable();
            $table->date('expira_en');
            $table->timestamps();

            $table->index(['estado', 'expira_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('respaldos_base');
    }
};
