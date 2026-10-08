<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (08-oct-2026) Formulario de salida — REGISTRO_DECISIONES_KERNIA.md §3 (05-oct)
 * y aprobación del dueño del 08-oct:
 *  - El asesor registra obligatoriamente el motivo (de una lista fija) al
 *    solicitar un retiro, un finiquito o una baja de plan.
 *  - El cliente contesta, si quiere, desde un enlace de un solo uso válido 30
 *    días: motivo, calificación 1 a 5, qué pudimos hacer mejor y si nos recomendaría.
 *  - Dirección consulta todo en "Motivos de salida".
 * Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formularios_salida', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('suscripcion_id')->nullable()->constrained('suscripciones')->nullOnDelete();
            $table->string('origen', 10);             // asesor | cliente
            $table->string('evento', 15);             // retiro | finiquito | baja_plan | archivo
            $table->foreignId('solicitud_salida_id')->nullable()->constrained('solicitudes_salida')->nullOnDelete();
            $table->foreignId('solicitud_plan_id')->nullable()->constrained('solicitudes_plan')->nullOnDelete();
            $table->string('motivo', 30);             // clave de config('kernia.motivos_salida')
            $table->text('detalle')->nullable();
            $table->unsignedTinyInteger('calificacion')->nullable(); // 1 a 5 (cliente)
            $table->text('mejora')->nullable();                      // "¿qué pudimos hacer mejor?" (cliente)
            $table->boolean('recomendaria')->nullable();             // (cliente)
            $table->foreignId('registrado_por')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['origen', 'created_at']);
        });

        // Enlace de un solo uso para el cliente. Solo se guarda la huella del token.
        Schema::create('enlaces_formulario_salida', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->string('evento', 15);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expira_en');
            $table->timestamp('usado_en')->nullable();
            $table->foreignId('formulario_salida_id')->nullable()->constrained('formularios_salida')->nullOnDelete();
            $table->foreignId('creado_por')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enlaces_formulario_salida');
        Schema::dropIfExists('formularios_salida');
    }
};
