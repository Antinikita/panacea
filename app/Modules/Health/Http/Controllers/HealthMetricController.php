<?php

namespace App\Modules\Health\Http\Controllers;

use App\Modules\Health\Services\HealthIngestService;
use App\Modules\Health\Services\HealthQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class HealthMetricController extends Controller
{
    private const FUTURE_TOLERANCE_MINUTES = 5;
    private const MAX_PAST_DAYS = 365;

    private const ALLOWED_METRICS = [
        'steps' => ['unit' => 'count', 'min' => 0, 'max' => 100000],
        'heart_rate' => ['unit' => 'bpm', 'min' => 20, 'max' => 250],
        'sleep_duration' => ['unit' => 'minutes', 'min' => 0, 'max' => 1440],
    ];

    public function __construct(
        private HealthIngestService $ingest,
        private HealthQueryService $queries,
    ) {}

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'metrics' => 'required|array|min:1|max:500',
            'metrics.*.type' => ['required', 'string', Rule::in(array_keys(self::ALLOWED_METRICS))],
            'metrics.*.value' => 'required|numeric',
            'metrics.*.unit' => 'required|string|max:32',
            'metrics.*.source' => 'nullable|string|max:64',
            'metrics.*.recorded_at' => ['required', 'date', $this->recordedAtWindow()],
            'metrics.*.metadata' => 'nullable|array',
        ]);

        $validator->after(function ($v) use ($request) {
            foreach ($request->input('metrics', []) as $i => $m) {
                $type = $m['type'] ?? null;
                if (! isset(self::ALLOWED_METRICS[$type])) {
                    continue; // type rule already errored
                }
                $spec = self::ALLOWED_METRICS[$type];
                if (($m['unit'] ?? null) !== $spec['unit']) {
                    $v->errors()->add(
                        "metrics.$i.unit",
                        "Unit for {$type} must be '{$spec['unit']}'."
                    );
                }
                if (isset($m['value']) && is_numeric($m['value'])) {
                    $val = (float) $m['value'];
                    if ($val < $spec['min'] || $val > $spec['max']) {
                        $v->errors()->add(
                            "metrics.$i.value",
                            "Value for {$type} must be between {$spec['min']} and {$spec['max']}."
                        );
                    }
                }
            }
        });

        $payload = $validator->validate();

        $count = $this->ingest->ingest(Auth::id(), $payload['metrics']);

        return response()->json([
            'inserted' => $count,
        ], 201);
    }

    private function recordedAtWindow(): \Closure
    {
        return function (string $attr, mixed $value, \Closure $fail) {
            try {
                $dt = CarbonImmutable::parse((string) $value);
            } catch (\Throwable) {
                return; // 'date' rule will error
            }
            $now = CarbonImmutable::now();
            if ($dt->greaterThan($now->addMinutes(self::FUTURE_TOLERANCE_MINUTES))) {
                $fail('Recorded date cannot be in the future.');
                return;
            }
            if ($dt->lessThan($now->subDays(self::MAX_PAST_DAYS))) {
                $fail('Recorded date cannot be more than '.self::MAX_PAST_DAYS.' days in the past.');
            }
        };
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
