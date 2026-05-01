<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $attributes = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'sex' => 'nullable|string',
            'age' => 'nullable|integer',
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'sex' => $attributes['sex'] ?? 'male',
            'age' => $attributes['age'] ?? 30,
            'password' => $attributes['password'],
        ]);

        $user->assignRole('user');

        $token = $user->createToken('api-token')->plainTextToken;

        activity('auth')
            ->causedBy($user)
            ->withProperties(['ip' => $request->ip(), 'user_agent' => $request->userAgent()])
            ->event('register')
            ->log('register');

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user->load('roles', 'permissions'),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => config('sanctum.expiration') * 60,
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            activity('auth')
                ->causedBy($user)
                ->withProperties([
                    'email' => $credentials['email'],
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ])
                ->event('login_failed')
                ->log('login_failed');

            return response()->json([
                'errors' => ['email' => ['The provided credentials do not match our records.']],
            ], 422);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        activity('auth')
            ->causedBy($user)
            ->withProperties(['ip' => $request->ip(), 'user_agent' => $request->userAgent()])
            ->event('login_success')
            ->log('login_success');

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => config('sanctum.expiration') * 60,
        ]);
    }

    public function user(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'sex' => 'sometimes|nullable|string|in:male,female,other',
            'age' => 'sometimes|nullable|integer|min:0|max:150',
        ]);

        $user = $request->user();
        $user->fill($validated);
        $user->save();

        return response()->json([
            'user' => $user->fresh(),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;

        $user->password = $request->input('password');
        $user->save();

        // Revoke every other access token on password change so that
        // sessions on other devices are forced back through login.
        // The current token stays alive so the caller doesn't 401 on
        // the next request.
        $user->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        activity('auth')
            ->causedBy($user)
            ->withProperties(['ip' => $request->ip(), 'user_agent' => $request->userAgent()])
            ->event('password_changed')
            ->log('password_changed');

        return response()->json(['message' => 'Password updated']);
    }

    public function logout(Request $request)
    {
        try {
            $user = $request->user();
            $user->currentAccessToken()->delete();

            activity('auth')
                ->causedBy($user)
                ->withProperties(['ip' => $request->ip()])
                ->event('logout')
                ->log('logout');

            return response()->json(['message' => 'Logged out successfully'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Logout error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function revokeToken(Request $request, $tokenId)
    {
        $token = $request->user()->tokens()->find($tokenId);

        if (!$token) {
            return response()->json(['message' => 'Token not found'], 404);
        }

        $token->delete();

        return response()->json(['message' => 'Token revoked successfully'], 200);
    }

    public function tokens(Request $request)
    {
        $tokens = $request->user()->tokens->map(fn ($token) => [
            'id' => $token->id,
            'name' => $token->name,
            'last_used_at' => $token->last_used_at,
            'created_at' => $token->created_at,
        ]);

        return response()->json(['tokens' => $tokens]);
    }

    public function createToken(Request $request)
    {
        $validated = $request->validate([
            'device_name' => 'required|string|max:255',
        ]);

        $token = $request->user()->createToken($validated['device_name'])->plainTextToken;

        return response()->json([
            'message' => 'Token created',
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }
}
