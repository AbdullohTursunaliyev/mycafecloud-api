<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ClientIdentity;
use App\Models\MobileToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => ['required','string'],
            'password' => ['required','string'],
        ]);

        $identity = ClientIdentity::where('login', $data['login'])->first();

        if (!$identity || !Hash::check($data['password'], $identity->password)) {
            throw ValidationException::withMessages([
                'login' => 'Неверный логин или пароль'
            ]);
        }

        $plain = Str::random(60);

        MobileToken::create([
            'identity_id' => $identity->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(7),
            'last_used_at' => now(),
        ]);

        return response()->json([
            'token' => $plain,
            'identity' => [
                'id' => $identity->id,
                'login' => $identity->login,
            ]
        ]);
    }

    public function me(Request $request)
    {
        $identity = $request->attributes->get('identity');

        return response()->json([
            'identity' => [
                'id' => $identity->id,
                'login' => $identity->login,
            ]
        ]);
    }

    public function logout(Request $request)
    {
        $auth = $request->header('Authorization');
        $plain = substr($auth, 7);
        $hash = hash('sha256', $plain);

        MobileToken::where('token_hash', $hash)->delete();

        return response()->json(['ok' => true]);
    }
}

