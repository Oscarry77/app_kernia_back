<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 1 de orquestación multi-app (ver GUIA_INTEGRACION_APP_KERNIA_v1.md §3.1).
// Qué módulos de su producto tiene activos cada suscripción. El `resolve`
// devuelve "modulos": [...] a partir de aquí (ausente si el producto no usa
// módulos, es decir, no tiene filas en `producto_modulos`).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suscripcion_modulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->string('modulo_clave', 60);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['suscripcion_id', 'modulo_clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suscripcion_modulos');
    }
};
