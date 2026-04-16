<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * @deprecated Стаб раннего эксперимента; не зарегистрирован в маршрутах. Оставлен как исторический справочник и будет удалён в следующей ревизии.
 */
class JsonSessionController extends Controller
{
        public function store(Request $request)
    {
       
    }


    public function show(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $request->session()->regenerate(); // Важно для Sanctum

        return response()->json(['message' => 'Logged in']);
    }
}
