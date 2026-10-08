<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * (05-oct-2026) Decisiones del dueño en REGISTRO_DECISIONES_KERNIA.md §3 y §6:
 *  - Tipo de cliente: comercial | demo | capacitacion | prueba.
 *  - Descripción comercial de cada plan (ficha de planes para los operadores).
 *  - Cambio de plan por solicitud con autorización del escalafón, para subir
 *    y para bajar; la baja se programa a la fecha de próximo pago.
 * Aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('tipo', 20)->default('comercial')->after('estatus');
        });

        // Clasificación inicial que dictó el dueño. `labormx` es interno
        // (LABOR MEXICANA, nunca en producción): se trata como prueba.
        DB::table('clientes')->whereIn('slug', ['demo', 'demo-svi'])->update(['tipo' => 'demo']);
        DB::table('clientes')->where('slug', 'like', 'prueba-%')->update(['tipo' => 'prueba']);
        DB::table('clientes')->where('slug', 'labormx')->update(['tipo' => 'prueba']);

        Schema::table('producto_planes', function (Blueprint $table) {
            $table->text('descripcion')->nullable()->after('nombre');
        });

        Schema::create('solicitudes_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suscripcion_id')->constrained('suscripciones')->cascadeOnDelete();
            $table->string('plan_actual', 60)->nullable();
            $table->string('plan_nuevo', 60);
            $table->string('direccion', 10);          // subida | bajada
            $table->string('aplicacion', 12);         // inmediata | renovacion
            $table->date('fecha_efectiva')->nullable(); // renovación: la fecha de próximo pago al autorizar
            $table->text('motivo');
            $table->string('estado', 20)->default('solicitada'); // solicitada|programada|aplicada|rechazada|cancelada|fallida
            $table->foreignId('solicitada_por')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->foreignId('resuelta_por')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->unsignedTinyInteger('nivel_autorizacion')->nullable();
            $table->text('comentario_resolucion')->nullable();
            $table->timestamp('resuelta_en')->nullable();
            $table->timestamp('aplicada_en')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamps();

            $table->index(['suscripcion_id', 'estado']);
            $table->index(['estado', 'fecha_efectiva']);
        });

        // Cada intento de autorizar o rechazar, incluidos los fallidos.
        Schema::create('solicitud_plan_intentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_plan_id')->constrained('solicitudes_plan')->cascadeOnDelete();
            $table->foreignId('sesion_usuario_id')->nullable()->constrained('landlord_admins')->nullOnDelete();
            $table->string('email_tecleado', 150);
            $table->string('accion', 12);            // autorizar | rechazar
            $table->string('resultado', 30);         // credencial_invalida | sin_nivel | autoriza_propia | autorizada | rechazada
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_plan_intentos');
        Schema::dropIfExists('solicitudes_plan');
        Schema::table('producto_planes', fn (Blueprint $t) => $t->dropColumn('descripcion'));
        Schema::table('clientes', fn (Blueprint $t) => $t->dropColumn('tipo'));
    }
};
