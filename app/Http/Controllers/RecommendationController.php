<?php
namespace App\Http\Controllers;

use App\Models\Recommendation;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class RecommendationController extends Controller
{
    // GET /api/recommendations — all recommendations for current user
    public function index()
    {
        $recommendations = Recommendation::where('user_id', Auth::id())
            ->with('complaint') // useful to show which complaint it belongs to
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($recommendations);
    }

    // GET /api/recommendations/{id}
    public function show(string $id)
    {
        $recommendation = Recommendation::where('user_id', Auth::id())
            ->with('complaint')
            ->findOrFail($id);

        return response()->json($recommendation);
    }

    // DELETE /api/recommendations/{id}
    public function destroy(string $id)
    {
        $recommendation = Recommendation::where('user_id', Auth::id())->findOrFail($id);
        $recommendation->delete();

        return response()->json(['message' => 'Deleted', 'id' => $id]);
    }
}