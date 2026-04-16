<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ComplaintController extends Controller
{
    public function index()
    {
        $perPage = max(1, min(100, (int) request()->integer('per_page', 20)));

        $complaints = Complaint::where('user_id', Auth::id())
            ->with('latestRecommendation')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'user' => Auth::user(),
            'complaints' => $complaints,
        ]);
    }

    public function store(Request $request)
    {
        $attributes = $request->validate([
            'complaint' => ['required', 'string', 'max:1000']
        ]);

        $complaint = Auth::user()->complaints()->create($attributes);

        return response()->json([
            'complaint' => $complaint,
            'message' => 'Complaint created successfully'
        ], 201);
    }

    public function show(string $id)
    {
        $complaint = Complaint::where('user_id', Auth::id())
            ->with(['recommendations' => function($query) {
                $query->orderBy('created_at', 'desc');
            }])
            ->findOrFail($id);

        return response()->json($complaint);
    }

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
            'complaint' => $complaint,
            'message' => 'Complaint updated successfully'
        ]);
    }

    public function destroy(string $id)
    {
        $complaint = Complaint::where('user_id', Auth::id())->findOrFail($id);
        $complaint->delete();

        return response()->json([
            'message' => 'Complaint deleted successfully',
            'id' => $id
        ]);
    }
}