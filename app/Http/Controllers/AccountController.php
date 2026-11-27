<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AccountController
{
    public function register(Request $request): JsonResponse
    {
        return $this->session($request, $this->credentials($request, true));
    }

    public function login(Request $request): JsonResponse
    {
        return $this->session($request, $this->credentials($request, false));
    }

    public function registerToken(Request $request): JsonResponse
    {
        return $this->issueToken($request, true);
    }

    public function token(Request $request): JsonResponse
    {
        return $this->issueToken($request, false);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()))->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['csrf_token' => $request->session()->token()]);
    }

    public function revoke(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 400, 'Use a bearer token to revoke it.');
        $token->delete();

        return response()->json(['revoked' => true]);
    }

    private function credentials(Request $request, bool $register): User
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', $register ? 'min:10' : 'min:1', 'max:72'],
        ]);
        if (strlen($data['password']) > 72) {
            throw ValidationException::withMessages(['password' => 'The password must be at most 72 bytes.']);
        }
        $email = mb_strtolower(trim($data['email']));
        if ($register) {
            try {
                return User::create(['email' => $email, 'password' => Hash::make($data['password'])]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['email' => 'This email already has an account.']);
            }
        }
        $user = User::where('email', $email)->first();
        $hash = $user?->password ?? '$2y$12$C0TGStmQ2Y.r5CKwep8dH.9Ce5i.gQHZ0VIHcAy49iAvemBUf1xB.';
        if (! password_verify($data['password'], $hash) || ! $user) {
            throw ValidationException::withMessages(['email' => 'Check your email and password.']);
        }

        return $user;
    }

    private function session(Request $request, User $user): JsonResponse
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $this->profile($user), 'csrf_token' => $request->session()->token()])
            ->header('Cache-Control', 'no-store');
    }

    private function issueToken(Request $request, bool $register): JsonResponse
    {
        $device = $request->validate(['device' => ['required', 'string', 'max:100']])['device'];
        $user = $this->credentials($request, $register);
        $expires = now()->addDays(30);
        $token = $user->createToken($device, ['library'], $expires);

        return response()->json(['token' => $token->plainTextToken, 'token_type' => 'Bearer',
            'expires_at' => $expires->toAtomString(), 'user' => $this->profile($user)], 201)
            ->header('Cache-Control', 'no-store');
    }

    private function profile(User $user): array
    {
        $admin = $user->isAdmin();
        $pro = $user->hasPro();

        return ['id' => $user->id, 'email' => $user->email, 'admin' => $admin, 'pro' => $pro, 'full_access' => $admin || $pro];
    }
}
