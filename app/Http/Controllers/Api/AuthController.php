<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlyUser;
use App\Mail\SendOtpMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;        // FIXED: Corrected namespace path without the structural typo!
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    // 1. REGISTER INIT: Accepts Name & Email -> Dispatches Hostinger SMTP OTP Email
    public function registerInit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:fly_users,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        // Cryptographically generate a random 5-digit verification code
        $otpCode = (string) random_int(10000, 99999);

        // Appends the plain text OTP to storage/logs/laravel.log for simple sandbox testing
        Log::info("Flybirds Registration Sandbox Verification Code for {$request->email}: [ {$otpCode} ]");

        try {
            // Deploy tracking email via your verified Hostinger support channel configuration
            Mail::to($request->email)->send(new SendOtpMail($request->name, $otpCode));
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to dispatch email via Hostinger server. Check SMTP credentials configuration.',
                'error' => $e->getMessage()
            ], 500);
        }

        // Encrypt temporary payload state variables inside a short-lived 15-minute token wrapper
        $otpTokenPayload = [
            'registration_data' => [
                'name' => $request->name,
                'email' => $request->email,
                'phone' => 'NOT_SET_YET',
                'password' => Hash::make(Str::random(16)), // FIXED: Now runs smoothly
                'user_type' => 'user',
            ],
            'hashed_otp' => bcrypt($otpCode),
            'exp' => time() + (15 * 60)
        ];

        $otpToken = JWTAuth::getJWTProvider()->encode($otpTokenPayload);

        return response()->json([
            'status' => 'success',
            'message' => 'OTP dispatched successfully from support@flybirdsleggins.com to your email inbox.',
            'otp_token' => $otpToken
        ], 200);
    }

    // 2. REGISTER VERIFY: Matches code + temporary token -> Saves user -> Returns explicit 21-day tokens
    public function registerVerify(Request $request)
    {
        $request->validate([
            'otp_token' => 'required|string',
            'otp_code' => 'required|string|digits:5'
        ]);

        try {
            $payload = JWTAuth::getJWTProvider()->decode($request->otp_token);

            if (!Hash::check($request->otp_code, $payload['hashed_otp'])) {
                return response()->json(['status' => 'error', 'message' => 'Invalid or incorrect OTP code.'], 401);
            }

            $regData = $payload['registration_data'];

            $user = FlyUser::create([
                'name' => $regData['name'],
                'email' => $regData['email'],
                'phone' => $regData['phone'],
                'password' => $regData['password'],
                'user_type' => $regData['user_type'],
                'otp_verified_at' => now(),
            ]);

            // Authorize user and issue application tokens
            $accessToken = JWTAuth::fromUser($user);
            $refreshToken = JWTAuth::customClaims(['is_refresh' => true, 'exp' => time() + (365 * 24 * 60 * 60)])->fromUser($user);

            return response()->json([
                'status' => 'success',
                'message' => 'Email verification complete. Account successfully created!',
                'user_id' => $user->user_id,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'OTP token session has expired.'], 400);
        }
    }

    // 3. UNIFIED LOGIN: Supports both standard password check and dynamic OTP verification
    public function login(Request $request)
    {
        if ($request->has('password')) {
            $request->validate([
                'login_field' => 'required|string',
                'password' => 'required|string',
            ]);

            $field = filter_var($request->login_field, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

            if (!$token = JWTAuth::attempt([$field => $request->login_field, 'password' => $request->password])) {
                return response()->json(['status' => 'error', 'message' => 'Invalid matching credentials.'], 401);
            }

            $user = JWTAuth::setToken($token)->toUser();
            $refreshToken = JWTAuth::customClaims(['is_refresh' => true, 'exp' => time() + (365 * 24 * 60 * 60)])->fromUser($user);

            return response()->json([
                'status' => 'success',
                'access_token' => $token,
                'refresh_token' => $refreshToken,
                'user_type' => $user->user_type
            ]);
        }

        // OTP Login Flow - Handshake A
        if ($request->has('login_field') && !$request->has('otp_code')) {
            $request->validate(['login_field' => 'required|string']);
            $field = filter_var($request->login_field, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
            $user = FlyUser::where($field, $request->login_field)->first();

            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'User identity not discovered.'], 444);
            }

            $otpCode = (string) random_int(10000, 99999);
            Log::info("Flybirds Login Challenge Verification Code for {$user->email}: [ {$otpCode} ]");

            if ($field === 'email') {
                Mail::to($user->email)->send(new SendOtpMail($user->name, $otpCode));
            }

            $loginTokenPayload = [
                'user_id' => $user->user_id,
                'hashed_otp' => bcrypt($otpCode),
                'exp' => time() + (10 * 60)
            ];
            $loginOtpToken = JWTAuth::getJWTProvider()->encode($loginTokenPayload);

            return response()->json([
                'status' => 'success',
                'message' => 'Login challenge OTP successfully dispatched.',
                'login_otp_token' => $loginOtpToken
            ]);
        }

        // OTP Login Flow - Handshake B
        if ($request->has('login_otp_token') && $request->has('otp_code')) {
            try {
                $payload = JWTAuth::getJWTProvider()->decode($request->login_otp_token);

                if (!Hash::check($request->otp_code, $payload['hashed_otp'])) {
                    return response()->json(['status' => 'error', 'message' => 'Incorrect validation code.'], 401);
                }

                $user = FlyUser::find($payload['user_id']);

                $token = JWTAuth::fromUser($user);
                $refreshToken = JWTAuth::customClaims(['is_refresh' => true, 'exp' => time() + (365 * 24 * 60 * 60)])->fromUser($user);

                return response()->json([
                    'status' => 'success',
                    'access_token' => $token,
                    'refresh_token' => $refreshToken,
                    'user_type' => $user->user_type
                ]);
            } catch (\Exception $e) {
                return response()->json(['status' => 'error', 'message' => 'Verification window has expired.'], 400);
            }
        }

        return response()->json(['status' => 'error', 'message' => 'Bad request schema parameters.'], 400);
    }

    // 4. PROFILE UPDATE: Updates miscellaneous attributes via route user_id query parameters
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
            return response()->json(['status' => 'error', 'message' => 'User identity record matching parameter target not found.'], 444);
        }

        $filteredData = $request->except(['email', 'phone', 'user_id', 'user_type', 'otp_verified_at']);

        if (isset($filteredData['password'])) {
            $filteredData['password'] = Hash::make($filteredData['password']);
        }

        $userToUpdate->update($filteredData);

        return response()->json([
            'status' => 'success',
            'message' => 'Profile attributes successfully refreshed.',
            'data' => $userToUpdate
        ], 200);
    }


    public function getUserInfo(Request $request, $user_id)
    {
        $authenticatedUser = auth('api')->user();

        if (!$authenticatedUser) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized or expired token.'], 401);
        }

        // Security Enforcement: Regular buyers can ONLY see their own profile, Admins/Managers can fetch anyone
        if ($authenticatedUser->user_id !== $user_id && $authenticatedUser->user_type === 'user') {
            return response()->json(['status' => 'error', 'message' => 'Forbidden. You do not have permission to read this resource.'], 403);
        }

        $user = FlyUser::where('user_id', $user_id)->first();
        if (!$user) {
            return response()->json(['status' => 'error', 'message' => 'Account record not discovered.'], 444);
        }

        return response()->json([
            'status' => 'success',
            'data' => $user
        ], 200);
    }

    /**
     * API 2: Administrative Gateway to Create Superadmin & Management Users Natively (Bypasses OTP)
     * URL Path: POST /api/auth/admin/create-user
     */
    public function createAdminRoleUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:fly_users,email',
            'phone' => 'required|string|max:15|unique:fly_users,phone',
            'password' => 'required|string|min:6',
            'user_type' => 'required|string|in:superadmin,manager,finance' // Prevents creating generic 'user' here
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        // Create the administrative personnel directly into the database
        $adminUser = FlyUser::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'user_type' => $request->user_type,
            'otp_verified_at' => now(), // Instantly auto-verified since created by an administrator
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Administrative profile successfully provisioned!',
            'data' => [
                'user_id' => $adminUser->user_id,
                'name' => $adminUser->name,
                'email' => $adminUser->email,
                'user_type' => $adminUser->user_type
            ]
        ], 201);
    }

    /**
     * API 3: Get All Users (Crucial for Admin Management Dashboards)
     * URL Path: GET /api/auth/admin/users
     */
    public function getAllUsers()
    {
        $users = FlyUser::orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'count' => $users->count(),
            'data' => $users
        ], 200);
    }


    public function adminRegisterInit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:fly_users,email',
            'phone' => 'required|string|max:15|unique:fly_users,phone',
            'password' => 'required|string|min:6',
            'user_type' => 'required|string|in:superadmin,manager,finance' // Enforces admin level roles
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        // Cryptographically generate random 5-digit OTP
        $otpCode = (string) random_int(10000, 99999);

        // Testing Convenience: Outputs plain text OTP directly inside storage/logs/laravel.log
        Log::info("Flybirds ADMIN Registration Sandbox Verification Code for {$request->email}: [ {$otpCode} ]");

        try {
            // Dispatch verification email to target inbox
            Mail::to($request->email)->send(new SendOtpMail($request->name, $otpCode));
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to dispatch email via Hostinger server. Check SMTP logs.',
                'error' => $e->getMessage()
            ], 500);
        }

        // Secure state metrics into a short-lived 15-minute token signature wrapper
        $otpTokenPayload = [
            'registration_data' => [
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
                'user_type' => $request->user_type, // Captures: superadmin, manager, or finance
            ],
            'hashed_otp' => bcrypt($otpCode),
            'exp' => time() + (15 * 60)
        ];

        $otpToken = JWTAuth::getJWTProvider()->encode($otpTokenPayload);

        return response()->json([
            'status' => 'success',
            'message' => 'Admin registration OTP dispatched successfully from support@flybirdsleggins.com to your email inbox.',
            'otp_token' => $otpToken
        ], 200);
    }

    /**
     * ADMIN REGISTER STEP 2: Verify OTP -> Commits to DB with Alphanumeric Sequence Key -> Returns Access Tokens
     * URL Path: POST /api/auth/admin/register/verify
     */
    public function adminRegisterVerify(Request $request)
    {
        $request->validate([
            'otp_token' => 'required|string',
            'otp_code' => 'required|string|digits:5'
        ]);

        try {
            $payload = JWTAuth::getJWTProvider()->decode($request->otp_token);

            if (!Hash::check($request->otp_code, $payload['hashed_otp'])) {
                return response()->json(['status' => 'error', 'message' => 'Invalid or incorrect OTP code.'], 401);
            }

            $regData = $payload['registration_data'];

            // Double check to prevent malicious intercept injections from overriding role safety states
            if (!in_array($regData['user_type'], ['superadmin', 'manager', 'finance'])) {
                return response()->json(['status' => 'error', 'message' => 'Invalid structural role context block.'], 422);
            }

            // Create verified user instance inside database
            // Note: The model's booted definition automatically assigns sequential IDs like FYB-ADM-001
            $adminUser = FlyUser::create([
                'name' => $regData['name'],
                'email' => $regData['email'],
                'phone' => $regData['phone'],
                'password' => $regData['password'],
                'user_type' => $regData['user_type'],
                'otp_verified_at' => now(),
            ]);

            // Issue standard access token (21 days) and a dedicated refresh token wrapper
            $accessToken = JWTAuth::fromUser($adminUser);
            $refreshToken = JWTAuth::customClaims(['is_refresh' => true, 'exp' => time() + (365 * 24 * 60 * 60)])->fromUser($adminUser);

            return response()->json([
                'status' => 'success',
                'message' => 'Administrative account verified and created successfully!',
                'user_id' => $adminUser->user_id, // Outputs: FYB-ADM-XXX
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'OTP token verification window expired or corrupt state.'], 400);
        }
    }
}