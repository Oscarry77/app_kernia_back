<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planes (edición) y extras por producto (01-oct-2026, estándar v2.1).
 *
 * - producto_planes: la edición define QUÉ módulos incluye y sus límites
 *   base (p. ej. Comercializa Básico = ventas/compras/inventarios, 4 empresas).
 * - producto_extras: capacidad que se vende aparte y suma a un límite
 *   (Comercializa: +1 empresa; HRM: +100 empleados).
 * - suscripcion_extras: BITÁCORA de movimientos (+n / -n) por suscripción;
 *   el total contratado es la suma. Así queda quién, cuándo y por qué.
 *
 * Aditiva: no toca tablas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_planes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('codigo', 60);
            $table->string('nombre');
            $table->json('modulos')->nullable();   // claves de producto_modulos incluidas
            $table->json('limites')->nullable();   // {"max_empresas": 4}; null en un límite = sin límite
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['producto_id', 'codigo']);
        });

        Schema::create('producto_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('codigo', 60);
            $table->string('nombre');
            $table->string('limite', 60);            // clave del límite que incrementa
            $table->unsignedInteger('incremento');    // cuánto suma cada unidad
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['producto_id', 'codigo']);
        });

        Schema::create('suscripcion_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->foreignId('producto_extra_id')->constrained('producto_extras')->restrictOnDelete();
            $table->integer('cantidad');              // movimiento: +n alta, -n baja
            $table->string('motivo', 255)->nullable();
            $table->string('registrado_por', 120)->nullable();
            $table->timestamps();

            $table->index(['suscripcion_id', 'producto_extra_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suscripcion_extras');
        Schema::dropIfExists('producto_extras');
        Schema::dropIfExists('producto_planes');
    }
};
