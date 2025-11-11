<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ComplaintController extends Controller
{
    public function store(Request $request){
        $attributes=$request->validate([
            'complaint'=>['required']
        ]);

        Auth::user()->complaints()->create($attributes);

        return redirect('/index');
    }
}
