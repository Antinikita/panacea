<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ComplaintController extends Controller
{
    public function index()
    {
        $complaints = Complaint::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json($complaints);  // ✅ Array of complaints
    }

    public function store(Request $request)
    {
        $attributes = $request->validate([
            'complaint' => 'required|string|max:5000', // ✅ MATCH REACT
        ]);

        $complaint = Auth::user()->complaints()->create($attributes);

        return response()->json($complaint, 201);  // ✅ Single complaint object
    }

    public function show($id)
    {
        $complaint = Complaint::where('user_id', Auth::id())->findOrFail($id);
        return response()->json($complaint);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'complaint' => 'required|string|max:5000',
        ]);

        $complaint = Complaint::where('user_id', Auth::id())->findOrFail($id);
        $complaint->update($request->all());  // ✅ title + description

        return response()->json($complaint);
    }

    public function destroy($id)
    {
        $complaint = Complaint::where('user_id', Auth::id())->findOrFail($id);
        $complaint->delete();

        return response()->json(['message' => 'Complaint deleted']);
    }
}
