<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Identity\Domain\Enums\UserRole;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default(UserRole::Technician->value)->after('email');
        });

        // O usuário seedado via ADMIN_EMAIL (IdentityDatabaseSeeder) já existe em qualquer
        // ambiente onde essa migration rodar — sem isso, ele cairia no default (technician) e
        // deixaria de aparecer como quem deveria acompanhar/liberar os alertas.
        $adminEmail = env('ADMIN_EMAIL');

        if ($adminEmail) {
            DB::table('users')
                ->where('email', $adminEmail)
                ->update(['role' => UserRole::GeneralAdmin->value]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
