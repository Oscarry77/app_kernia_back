<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 (02-oct-2026): roles de operadores, cartera de clientes, escalafón
 * de autorización y prórrogas con bitácora de intentos. Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landlord_admins', function (Blueprint $table) {
            $table->string('rol', 20)->default('vendedor')->after('email');
            $table->string('puesto', 120)->nullable()->after('rol');
        });
        // Los operadores que ya existen (el dueño) quedan como superadmin.
        DB::table('landlord_admins')->update(['rol' => 'superadmin']);

        // Cartera: qué clientes ve cada operador con rol de cartera (vendedor).
        Schema::create('cliente_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('landlord_admins')->cascadeOnDelete();
            $table->foreignId('asignado_por')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['cliente_id', 'usuario_id']);
        });

        // Escalafón: quién autoriza prórrogas y hasta cuántos días.
        Schema::create('niveles_autorizacion', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('nivel');
            $table->string('puesto', 120);
            $table->foreignId('usuario_id')->constrained('landlord_admins')->cascadeOnDelete();
            $table->unsignedTinyInteger('dias_max');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['usuario_id']);
        });

        Schema::create('prorrogas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->date('fecha_vencimiento');       // ciclo: fecha de próximo pago vencida
            $table->unsignedTinyInteger('dias');
            $table->string('motivo', 40);
            $table->text('detalle')->nullable();
            $table->string('estado', 20)->default('solicitada'); // solicitada|autorizada|rechazada|vencida|cerrada_por_pago|cancelada
            $table->date('desde')->nullable();
            $table->date('hasta')->nullable();
            $table->foreignId('solicitada_por')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->foreignId('resuelta_por')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->unsignedTinyInteger('nivel_autorizacion')->nullable();
            $table->text('comentario_resolucion')->nullable();
            $table->timestamp('resuelta_en')->nullable();
            $table->timestamps();

            $table->index(['suscripcion_id', 'estado']);
        });

        // Cada intento de autorizar o rechazar, incluidos los fallidos.
        Schema::create('prorroga_intentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prorroga_id')->constrained('prorrogas')->cascadeOnDelete();
            $table->foreignId('sesion_usuario_id')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->string('email_tecleado', 150);
            $table->string('accion', 12);            // autorizar | rechazar
            $table->string('resultado', 30);         // credencial_invalida | sin_nivel | nivel_insuficiente | autoriza_propia | autorizada | rechazada
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prorroga_intentos');
        Schema::dropIfExists('prorrogas');
        Schema::dropIfExists('niveles_autorizacion');
        Schema::dropIfExists('cliente_usuario');
        Schema::table('landlord_admins', fn (Blueprint $t) => $t->dropColumn(['rol', 'puesto']));
    }
};
