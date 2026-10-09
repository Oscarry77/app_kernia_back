<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (09-oct-2026) Seguimiento de las empresas bloqueadas por plan
 * (REGISTRO_DECISIONES_KERNIA.md §3, 05-oct): 30 días para recuperarlas; si
 * no, se archivan con el flujo de v2.3. Excepción: el bloqueo total por falta
 * de lista nunca se archiva solo; se escala. Kernia no guarda datos de la
 * empresa: solo su id en la app, nombre, RFC y fechas.
 * Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas_bloqueadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->foreignId('solicitud_plan_id')->nullable()->constrained('solicitudes_plan')->nullOnDelete();
            $table->unsignedBigInteger('empresa_id');          // id en la app
            $table->string('nombre', 255);
            $table->string('rfc', 20)->nullable();
            $table->boolean('sin_seleccion')->default(false);  // bloqueo total por falta de lista
            $table->date('bloqueada_en');
            $table->date('archivar_el')->nullable();           // null en sin_seleccion
            $table->string('estado', 15)->default('bloqueada'); // bloqueada | desbloqueada | archivando | escalada
            $table->timestamp('aviso_enviado_en')->nullable();
            $table->foreignId('solicitud_salida_id')->nullable()->constrained('solicitudes_salida')->nullOnDelete();
            $table->string('nota', 300)->nullable();
            $table->timestamps();

            $table->index(['estado', 'archivar_el']);
            $table->index(['suscripcion_id', 'empresa_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas_bloqueadas');
    }
};
