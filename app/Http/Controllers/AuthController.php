<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required']);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Wrong email or password'], 422);
        }
        $plain = Str::random(40);
        $user->forceFill(['api_token' => hash('sha256', $plain)])->save();

        return ['token' => $plain, 'user' => ['name' => $user->name, 'email' => $user->email]];
    }

    public function logout(Request $r)
    {
        $r->user()->forceFill(['api_token' => null])->save();

        return ['ok' => true];
    }
}
