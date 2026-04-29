<?php

namespace App\Modules\Health\Http\Controllers;

use App\Modules\Health\Services\HealthIngestService;
use App\Modules\Health\Services\HealthQueryService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class HealthMetricController extends Controller
{
    public function __construct(
        private HealthIngestService $ingest,
        private HealthQueryService $queries,
    ) {}

    public function store(Request $request)
    {
        $payload = $request->validate([
            'metrics' => 'required|array|min:1|max:500',
            'metrics.*.type' => 'required|string|max:64',
            'metrics.*.value' => 'required|numeric',
            'metrics.*.unit' => 'required|string|max:32',
            'metrics.*.source' => 'nullable|string|max:64',
            'metrics.*.recorded_at' => 'required|date',
            'metrics.*.metadata' => 'nullable|array',
        ]);

        $count = $this->ingest->ingest(Auth::id(), $payload['metrics']);

        return response()->json([
            'inserted' => $count,
        ], 201);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'type' => 'nullable|string|max:64',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:5000',
        ]);

        $rows = $this->queries->range(
            Auth::id(),
            $validated['type'] ?? null,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            $validated['limit'] ?? 1000,
        );

        return response()->json([
            'count' => count($rows),
            'metrics' => $rows,
        ]);
    }

    public function summary(Request $request)
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
        ]);

        return response()->json(
            $this->queries->summaryFor(Auth::id(), $validated['date'] ?? now()->toDateString())
        );
    }
}
