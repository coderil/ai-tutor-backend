<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Register
     * 
     * Register user
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:50', 'unique:users,username'],
            'password' => ['required', 'string', 'min:5', 'confirmed'],
        ]);

        $user = User::create([
            'username' => $validated['username'],
            'password' => Hash::make($validated['password']),
        ]);

        return ApiResponse::success(
            'User registered successfully.',
            new UserResource($user),
            201
        );
    }

    /**
     * Login
     * 
     * Login to registered account
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string'
        ]);

        if (! Auth::attempt($validated)) {
            return ApiResponse::error(message: 'Invalid Credentials', statusCode: 401);
        }

        $user = $request->user();
        $token = $user->createToken('auth-token')->plainTextToken;

        return ApiResponse::success(
            'User logged in successfully',
            [
                'user'  => new UserResource($user),
                'token' => $token,
                'role'  => $user->getRoleNames()->first()
            ]
        );
    }

    /**
     * Logout
     * 
     * Logout to registered account
     */
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return ApiResponse::success('User logged out successfully');
    }
}
