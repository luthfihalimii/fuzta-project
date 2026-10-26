<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Modules\Identity\Enums\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->only(['email', 'password']);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi salah.',
            ]);
        }

        $user = $request->user();

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Akun ini sedang tidak aktif.',
            ]);
        }

        $request->session()->regenerate();

        $destination = match ($user->role()) {
            Role::Owner => '/admin',
            Role::Cashier => '/pos',
            Role::Kitchen => '/kitchen',
        };

        return redirect()->intended($destination);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
