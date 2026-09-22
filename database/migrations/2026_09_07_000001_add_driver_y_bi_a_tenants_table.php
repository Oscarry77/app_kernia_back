<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Movida de bridge-com-api el 13-sep-2026, mismo nombre/timestamp original.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('db_driver', 20)->default('sqlsrv')->after('db_port');
            $table->string('db_bi_username')->nullable()->after('db_password');
            $table->text('db_bi_password')->nullable()->after('db_bi_username');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['db_driver', 'db_bi_username', 'db_bi_password']);
        });
    }
};
