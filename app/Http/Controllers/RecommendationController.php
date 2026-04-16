<?php

namespace App\Http\Controllers;

use App\Models\Recommendation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RecommendationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $perPage = max(1, min(100, (int) request()->integer('per_page', 20)));

        $recommendations = Recommendation::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($recommendations);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $attributes = $request->validate([
            'recommendation' => 'required|string|max:5000', 
        ]);

        $recommendation = Auth::user()->recommendations()->create($attributes);

        return response()->json($recommendation, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $recommendation = Recommendation::where('user_id', Auth::id())->findOrFail($id);
        return response()->json($recommendation);
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
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
