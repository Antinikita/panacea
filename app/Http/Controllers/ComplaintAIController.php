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
                return response()->json([
                    'message' => 'Unauthorized'
                ], 403);
            }

            // ========== МОК: ВКЛЮЧИ ЭТО ДЛЯ ТЕСТА ==========
            $useMock = false; // Поставь false когда сервер заработает
            
            if ($useMock) {
                // Имитация ответа от AI-сервера
                $aiResult = [
                    'reply' => "Thank you for sharing your concern. Based on your complaint about '{$complaint->complaint}', here are my recommendations:\n\n1. **Immediate Action**: Document all relevant details and timestamps related to this issue.\n\n2. **Next Steps**: Consider reaching out to the appropriate department or supervisor to discuss this matter formally.\n\n3. **Follow-up**: Keep a record of all communications and follow up within 3-5 business days if you don't receive a response.\n\n4. **Self-care**: Remember to take care of your mental health during this stressful time. Consider speaking with a counselor if needed.\n\nIf the situation doesn't improve, you may want to escalate this to higher management or HR department.",
                    'sentiment' => 'negative',
                    'category' => 'workplace_issue',
                    'priority' => 'high',
                ];
                
                // Симуляция задержки сервера (опционально)
                sleep(2); // 2 секунды задержки для реалистичности
            } else {
                // ========== РЕАЛЬНЫЙ ЗАПРОС К AI-СЕРВЕРУ ==========
                $payload = [
                    'user_id' => (string) $complaint->user->id,
                    'message' => $complaint->complaint,
                    'context' => [
                        'age' => $complaint->user->age ?? 20,
                        'sex' => $complaint->user->sex ?? 'female',
                        'goals' => ['default'],
                        'metrics' => (object) [],
                    ],
                    'mode' => 'general',
                ];
                
                Log::info('Sending to AI Module', ['payload' => $payload]);
                
                $response = Http::timeout(300)->withHeaders([
        'X-Service-Token' => env('AI_SERVICE_TOKEN'),
        'X-User-Id'       => (string) $complaint->user->id,
        'Content-Type'    => 'application/json',
    ])
                    ->post(env('AI_MODULE_URL'), $payload);

                if ($response->failed()) {
                    Log::error('AI Module Error', [
                        'status' => $response->status(),
                        'body' => $response->body()
                    ]);
                    
                    return response()->json([
                        'message' => 'AI analysis failed',
                        'error' => $response->body()
                    ], 500);
                }

                $aiResult = $response->json();
                Log::info('AI Module Response', ['result' => $aiResult]);
            }

            // Извлекаем reply из ответа
            $replyText = $aiResult['reply'] ?? 
                         $aiResult['response'] ?? 
                         $aiResult['message'] ?? 
                         $aiResult['answer'] ??
                         'No recommendation provided';

            // Сохраняем в БД
            $recommendation = Recommendation::create([
                'user_id' => auth()->id(),
                'complaint_id' => $complaint->id,
                'recommendation' => $replyText,
            ]);

            return response()->json([
                'reply' => $replyText,
                'recommendation_id' => $recommendation->id,
                'saved_at' => $recommendation->created_at,
                'full_response' => $aiResult,
            ]);

        } catch (\Exception $e) {
            Log::error('Complaint AI Analysis Error: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to analyze complaint',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}