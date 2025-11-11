<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PythonController extends Controller
{
    public function sendToPy(Request $request){
        $text=$request->input('text');

        $response=Http::post('http://127.0.0.1:5001/analyze',[
            'text'=>$text
        ]);

        if($response->successful()){
                $python_result=$response->json();
                return view('index',['result'=>$python_result]);
            
        } else {
            return response()->json(['error'=>'fail'],500);
        }
    }
}
