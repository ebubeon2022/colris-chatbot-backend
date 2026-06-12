<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|unique:users',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $email = $request->input('email');
        $allowedDomains = ['stu.cu.edu.ng', 'covenantuniversity.edu.ng'];
        $emailDomain = substr(strrchr($email, '@'), 1);
        if (!in_array($emailDomain, $allowedDomains)) {
            return response()->json([
                'message' => 'Only Covenant University email addresses are allowed (@stu.cu.edu.ng or @covenantuniversity.edu.ng).'
            ], 422);
        }

        $role = 'student';

        if ($request->input('admin_code', '') !== '') {
            if ($request->input('admin_code') !== env('ADMIN_REGISTRATION_CODE', 'COLRIS2025')) {
                return response()->json(['message' => 'Invalid admin code.'], 422);
            }
            $role = 'admin';
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $role,
            'email_verified' => true,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful',
            'token' => $token,
            'user' => $user,
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

    public function verifyOtp(Request $request)
    {
        return response()->json(['message' => 'OK']);
    }

    public function resendOtp(Request $request)
    {
        return response()->json(['message' => 'OK']);
    }
}
