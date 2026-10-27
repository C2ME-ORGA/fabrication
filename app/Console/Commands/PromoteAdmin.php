<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteAdmin extends Command
{
    protected $signature = 'admin:promote {email : E-mail de l\'utilisateur à autoriser}';

    protected $description = "Autorise un utilisateur existant à accéder au panneau d'administration Filament";

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("Aucun utilisateur trouvé pour {$email}. Créez-le d'abord (php artisan make:filament-user).");

            return self::FAILURE;
        }

        if ($user->isAdmin()) {
            $this->components->info("{$email} est déjà administrateur.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_admin' => true])->save();

        $this->components->info("{$email} peut désormais accéder au panneau d'administration.");

        return self::SUCCESS;
    }
}
