<?php

namespace App\Modules\Health\Http\Controllers;

use App\Modules\Health\Services\HealthNorms;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class HealthNormsController extends Controller
{
    /**
     * Returns the population-norm reference ranges for the authenticated
     * user's age + sex. Used by the React frontend to overlay a shaded
     * "normal" band on each trend chart.
     *
     * For educational comparison only — not a medical-grade dataset.
     */
    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'age' => $user->age,
                'sex' => $user->sex,
            ],
            'norms' => HealthNorms::forUser($user),
        ]);
    }
}
