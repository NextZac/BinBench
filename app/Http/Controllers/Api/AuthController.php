<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Session login for the SPA. Every response carries a fresh CSRF token, since logging in or out
 * rotates the session.
 */
class AuthController extends Controller
{
    /**
     * Who is signed in, and what the login page should offer.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()?->only(['id', 'name', 'email']),
            'registration' => (bool) config('binbench.registration'),
            'inviteRequired' => filled(config('binbench.invite_code')),
            'csrfToken' => csrf_token(),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        $request->session()->regenerate();

        return $this->show($request);
    }

    public function register(Request $request): JsonResponse
    {
        abort_unless(config('binbench.registration'), 403, 'Registration is closed.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'invite_code' => filled(config('binbench.invite_code'))
                ? ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! hash_equals((string) config('binbench.invite_code'), (string) $value)) {
                        $fail('The invite code is not valid.');
                    }
                }]
                : ['nullable'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);
        Auth::login($user);
        $request->session()->regenerate();

        return $this->show($request);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->show($request);
    }
}
