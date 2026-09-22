<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 1 de orquestación multi-app (ver GUIA_INTEGRACION_APP_KERNIA_v1.md §3.1).
// Catálogo de módulos que declara un producto (p. ej. Comercializa: ventas,
// compras, inventarios, tesoreria, viaticos). HRM y SVI no declaran filas
// aquí por ahora (un solo módulo implícito).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_modulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('clave', 60);
            $table->string('nombre');
            $table->timestamps();

            $table->unique(['producto_id', 'clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_modulos');
    }
};
