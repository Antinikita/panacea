<?php
namespace App\Http\Controllers;

use App\Models\Complaint;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class ComplaintController extends Controller
{
    public function index()
    {
        $complaints = Complaint::where('user_id', Auth::id())
            ->with('latestRecommendation')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($complaints); // no need to return user here, frontend already has it
    }

    public function store(Request $request)
    {
        $attributes = $request->validate([
            'complaint' => ['required', 'string', 'max:1000']
        ]);

        $complaint = Auth::user()->complaints()->create($attributes);

        return response()->json($complaint, 201);
    }

    public function show(string $id)
    {
        $complaint = Complaint::where('user_id', Auth::id())
            ->with(['recommendations' => fn($q) => $q->orderBy('created_at', 'desc')])
            ->findOrFail($id);

        return response()->json($complaint);
    }

    public function update(Request $request, string $id)
    {
        $request->validate([
            'complaint' => 'required|string|max:1000',
        ]);

        $complaint = Complaint::where('user_id', Auth::id())->findOrFail($id);
        $complaint->update(['complaint' => $request->complaint]);

        return response()->json($complaint);
    }

    public function destroy(string $id)
    {
        $complaint = Complaint::where('user_id', Auth::id())->findOrFail($id);
        $complaint->delete();

        return response()->json(['message' => 'Deleted', 'id' => $id]);
    }
}