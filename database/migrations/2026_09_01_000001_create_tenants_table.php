<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Movida de bridge-com-api el 13-sep-2026 (extracción de Kernia). Ya no
// necesita declarar $connection='landlord' -- en kernia-api esta es
// simplemente la conexión default. El archivo mantiene su nombre/timestamp
// original para que Laravel la reconozca como ya aplicada (la tabla
// `migrations` de kernia_landlord ya trae este registro).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenants')) {
            return;
        }

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_cliente');
            $table->string('slug', 63)->unique();
            $table->string('db_host');
            $table->string('db_port', 10)->default('1433');
            $table->string('db_database');
            $table->string('db_username');
            $table->text('db_password');
            // activo | suspendido | en_aprovisionamiento
            $table->string('estatus', 30)->default('en_aprovisionamiento');
            $table->string('plan', 60)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
