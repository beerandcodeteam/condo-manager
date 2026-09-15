<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('app:create-super-admin {--name= : Nome do super admin} {--email= : E-mail de acesso} {--password= : Senha (solicitada de forma oculta quando omitida)}')]
#[Description('Cria um usuário super admin da plataforma, sem condomínio')]
class CreateSuperAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $attributes = [
            'name' => $this->option('name') ?? $this->ask('Nome'),
            'email' => $this->option('email') ?? $this->ask('E-mail'),
            'password' => $this->option('password') ?? $this->secret('Senha (mínimo 8 caracteres)'),
        ];

        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create([
            ...$validator->validated(),
            'role_id' => Role::idFor(Role::SUPER_ADMIN),
            'condominium_id' => null,
            'is_active' => true,
        ]);

        $this->components->info("Super admin {$user->email} criado.");

        return self::SUCCESS;
    }
}
