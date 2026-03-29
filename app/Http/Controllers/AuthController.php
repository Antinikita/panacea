<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $attributes = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'sex'=>'nullable|string',
            'age'=>'nullable|integer',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'sex'=>$attributes['sex'] ?? 'male',
            'age'=>$attributes['age'] ?? 30,
            'password' => bcrypt($attributes['password']), // Не забудь хешировать!
        ]);

        // ВАЖНО: Сразу логиним юзера для React (создаем сессию)
        Auth::login($user);

        // Генерируем токен для Swift (React его просто проигнорирует)
        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token
        ], 201);
    }

    public function login(Request $request)
{
    // Валидация
    $credentials = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required'],
    ]);

    // Пытаемся войти и создать сессию
    if (Auth::attempt($credentials)) {
        if ($request->hasHeader('X-Requested-With')) {

        $request->session()->regenerate(); // Важно для безопасности сессии
        
    }

        return response()->json([
            'user' => Auth::user(),
            // Токен для SPA не нужен, но если хочешь для мобилки - оставь
            'token' => $request->user()->createToken('mobile')->plainTextToken 
        ]);
    }

    // Если не вышло — возвращаем JSON с ошибкой 422 (чтобы не было редиректа!)
    return response()->json([
        'errors' => [
            'email' => ['The provided credentials do not match our records.']
        ]
    ], 422);
}

 public function logout(Request $request)
{
    // If you are using token abilities, revoke tokens (optional but recommended)
    if ($request->user()) {
        $request->user()->tokens()->delete();  // Revoke all API tokens if you use them
    }
    
    Auth::guard('web')->logout();                             // Log out from the session
    $request->session()->invalidate();         // Invalidate session data
    $request->session()->regenerateToken();    // Regenerate CSRF token
    
    return response()->json(['message' => 'Logged out'])
        ->withCookie(cookie()->forget('laravel_session'))  // Clear session cookie
        ->withCookie(cookie()->forget('XSRF-TOKEN'));      // Clear CSRF cookie
}


}