<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OtpService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class OtpAuthController extends Controller
{
    public function __construct(
        protected OtpService $otpService,
        protected WhatsAppService $whatsAppService
    ) {}

    public function sendOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|regex:/^[0-9]{10,15}$/',
        ]);

        $phone = $request->phone;
        $ip = $request->ip();

        $key = "otp-send-ip:{$ip}";
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Too many OTP requests from this IP. Try again in " . ceil($seconds / 60) . " minute(s)."
            ], 429);
        }
        RateLimiter::hit($key, 3600);

        $otp = $this->otpService->generate($phone);
        $waPhone = '91' . ltrim($phone, '0');

        $template = config('whatsapp_templates.otp');

        $sent = $this->whatsAppService->sendTemplateMessage(
            to: $waPhone,
            templateName: $template['name'],
            language: $template['language'],
            bodyParams: [$otp, 'Fly Birds Login'],
            buttons: [
                ['type' => 'button', 'sub_type' => 'url', 'text' => $otp],
            ]
        );

        if (!$sent) {
            return response()->json(['message' => 'Failed to send OTP'], 500);
        }

        return response()->json(['message' => 'OTP sent successfully']);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'otp' => 'required|string|size:6',
        ]);

        $valid = $this->otpService->verify($request->phone, $request->otp);

        if (!$valid) {
            return response()->json(['message' => 'Invalid or expired OTP'], 422);
        }

        return response()->json(['message' => 'OTP verified']);
    }
}