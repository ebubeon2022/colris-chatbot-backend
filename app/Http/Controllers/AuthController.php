<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use App\Mail\OtpMail;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|unique:users',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $role = 'student';

        if ($request->input('admin_code', '') !== '') {
            if ($request->input('admin_code') !== env('ADMIN_REGISTRATION_CODE', 'COLRIS2025')) {
                return response()->json(['message' => 'Invalid admin code.'], 422);
            }
            $role = 'admin';
        }

        User::where('email', $request->email)->where('email_verified', false)->delete();

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $role,
            'email_verified' => false,
        ]);

        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('otps')->where('email', $request->email)->delete();
        DB::table('otps')->insert([
            'email' => $request->email,
            'otp' => $otp,
            'expires_at' => now()->addMinutes(10),
            'used' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mailSent = false;
        try {
            Mail::to($request->email)->send(new OtpMail($otp, $user->name));
            $mailSent = true;
        } catch (\Exception $e) {
            \Log::error('Mail failed: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Registration successful',
            'email' => $request->email,
            'otp_hint' => $mailSent ? null : $otp,
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string',
        ]);

        $otpRecord = DB::table('otps')
            ->where('email', $request->email)
            ->where('otp', $request->otp)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$otpRecord) {
            return response()->json(['message' => 'Invalid or expired OTP'], 422);
        }

        DB::table('otps')->where('id', $otpRecord->id)->update(['used' => true]);
        DB::table('users')->where('email', $request->email)->update(['email_verified' => true]);

        $user = User::where('email', $request->email)->first();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Email verified successfully',
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function resendOtp(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->where('email_verified', false)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found or already verified'], 422);
        }

        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('otps')->where('email', $request->email)->delete();
        DB::table('otps')->insert([
            'email' => $request->email,
            'otp' => $otp,
            'expires_at' => now()->addMinutes(10),
            'used' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mailSent = false;
        try {
            Mail::to($request->email)->send(new OtpMail($otp, $user->name));
            $mailSent = true;
        } catch (\Exception $e) {
            \Log::error('Mail failed: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'OTP resent',
            'otp_hint' => $mailSent ? null : $otp,
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (!$user->email_verified) {
            return response()->json(['message' => 'Please verify your email first'], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }
}
