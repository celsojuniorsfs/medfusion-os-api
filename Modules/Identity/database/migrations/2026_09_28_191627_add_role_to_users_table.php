<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        // deixaria de aparecer como quem deveria acompanhar/liberar os alertas. config(), nunca
        // env() direto: o build roda `config:cache` antes do migrate do deploy (ver
        // config/app.php e docs/ambientes.md).
        $adminEmail = config('app.admin_email');

        if (! $adminEmail) {
            // Não trava a migration por isso — só avisa. O admin fica technician até alguém
            // promover manualmente ou rodar o seeder com ADMIN_EMAIL definido.
            Log::warning('Migration add_role_to_users_table: config app.admin_email vazio — backfill de general_admin pulado.');

            return;
        }

        DB::table('users')
            ->where('email', $adminEmail)
            ->update(['role' => UserRole::GeneralAdmin->value]);
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
