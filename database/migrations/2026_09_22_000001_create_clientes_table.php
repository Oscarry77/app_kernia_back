<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 1 de orquestación multi-app (ver GUIA_INTEGRACION_APP_KERNIA_v1.md §2).
// Aditiva: no toca `tenants`, que sigue siendo la fuente de verdad de
// Comercializa hasta que se vincule vía `suscripciones.ref_externa`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 63)->unique();
            $table->string('nombre');
            $table->string('rfc', 20)->nullable();
            // activo | suspendido | baja
            $table->string('estatus', 20)->default('activo');
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
