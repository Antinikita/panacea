<?php

namespace App\Modules\Chat\Http\Controllers;

use App\Modules\Chat\Services\SearchService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class SearchController extends Controller
{
    public function __construct(private SearchService $search) {}

    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'q' => 'required|string|min:1|max:200',
            'mode' => 'nullable|string|in:text,semantic,hybrid',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $results = $this->search->search(
            userId: Auth::id(),
            query: $validated['q'],
            mode: $validated['mode'] ?? 'hybrid',
            limit: $validated['limit'] ?? 20,
        );

        return response()->json([
            'mode' => $validated['mode'] ?? 'hybrid',
            'count' => count($results),
            'results' => $results,
        ]);
    }
}
