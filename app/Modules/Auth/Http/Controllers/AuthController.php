<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Materialize SANCTUM_EXPIRATION (minutes) into a Carbon expiry that
     * createToken() can stamp onto personal_access_tokens.expires_at.
     * Without this Sanctum leaves the column NULL — tokens still expire
     * at the auth-guard layer, but they linger in the table forever and
     * the prune in routes/console.php can't reach them.
     */
    private function tokenExpiration(): ?\DateTimeInterface
    {
        $minutes = (int) config('sanctum.expiration', 0);
        return $minutes > 0 ? now()->addMinutes($minutes) : null;
    }

    public function register(Request $request)
    {
        $attributes = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required', 'string', 'email', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (User::byEmail((string) $value)) {
                        $fail('The email has already been taken.');
                    }
                },
            ],
            'sex' => 'nullable|string',
            'age' => 'nullable|integer',
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'sex' => $attributes['sex'] ?? 'male',
            'age' => $attributes['age'] ?? 30,
            'password' => $attributes['password'],
        ]);

        $user->assignRole('user');
        $user->sendEmailVerificationNotification();

        $token = $user->createToken('api-token', ['*'], $this->tokenExpiration())->plainTextToken;

        activity('auth')
            ->causedBy($user)
            ->withProperties(['ip' => $request->ip(), 'user_agent' => $request->userAgent()])
            ->event('register')
            ->log('register');

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user->load('roles', 'permissions'),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => config('sanctum.expiration') * 60,
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::byEmail($credentials['email']);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            activity('auth')
                ->causedBy($user)
                ->withProperties([
                    'email_hash' => User::hashEmail($credentials['email']),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ])
                ->event('login_failed')
                ->log('login_failed');

            return response()->json([
                'errors' => ['email' => ['The provided credentials do not match our records.']],
            ], 422);
        }

        // Silently upgrade hashes that were created under a weaker
        // BCRYPT_ROUNDS setting. The 'hashed' cast re-hashes on assign.
        if (Hash::needsRehash($user->password)) {
            $user->password = $credentials['password'];
            $user->save();
        }

        $token = $user->createToken('api-token', ['*'], $this->tokenExpiration())->plainTextToken;

        activity('auth')
            ->causedBy($user)
            ->withProperties(['ip' => $request->ip(), 'user_agent' => $request->userAgent()])
            ->event('login_success')
            ->log('login_success');

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => config('sanctum.expiration') * 60,
        ]);
    }

    public function user(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'sex' => 'sometimes|nullable|string|in:male,female,other',
            'age' => 'sometimes|nullable|integer|min:0|max:150',
        ]);

        $user = $request->user();
        $user->fill($validated);
        $user->save();

        return response()->json([
            'user' => $user->fresh(),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;

        $user->password = $request->input('password');
        $user->save();

        // Revoke every other access token on password change so that
        // sessions on other devices are forced back through login.
        // The current token stays alive so the caller doesn't 401 on
        // the next request.
        $user->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        activity('auth')
            ->causedBy($user)
            ->withProperties(['ip' => $request->ip(), 'user_agent' => $request->userAgent()])
            ->event('password_changed')
            ->log('password_changed');

        return response()->json(['message' => 'Password updated']);
    }

    public function verifyEmail(Request $request, string $id, string $hash)
    {
        if (! $request->hasValidSignature()) {
            return response()->json(['error' => 'Invalid or expired verification link'], 403);
        }

        $user = User::find($id);
        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json(['error' => 'Invalid verification hash'], 403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return response()->json([
            'message' => 'Email verified',
            'verified_at' => $user->email_verified_at,
        ]);
    }

    public function resendEmailVerification(Request $request)
    {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified'], 200);
        }
        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent']);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
        ]);

        // Always respond identically regardless of whether the email is
        // registered — prevents user enumeration via this endpoint. We
        // resolve the user via the email_hash sidecar column because
        // users.email is encrypted ciphertext (random IV per row), so a
        // direct WHERE on email never matches.
        $user = User::byEmail($request->input('email'));
        if ($user) {
            $token = PasswordBroker::broker()->getRepository()->create($user);
            $user->sendPasswordResetNotification($token);
        }

        return response()->json([
            'message' => 'If an account exists for that email, a reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|string|email',
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = User::byEmail($request->input('email'));
        $repository = PasswordBroker::broker()->getRepository();

        if (! $user || ! $repository->exists($user, $request->input('token'))) {
            return response()->json([
                'errors' => ['email' => ['Invalid or expired reset link.']],
            ], 422);
        }

        $user->password = $request->input('password');
        $user->save();
        $user->tokens()->delete();
        $repository->delete($user);

        activity('auth')
            ->causedBy($user)
            ->withProperties(['ip' => $request->ip(), 'user_agent' => $request->userAgent()])
            ->event('password_reset')
            ->log('password_reset');

        return response()->json(['message' => 'Password has been reset']);
    }

    public function deleteAccount(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
        ]);

        $user = $request->user();
        $userId = $user->id;

        $user->tokens()->delete();
        $user->delete();

        activity('auth')
            ->withProperties([
                'deleted_user_id' => $userId,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ])
            ->event('account_deleted')
            ->log('account_deleted');

        return response()->json(['message' => 'Account deleted'], 200);
    }

    public function logout(Request $request)
    {
        try {
            $user = $request->user();
            $user->currentAccessToken()->delete();

            activity('auth')
                ->causedBy($user)
                ->withProperties(['ip' => $request->ip()])
                ->event('logout')
                ->log('logout');

            return response()->json(['message' => 'Logged out successfully'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Logout error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function revokeToken(Request $request, $tokenId)
    {
        $token = $request->user()->tokens()->find($tokenId);

        if (!$token) {
            return response()->json(['message' => 'Token not found'], 404);
        }

        $token->delete();

        return response()->json(['message' => 'Token revoked successfully'], 200);
    }

    public function tokens(Request $request)
    {
        $tokens = $request->user()->tokens->map(fn ($token) => [
            'id' => $token->id,
            'name' => $token->name,
            'last_used_at' => $token->last_used_at,
            'created_at' => $token->created_at,
        ]);

        return response()->json(['tokens' => $tokens]);
    }

    public function createToken(Request $request)
    {
        $validated = $request->validate([
            'device_name' => 'required|string|max:255',
        ]);

        $token = $request->user()
            ->createToken($validated['device_name'], ['*'], $this->tokenExpiration())
            ->plainTextToken;

        return response()->json([
            'message' => 'Token created',
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    /**
     * Admin-only roster of every registered user. Emails come back
     * decrypted because the model's `'email' => 'encrypted'` cast
     * runs on attribute access. Route is gated behind role:admin
     * (see Auth/routes.php) so leaking PII to a regular user is
     * impossible by construction, not by view-template discipline.
     */
    public function adminUsersList(Request $request)
    {
        // Cap per_page low enough that a single compromised admin token
        // can't dump the entire table in a handful of requests. 50 is
        // generous for any legitimate UI while keeping max-exfil-per-
        // request modest: 50 × 5 req/min = 250 rows/min, not thousands.
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 50);

        $page = User::with('roles:id,name')
            ->orderByDesc('id')
            ->paginate($perPage);

        // Audit log is handled by the `admin.audit` middleware on the
        // route group — every admin endpoint inherits it automatically.

        $page->getCollection()->transform(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'sex' => $u->sex,
            'age' => $u->age,
            'roles' => $u->roles->pluck('name')->all(),
            'email_verified_at' => $u->email_verified_at?->toIso8601String(),
            'created_at' => $u->created_at?->toIso8601String(),
        ]);

        // PII payload — must not be cached by any intermediary.
        return response()->json($page)
            ->header('Cache-Control', 'private, no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }
}
