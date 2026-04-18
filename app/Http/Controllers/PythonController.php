<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;

/**
 * @deprecated Предшественник `ComplaintAIController`; интеграция с `127.0.0.1:5001/analyze` устарела. Оставлен как исторический справочник и будет удалён в следующей ревизии.
 */
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
