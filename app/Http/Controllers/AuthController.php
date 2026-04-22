<?php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request)
    {
        $attributes = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'sex'      => 'nullable|string',
            'age'      => 'nullable|integer',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::create([
            'name'     => $attributes['name'],
            'email'    => $attributes['email'],
            'sex'      => $attributes['sex'] ?? 'male',
            'age'      => $attributes['age'] ?? 30,
            'password' => $attributes['password'],
        ]);

        $user->assignRole('user');

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user->load('roles', 'permissions'),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => config('sanctum.expiration') * 60, // Convert to seconds
        ], 201);
    }

    /**
     * Sign in / sign up a user with a Google ID token (issued by the mobile
     * client's Credential Manager). Verifies the token server-side against the
     * Google client ID and issues a Sanctum token so the mobile app can call
     * the same protected endpoints as email/password users.
     */
    public function googleLogin(Request $request)
    {
        $validated = $request->validate([
            'id_token' => 'required|string',
        ]);

        $clientId = config('services.google.client_id');
        if (blank($clientId)) {
            Log::error('googleLogin: GOOGLE_CLIENT_ID is not configured');
            return response()->json([
                'errors' => ['id_token' => ['Google auth is not configured on the server.']],
            ], 500);
        }

        try {
            $client = new \Google_Client(['client_id' => $clientId]);
            $payload = $client->verifyIdToken($validated['id_token']);
        } catch (\Throwable $e) {
            Log::error('googleLogin: verifyIdToken threw: ' . $e->getMessage());
            return response()->json([
                'errors' => ['id_token' => ['Unable to verify Google token.']],
            ], 422);
        }

        if (!$payload || empty($payload['email'])) {
            return response()->json([
                'errors' => ['id_token' => ['Invalid Google ID token.']],
            ], 422);
        }

        $email = $payload['email'];
        $name = $payload['name'] ?? $payload['given_name'] ?? Str::before($email, '@');

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name'              => $name,
                // Random placeholder — Google users never sign in with this password.
                'password'          => Hash::make(Str::random(40)),
                'sex'               => 'male',
                'age'               => 30,
                'email_verified_at' => now(),
            ],
        );

        if ($user->wasRecentlyCreated) {
            $user->assignRole('user');
        }

        $token = $user->createToken('google-mobile')->plainTextToken;

        return response()->json([
            'user'        => $user->load('roles', 'permissions'),
            'roles'       => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'token'       => $token,
            'token_type'  => 'Bearer',
            'expires_in'  => 525600 * 60,
        ], $user->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Login user and return token
     * 
     * Works for both:
     * - React Web (stores in localStorage/sessionStorage)
     * - Mobile Apps (stores in secure storage)
     */
    public function login(Request $request)
    {
    $credentials = $request->validate([
        'email'    => 'required|email',
        'password' => 'required',
    ]);

    $user = User::where('email', $credentials['email'])->first();

    if (!$user) {
        return response()->json([
            'errors' => ['email' => ['The provided credentials do not match our records.']]
        ], 422);
    }

    if (!\Illuminate\Support\Facades\Hash::check($credentials['password'], $user->password)) {
        return response()->json([
            'errors' => ['email' => ['The provided credentials do not match our records.']]
        ], 422);
    }

    $token = $user->createToken('api-token')->plainTextToken;

    return response()->json([
        'user' => $user,
        'roles' => $user->getRoleNames(),
        'permissions' => $user->getAllPermissions()->pluck('name'),
        'token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => 525600 * 60, // seconds until expiration
    ]);
}

    /**
     * Get current authenticated user
     */
    public function user(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    /**
     * Logout - revoke current token
     * 
     * For Web: This invalidates the token
     * For Mobile: Token is revoked on backend
     */
    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();
            
            return response()->json([
                'message' => 'Logged out successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Logout error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Revoke specific token (for managing multiple devices)
     */
    public function revokeToken(Request $request, $tokenId)
    {
        $token = $request->user()->tokens()->find($tokenId);

        if (!$token) {
            return response()->json(['message' => 'Token not found'], 404);
        }

        $token->delete();

        return response()->json(['message' => 'Token revoked successfully'], 200);
    }

    /**
     * Get all tokens for current user
     * Useful for "Devices" or "Sessions" page
     */
    public function tokens(Request $request)
    {
        $tokens = $request->user()->tokens->map(function ($token) {
            return [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at,
                'created_at' => $token->created_at,
            ];
        });

        return response()->json([
            'tokens' => $tokens,
        ]);
    }

    /**
     * Create new token for mobile device
     * Usage: User creates new login from new device
     */
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