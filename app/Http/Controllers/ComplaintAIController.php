<?php
namespace App\Http\Controllers;

use App\Models\Complaint;
use App\Models\Recommendation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ComplaintAIController extends Controller
{
    public function analyze(Request $request)
    {
        $request->validate([
            'complaint_id' => 'required|exists:complaints,id'
        ]);

        try {
            $complaint = Complaint::with('user')->findOrFail($request->complaint_id);

            if ($complaint->user_id !== auth()->id()) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            // Control mock from .env: AI_USE_MOCK=true
            if (env('AI_USE_MOCK', false)) {
                $aiResult = [
                    'answer' => "Based on your complaint, here are recommendations:\n\n1. Document all relevant details.\n2. Reach out to the appropriate department.\n3. Follow up within 3-5 business days.",
                ];
            } else {
                $payload = [
                    'user_id' => (string) $complaint->user->id,
                    'message' => $complaint->complaint,
                    'context' => [
                        'age'     => $complaint->user->age ?? 20,
                        'sex'     => $complaint->user->sex ?? 'female',
                        'goals'   => ['default'],
                        'metrics' => (object) [],
                    ],
                    'mode' => 'general',
                ];

                Log::info('Sending to AI Module', ['payload' => $payload]);

                $response = Http::timeout(300)
                    ->withHeaders([
                        'X-Service-Token' => env('AI_SERVICE_TOKEN'),
                        'X-User-Id'       => (string) $complaint->user->id,
                        'Content-Type'    => 'application/json',
                    ])
                    ->post(env('AI_MODULE_URL'), $payload);

                if ($response->failed()) {
                    Log::error('AI Module Error', [
                        'status' => $response->status(),
                        'body'   => $response->body()
                    ]);
                    return response()->json(['message' => 'AI analysis failed'], 500);
                }

                $aiResult = $response->json();
                Log::info('AI Module Response', ['result' => $aiResult]);
            }

            // answer first since that's what your AI server returns
            $replyText = $aiResult['answer']
                ?? $aiResult['reply']
                ?? $aiResult['response']
                ?? $aiResult['message']
                ?? 'No recommendation provided';

            $recommendation = Recommendation::create([
                'user_id'        => auth()->id(),
                'complaint_id'   => $complaint->id,
                'recommendation' => $replyText,
            ]);

            return response()->json([
                'reply'             => $replyText,
                'recommendation_id' => $recommendation->id,
                'saved_at'          => $recommendation->created_at,
            ]);

        } catch (\Exception $e) {
            Log::error('Complaint AI Analysis Error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to analyze complaint'], 500);
        }
    }
}