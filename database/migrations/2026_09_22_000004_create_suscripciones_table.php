<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 1 de orquestación multi-app (ver GUIA_INTEGRACION_APP_KERNIA_v1.md §2).
// Cliente x Producto. `db_*` solo se llena en modo `dedicada` (mismo patrón
// de cifrado que `tenants.db_password`, ver app/Models/Landlord/Tenant.php).
// Los "días restantes" se calculan (fecha_proximo_pago - hoy), no se guardan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suscripciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();

            // en_aprovisionamiento | activo | suspendido | fallido | cancelado
            $table->string('estatus', 30)->default('en_aprovisionamiento');
            $table->string('plan', 60)->nullable();
            // id local del cliente en la APP (p. ej. tenants.id de HRM)
            $table->string('ref_externa')->nullable();
            $table->string('admin_email')->nullable();
            // mensual | anual | ...
            $table->string('modalidad_pago', 20)->nullable();
            $table->date('fecha_contratacion')->nullable();
            $table->date('fecha_proximo_pago')->nullable();
            $table->unsignedSmallInteger('dias_gracia')->default(0);
            $table->boolean('suspension_automatica')->default(true);
            $table->date('activa_hasta')->nullable();

            // Conexión dedicada (nulos en modo compartida)
            $table->string('db_driver', 20)->nullable();
            $table->string('db_host')->nullable();
            $table->string('db_port', 10)->nullable();
            $table->string('db_database')->nullable();
            $table->string('db_username')->nullable();
            $table->text('db_password')->nullable();
            $table->string('db_bi_username')->nullable();
            $table->text('db_bi_password')->nullable();

            $table->timestamp('provisionada_en')->nullable();
            $table->timestamps();

            $table->unique(['cliente_id', 'producto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suscripciones');
    }
};
