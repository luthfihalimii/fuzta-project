<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateOwner extends Command
{
    protected $signature = 'app:create-owner
        {email : Email address for the owner}
        {--name=Pemilik : Display name}
        {--password= : Password for automation; omit to be prompted}';

    protected $description = 'Create the first active owner account';

    public function handle(): int
    {
        if (User::query()
            ->where('role', Role::Owner)
            ->where('is_active', true)
            ->exists()
        ) {
            $this->error('An active owner already exists. Use the staff admin to manage accounts.');

            return self::FAILURE;
        }

        $password = $this->option('password');

        if ($password === null) {
            $password = $this->secret('Kata sandi');
            $confirmation = $this->secret('Ulangi kata sandi');

            if ($password !== $confirmation) {
                $this->error('Kata sandi tidak cocok.');

                return self::FAILURE;
            }
        }

        $data = [
            'name' => $this->option('name'),
            'email' => $this->argument('email'),
            'password' => $password,
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::default() ?? Password::min(8)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $owner = User::create([
            ...$data,
            'role' => Role::Owner,
            'branch_id' => null,
            'is_active' => true,
        ]);

        $this->info("Owner {$owner->email} created.");

        return self::SUCCESS;
    }
}
