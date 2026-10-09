<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (09-oct-2026) Avisos de vencimiento por correo (fase 4) —
 * REGISTRO_DECISIONES_KERNIA.md §4 (21-sep y 02-oct): desde 30 días antes, al
 * cliente, a su asesor y a Dirección. Un registro por suscripción, ciclo
 * (fecha de próximo pago) e hito, para no repetir un aviso ya entregado.
 * Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avisos_vencimiento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->date('fecha_proximo_pago');   // el ciclo: un pago la recorre y empieza otro
            $table->string('hito', 12);            // 30 | 15 | 7 | 3 | 1 | 0 | suspendida
            $table->timestamp('enviado_en');
            $table->timestamps();

            $table->unique(['suscripcion_id', 'fecha_proximo_pago', 'hito']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos_vencimiento');
    }
};
