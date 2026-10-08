<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (07-oct-2026) Centro de correo de Kernia (REGISTRO_DECISIONES_KERNIA.md §6,
 * 05-oct): registro de cada correo que Kernia envía o intenta enviar. Es el
 * "Enviados" real de Kernia. Guarda solo metadatos: nunca el cuerpo, y nunca
 * una contraseña. Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correos_enviados', function (Blueprint $table) {
            $table->id();
            $table->string('plantilla', 60);
            $table->string('destinatario', 150);
            $table->string('asunto', 255);
            $table->string('estado', 20);                    // enviado | fallido | omitido
            $table->string('error', 255)->nullable();        // saneado, sin credenciales
            $table->boolean('contiene_secreto')->default(false);
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('suscripcion_id')->nullable()->constrained('suscripciones')->nullOnDelete();
            $table->foreignId('operador_id')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->string('referencia', 120)->nullable();   // p. ej. solicitud_plan:41
            $table->timestamp('created_at')->useCurrent();

            $table->index(['cliente_id', 'created_at']);
            $table->index(['plantilla', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correos_enviados');
    }
};
