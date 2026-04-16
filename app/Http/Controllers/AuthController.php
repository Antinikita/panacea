<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $attributes = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'sex'      => 'nullable|in:male,female,other',
            'age'      => 'nullable|integer|min:0|max:120',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::create([
            'name'     => $attributes['name'],
            'email'    => $attributes['email'],
            'sex'      => $attributes['sex'] ?? 'male',
            'age'      => $attributes['age'] ?? 30,
            'password' => $attributes['password'], // cast 'hashed' в User хеширует автоматически
        ]);

        if ($this->isStatefulRequest($request)) {
            Auth::login($user);
            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
            return response()->json(['user' => $user], 201);
        }

        $token = $user->createToken('mobile-app')->plainTextToken;
        return response()->json(['user' => $user, 'token' => $token], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json([
                'errors' => ['email' => ['The provided credentials do not match our records.']],
            ], 422);
        }

        $user = Auth::user();

        if ($this->isStatefulRequest($request)) {
            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
            return response()->json(['user' => $user]);
        }

        $token = $user->createToken('mobile')->plainTextToken;
        return response()->json(['user' => $user, 'token' => $token]);
    }

    public function logout(Request $request)
    {
        if ($this->isStatefulRequest($request)) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            return response()->json(['message' => 'Logged out'])
                ->withCookie(cookie()->forget('laravel_session'))
                ->withCookie(cookie()->forget('XSRF-TOKEN'));
        }

        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
        return response()->json(['message' => 'Logged out']);
    }

    private function isStatefulRequest(Request $request): bool
    {
        if ($request->header('X-Requested-With') === 'XMLHttpRequest') {
            return true;
        }
        if ($request->attributes->get('sanctum.stateful')) {
            return true;
        }
        return false;
    }
}
