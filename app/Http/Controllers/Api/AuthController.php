<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlyUser;
use App\Mail\SendOtpMail;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function __construct(
        protected WhatsAppService $whatsAppService
    ) {}

    private function buildOtpToken(array $data, string $otpCode, int $ttlMinutes = 15): string
    {
        $payload = [
            'data'       => $data,
            'hashed_otp' => bcrypt($otpCode),
            'exp'        => now()->addMinutes($ttlMinutes)->timestamp,
        ];

        return Crypt::encryptString(json_encode($payload));
    }

    private function decodeOtpToken(string $token): array
    {
        try {
            $json = Crypt::decryptString($token);
        } catch (\Exception $e) {
            throw new \RuntimeException('Malformed or tampered OTP token.');
        }

        $payload = json_decode($json, true);

        if (!is_array($payload) || !isset($payload['exp'], $payload['hashed_otp'], $payload['data'])) {
            throw new \RuntimeException('Malformed OTP token payload.');
        }

        if (now()->timestamp > $payload['exp']) {
            throw new \RuntimeException('OTP token has expired. Please request a new OTP.');
        }

        return $payload;
    }

    private function issueTokens(FlyUser $user): array
    {
        $accessToken  = JWTAuth::fromUser($user);
        $refreshToken = JWTAuth::customClaims([
            'is_refresh' => true,
            'exp'        => now()->addDays(365)->timestamp,
        ])->fromUser($user);

        return [$accessToken, $refreshToken];
    }

    /**
     * Send OTP via WhatsApp using rcloud's reference payload structure:
     * bodyParams = [otp, context], buttons = [copy-code url button].
     *
     * Rate limited per phone number: max 3 sends per 10-minute window,
     * and a hard cap of 5 per hour to blunt sustained abuse/cost. Only
     * successful dispatches count against the limit — a provider-side
     * failure shouldn't burn the user's quota.
     *
     * Throws \RuntimeException when the limit is hit, so callers should
     * catch it the same way they already catch it around decodeOtpToken()
     * and return a 429 (or reuse their existing \RuntimeException handler).
     *
     * If this still fails with #132000/#132018, the issue is the approved
     * template definition on rcloud/Meta's side — verify placeholder count
     * and button config in the rcloud dashboard before editing this again.
     */
    private function sendWhatsAppOtp(string $phone, string $otpCode, string $context = 'Fly Birds Login'): bool
    {
        $waPhone = '91' . ltrim($phone, '0');

        $minuteKey = 'whatsapp-otp:' . $waPhone;
        $hourKey   = 'whatsapp-otp-hourly:' . $waPhone;

        if (RateLimiter::tooManyAttempts($minuteKey, 3)) {
            $seconds = RateLimiter::availableIn($minuteKey);
            Log::warning('WhatsApp OTP rate limit hit (10-min window)', [
                'phone'       => $waPhone,
                'retry_after' => $seconds,
            ]);
            throw new \RuntimeException(
                'Too many OTP requests. Please try again in ' . max(1, ceil($seconds / 60)) . ' minute(s).'
            );
        }

        if (RateLimiter::tooManyAttempts($hourKey, 5)) {
            $seconds = RateLimiter::availableIn($hourKey);
            Log::warning('WhatsApp OTP rate limit hit (hourly window)', [
                'phone'       => $waPhone,
                'retry_after' => $seconds,
            ]);
            throw new \RuntimeException(
                'Too many OTP requests. Please try again in ' . max(1, ceil($seconds / 60)) . ' minute(s).'
            );
        }

        $template = config('whatsapp_templates.otp');

        Log::info('WhatsApp OTP send attempt', [
            'to'       => $waPhone,
            'template' => $template['name'],
            'language' => $template['language'],
            'params'   => [$otpCode, $context],
        ]);

        $sent = $this->whatsAppService->sendTemplateMessage(
            to: $waPhone,
            templateName: $template['name'],
            language: $template['language'],
            bodyParams: [$otpCode, $context],
            buttons: [
                ['type' => 'button', 'sub_type' => 'url', 'text' => $otpCode],
            ]
        );

        if ($sent) {
            RateLimiter::hit($minuteKey, 600);  // 10 minutes
            RateLimiter::hit($hourKey, 3600);   // 1 hour
        }

        return $sent;
    }

    // ============================================================
    // 1. USER REGISTER - STEP 1: INIT (Phone + Name, OTP via WhatsApp)
    // ============================================================
    public function registerInit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'  => 'required|string|max:255',
            'phone' => 'required|string|max:15|unique:fly_users,phone',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $otpCode = (string) random_int(10000, 99999);
        Log::info("Flybirds Registration OTP for phone={$request->phone}: [{$otpCode}]");

        $sent = $this->sendWhatsAppOtp($request->phone, $otpCode, 'Fly Birds Login');

        if (!$sent) {
            Log::error("WhatsApp registration OTP failed to send for phone: {$request->phone}");
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to dispatch OTP via WhatsApp. Please try again.',
            ], 500);
        }

        $otpToken = $this->buildOtpToken([
            'name'      => $request->name,
            'email'     => null,
            'phone'     => $request->phone,
            'password'  => Hash::make(Str::random(16)),
            'user_type' => 'user',
        ], $otpCode, 15);

        return response()->json([
            'status'    => 'success',
            'message'   => 'OTP dispatched successfully to your WhatsApp number.',
            'otp_token' => $otpToken,
        ], 200);
    }

    // ============================================================
    // 2. USER REGISTER - STEP 2: VERIFY
    // ============================================================
    public function registerVerify(Request $request)
    {
        $request->validate([
            'otp_token' => 'required|string',
            'otp_code'  => 'required|string|digits:5',
        ]);

        try {
            $payload = $this->decodeOtpToken($request->otp_token);

            if (!Hash::check($request->otp_code, $payload['hashed_otp'])) {
                return response()->json(['status' => 'error', 'message' => 'Invalid or incorrect OTP code.'], 401);
            }

            $regData = $payload['data'];

            $user = FlyUser::create([
                'name'            => $regData['name'],
                'email'           => $regData['email'],
                'phone'           => $regData['phone'],
                'password'        => $regData['password'],
                'user_type'       => $regData['user_type'],
                'otp_verified_at' => now(),
            ]);

            [$accessToken, $refreshToken] = $this->issueTokens($user);

            return response()->json([
                'status'        => 'success',
                'message'       => 'Phone verification complete. Account created!',
                'user_id'       => $user->user_id,
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type'    => 'bearer',
                'expires_in'    => config('jwt.ttl') * 60,
            ], 201);
        } catch (\RuntimeException $e) {
            Log::warning('Register verify failed: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('Register verify unexpected error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    // ============================================================
    // 3. ADMIN REGISTER - STEP 1: INIT
    // ============================================================
    public function adminRegisterInit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'      => 'required|string|max:255',
            'email'     => 'required|string|email|max:255|unique:fly_users,email',
            'phone'     => 'required|string|max:15|unique:fly_users,phone',
            'password'  => 'required|string|min:6',
            'user_type' => 'required|string|in:superadmin,manager,finance',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $otpCode = (string) random_int(10000, 99999);
        Log::info("Flybirds ADMIN Registration OTP for {$request->email}: [{$otpCode}]");

        try {
            Mail::to($request->email)->send(new SendOtpMail($request->name, $otpCode));
        } catch (\Exception $e) {
            Log::error('Admin register OTP mail failed: ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to dispatch email via Hostinger server.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        $otpToken = $this->buildOtpToken([
            'name'      => $request->name,
            'email'     => $request->email,
            'phone'     => $request->phone,
            'password'  => Hash::make($request->password),
            'user_type' => $request->user_type,
        ], $otpCode, 15);

        return response()->json([
            'status'    => 'success',
            'message'   => 'Admin registration OTP dispatched successfully to your email inbox.',
            'otp_token' => $otpToken,
        ], 200);
    }

    // ============================================================
    // 4. ADMIN REGISTER - STEP 2: VERIFY
    // ============================================================
    public function adminRegisterVerify(Request $request)
    {
        $request->validate([
            'otp_token' => 'required|string',
            'otp_code'  => 'required|string|digits:5',
        ]);

        try {
            $payload = $this->decodeOtpToken($request->otp_token);

            if (!Hash::check($request->otp_code, $payload['hashed_otp'])) {
                return response()->json(['status' => 'error', 'message' => 'Invalid or incorrect OTP code.'], 401);
            }

            $regData = $payload['data'];

            if (!in_array($regData['user_type'], ['superadmin', 'manager', 'finance'])) {
                return response()->json(['status' => 'error', 'message' => 'Invalid role in token payload.'], 422);
            }

            $adminUser = FlyUser::create([
                'name'            => $regData['name'],
                'email'           => $regData['email'],
                'phone'           => $regData['phone'],
                'password'        => $regData['password'],
                'user_type'       => $regData['user_type'],
                'otp_verified_at' => now(),
            ]);

            [$accessToken, $refreshToken] = $this->issueTokens($adminUser);

            return response()->json([
                'status'        => 'success',
                'message'       => 'Administrative account verified and created successfully!',
                'user_id'       => $adminUser->user_id,
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type'    => 'bearer',
                'expires_in'    => config('jwt.ttl') * 60,
            ], 201);
        } catch (\RuntimeException $e) {
            Log::warning('Admin register verify failed: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('Admin register verify unexpected error: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    // ============================================================
    // 5. UNIFIED LOGIN
    // ============================================================
    public function login(Request $request)
    {
        if ($request->has('password')) {
            $request->validate([
                'login_field' => 'required|string',
                'password'    => 'required|string',
            ]);

            $field = filter_var($request->login_field, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

            if (!$token = JWTAuth::attempt([$field => $request->login_field, 'password' => $request->password])) {
                return response()->json(['status' => 'error', 'message' => 'Invalid matching credentials.'], 401);
            }

            $user = JWTAuth::setToken($token)->toUser();

            if ($user->is_locked) {
                return response()->json(['status' => 'error', 'message' => 'Your account has been locked. Please contact support.'], 403);
            }

            [, $refreshToken] = $this->issueTokens($user);

            return response()->json([
                'status'        => 'success',
                'user_id'       => $user->user_id,
                'access_token'  => $token,
                'refresh_token' => $refreshToken,
                'user_type'     => $user->user_type,
            ]);
        }

        // OTP Login - Handshake A: request OTP
        if ($request->has('login_field') && !$request->has('otp_code')) {
            $request->validate(['login_field' => 'required|string']);

            $field = filter_var($request->login_field, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
            $user  = FlyUser::where($field, $request->login_field)->first();

            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'User identity not found.'], 404);
            }

            if ($user->is_locked) {
                return response()->json(['status' => 'error', 'message' => 'Your account has been locked. Please contact support.'], 403);
            }

            $otpCode = (string) random_int(10000, 99999);
            Log::info("Flybirds Login OTP for {$field}={$request->login_field}: [{$otpCode}]");

            if ($field === 'email') {
                Mail::to($user->email)->send(new SendOtpMail($user->name, $otpCode));
            } else {
                $sent = $this->sendWhatsAppOtp($user->phone, $otpCode, 'Fly Birds Login');

                if (!$sent) {
                    Log::error("WhatsApp login OTP failed to send for phone: {$user->phone}");
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Failed to dispatch OTP via WhatsApp. Please try again.',
                    ], 500);
                }
            }

            $loginOtpToken = $this->buildOtpToken(['user_id' => $user->user_id], $otpCode, 10);

            return response()->json([
                'status'          => 'success',
                'message'         => 'Login challenge OTP successfully dispatched.',
                'login_otp_token' => $loginOtpToken,
            ]);
        }

        // OTP Login - Handshake B: verify OTP
        if ($request->has('login_otp_token') && $request->has('otp_code')) {
            try {
                $payload = $this->decodeOtpToken($request->login_otp_token);

                if (!Hash::check($request->otp_code, $payload['hashed_otp'])) {
                    return response()->json(['status' => 'error', 'message' => 'Incorrect validation code.'], 401);
                }

                $user = FlyUser::find($payload['data']['user_id']);

                if (!$user) {
                    return response()->json(['status' => 'error', 'message' => 'User no longer exists.'], 404);
                }

                if ($user->is_locked) {
                    return response()->json(['status' => 'error', 'message' => 'Your account has been locked. Please contact support.'], 403);
                }

                [$token, $refreshToken] = $this->issueTokens($user);

                return response()->json([
                    'status'        => 'success',
                    'user_id'       => $user->user_id,
                    'access_token'  => $token,
                    'refresh_token' => $refreshToken,
                    'user_type'     => $user->user_type,
                ]);
            } catch (\RuntimeException $e) {
                Log::warning('Login OTP verify failed: ' . $e->getMessage());
                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
            } catch (\Exception $e) {
                Log::error('Login OTP verify unexpected error: ' . $e->getMessage());
                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
            }
        }

        return response()->json(['status' => 'error', 'message' => 'Bad request schema parameters.'], 400);
    }

    // ============================================================
    // 6. PROFILE UPDATE
    // ============================================================
    public function updateProfile(Request $request, $user_id)
    {
        $authenticatedUser = auth('api')->user();

        if (!$authenticatedUser) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized token action.'], 401);
        }

        if ($authenticatedUser->user_id !== $user_id && $authenticatedUser->user_type === 'user') {
            return response()->json(['status' => 'error', 'message' => 'Forbidden parameter assignment.'], 403);
        }

        $userToUpdate = FlyUser::where('user_id', $user_id)->first();

        if (!$userToUpdate) {
            return response()->json(['status' => 'error', 'message' => 'User record not found.'], 404);
        }

        if ($request->has('email')) {
            $request->validate([
                'email' => 'required|email|max:255|unique:fly_users,email,' . $user_id . ',user_id',
            ]);
        }

        $filteredData = $request->except(['phone', 'user_id', 'user_type', 'otp_verified_at']);

        if (isset($filteredData['password'])) {
            $filteredData['password'] = Hash::make($filteredData['password']);
        }

        $userToUpdate->update($filteredData);

        return response()->json([
            'status'  => 'success',
            'message' => 'Profile attributes successfully updated.',
            'data'    => $userToUpdate,
        ], 200);
    }

    // ============================================================
    // 7. GET USER INFO
    // ============================================================
    public function getUserInfo(Request $request, $user_id)
    {
        $authenticatedUser = auth('api')->user();

        if (!$authenticatedUser) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized or expired token.'], 401);
        }

        if ($authenticatedUser->user_id !== $user_id && $authenticatedUser->user_type === 'user') {
            return response()->json(['status' => 'error', 'message' => 'Forbidden. You do not have permission to read this resource.'], 403);
        }

        $user = FlyUser::where('user_id', $user_id)->first();

        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Account record not found.'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $user], 200);
    }

    // ============================================================
    // 8. ADMIN: CREATE ADMIN/MANAGER/FINANCE USER DIRECTLY (bypasses OTP)
    // ============================================================
    public function createAdminRoleUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'      => 'required|string|max:255',
            'email'     => 'required|string|email|max:255|unique:fly_users,email',
            'phone'     => 'required|string|max:15|unique:fly_users,phone',
            'password'  => 'required|string|min:6',
            'user_type' => 'required|string|in:superadmin,manager,finance',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $adminUser = FlyUser::create([
            'name'            => $request->name,
            'email'           => $request->email,
            'phone'           => $request->phone,
            'password'        => Hash::make($request->password),
            'user_type'       => $request->user_type,
            'otp_verified_at' => now(),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Administrative profile successfully provisioned!',
            'data'    => [
                'user_id'   => $adminUser->user_id,
                'name'      => $adminUser->name,
                'email'     => $adminUser->email,
                'user_type' => $adminUser->user_type,
            ],
        ], 201);
    }

    // ============================================================
    // 9. ADMIN: GET ALL USERS
    // ============================================================
    public function getAllUsers()
    {
        $users = FlyUser::orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'count'  => $users->count(),
            'data'   => $users,
        ], 200);
    }

    // ============================================================
    // 10. FORGOT PASSWORD - SEND OTP
    // ============================================================
    public function sendPasswordResetOtp(Request $request)
    {
        $request->validate([
            'login_field' => 'required|string',
        ]);

        $field = filter_var($request->login_field, FILTER_VALIDATE_EMAIL)
            ? 'email'
            : 'phone';

        $user = FlyUser::where($field, $request->login_field)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found.'
            ], 404);
        }

        $otpCode = (string) random_int(10000, 99999);
        Log::info("Password Reset OTP for {$field}={$request->login_field}: [{$otpCode}]");

        try {
            if ($field === 'email') {
                Mail::to($user->email)->send(
                    new SendOtpMail($user->name, $otpCode)
                );
            } else {
                $sent = $this->sendWhatsAppOtp($user->phone, $otpCode, 'Fly Birds Password Reset');

                if (!$sent) {
                    Log::error("WhatsApp password reset OTP failed to send for phone: {$user->phone}");
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Failed to dispatch OTP via WhatsApp.',
                    ], 500);
                }
            }

            $otpToken = $this->buildOtpToken([
                'user_id' => $user->user_id
            ], $otpCode, 15);

            return response()->json([
                'status' => 'success',
                'message' => 'OTP sent successfully.',
                'otp_token' => $otpToken
            ]);
        } catch (\Exception $e) {
            Log::error('Password Reset OTP Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send OTP.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ============================================================
    // 11. FORGOT PASSWORD - VERIFY OTP
    // ============================================================
    public function verifyPasswordResetOtp(Request $request)
    {
        $request->validate([
            'otp_token'    => 'required|string',
            'otp_code'     => 'required|string|digits:5',
            'new_password' => 'required|string|min:6',
        ]);

        try {
            $payload = $this->decodeOtpToken($request->otp_token);

            if (!Hash::check($request->otp_code, $payload['hashed_otp'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid OTP.'
                ], 401);
            }

            $user = FlyUser::where(
                'user_id',
                $payload['data']['user_id']
            )->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found.'
                ], 404);
            }

            $user->update([
                'password' => Hash::make($request->new_password)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Password reset successfully.'
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            Log::error('Password Reset Verify Error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }
}