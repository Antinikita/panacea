<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Complaint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ComplaintController extends Controller
{
    public function index(){
        $complaints= Complaint::where('user_id', Auth::id())->get();
        return response()->json([
            'user'=>Auth::user(),
            'complaints'=>$complaints
        ]);
    }

    public function store(Request $request){
    $attributes = $request->validate([
        'complaint' => ['required', 'string', 'max:1000']
    ]);

    $complaint = Auth::user()->complaints()->create($attributes);

    return response()->json([ 
        'complaint' => $complaint
    ]);
}

     /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'complaint' => 'required|string|max:1000',
        ]);

        $complaint = Complaint::where('user_id', Auth::id())->findOrFail($id);
        $complaint->update([
            'complaint' => $request->complaint,
        ]);

        return response()->json([
            'complaint'=>$complaint
        ]);
    }
        public function destroy(string $id)
    {
        // Убедиться, что жалоба принадлежит текущему пользователю
        $complaint = Complaint::where('user_id', Auth::id())->findOrFail($id);

        $complaint->delete();

        return response()->json([
            'message' => 'Complaint deleted',
            'id' => $id
        ]);
    }
}
