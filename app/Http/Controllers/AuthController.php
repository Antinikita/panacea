<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    
    public function register(Request $request)
    {
        $attributes = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed', // ожидаем password и password_confirmation
        ]);
        
        $user = \App\Models\User::create($attributes);
        
        // Создаём токен для Swift
        $token = $user->createToken('mobile-app')->plainTextToken;
        
        return response()->json([
            'user' => $user,
            'token' => $token
        ]);
    }
    // Универсальный login: React (cookie) + Swift (token)
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            // Если Swift отправляет "device" или "token", можно добавить
        ]);

        $user = User::where('email', $request->email)->first();
        
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // $user->tokens()->delete();

        if (!$request->hasHeader('X-Requested-With')) {
    // Только если это не React (то есть Swift или другой API-клиент)
            $token = $user->createToken('mobile-app',['*'],now()->addDay())->plainTextToken;
        } else {
            $token = null; // React не использует токен
        }

        return response()->json([
            'user' => $user,
            'token' => $token, // для Swift
        ]);
    }


    public function logout(Request $request)
    {
        if ($request->hasHeader('X-Requested-With')) {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out (React session)']);
    }

        // Если это мобильный клиент (Swift)
        $user = $request->user();

        if ($user) {
            // Удаляем только текущий токен
            $user->currentAccessToken()->delete();

            return response()->json(['message' => 'Logged out (Swift token)']);
        }

        return response()->json(['message' => 'Not authenticated'], 401);
    }
}
