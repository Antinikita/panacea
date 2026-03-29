<?php
namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $attributes = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'sex'      => 'nullable|string',
            'age'      => 'nullable|integer',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user  = User::create([
            'name'     => $attributes['name'],
            'email'    => $attributes['email'],
            'sex'      => $attributes['sex'] ?? 'male',
            'age'      => $attributes['age'] ?? 30,
            'password' => $attributes['password'],
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token], 201);
    }
    
public function login(Request $request)
{
    $credentials = $request->validate([
        'email'    => 'required|email',
        'password' => 'required',
    ]);

    $user = \App\Models\User::where('email', $credentials['email'])->first();

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

    $token = $user->createToken('auth_token')->plainTextToken;

    return response()->json(['user' => $user, 'token' => $token]);
}
    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();
        } catch (\Exception $e) {
        // silent
        }
        return response()->json(['message' => 'Logged out']);
    }
}