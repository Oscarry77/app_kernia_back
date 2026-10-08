<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (07-oct-2026) Bóveda de Kernia (REGISTRO_DECISIONES_KERNIA.md §6, 05-oct):
 * secretos cifrados que nadie ve en pantalla (credenciales del buzón de
 * Kernia y, con el estándar v2.3, contraseñas de respaldo de los clientes) y
 * la bitácora de cada acceso. Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boveda_secretos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 120)->unique();          // p. ej. correo.smtp, respaldo.{exportacion}
            $table->string('tipo', 30);                      // correo_smtp | clave_respaldo
            $table->string('descripcion', 255)->nullable();
            $table->json('resumen')->nullable();             // datos NO secretos para mostrar (host, remitente…)
            $table->text('valor')->nullable();               // cifrado con APP_KEY; null una vez purgado
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('suscripcion_id')->nullable()->constrained('suscripciones')->nullOnDelete();
            $table->date('expira_en')->nullable();           // al pasar, el valor se purga
            $table->timestamp('purgado_en')->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['tipo', 'expira_en']);
        });

        Schema::create('boveda_accesos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secreto_id')->nullable()->constrained('boveda_secretos')->nullOnDelete();
            $table->string('clave', 120);                    // se conserva aunque el secreto se elimine
            $table->string('accion', 30);                    // guardado | reemplazado | usado | prueba | purgado | denegado
            $table->foreignId('usuario_id')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->foreignId('autorizado_por')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->string('motivo', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['clave', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boveda_accesos');
        Schema::dropIfExists('boveda_secretos');
    }
};
