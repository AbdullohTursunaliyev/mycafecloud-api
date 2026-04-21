<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SaasAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required','email'],
            'password' => ['required','string'],
        ]);

        $admin = SuperAdmin::where('email', $data['email'])->first();

        if (!$admin || !Hash::check($data['password'], $admin->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (isset($admin->is_active) && !$admin->is_active) {
            return response()->json(['message' => 'Account disabled'], 403);
        }

        $token = $admin->createToken('saas')->plainTextToken;

        return response()->json([
            'token' => $token,
            'admin' => [
                'id' => $admin->id,
                'email' => $admin->email,
                'name' => $admin->name,
            ],
        ]);
    }


    public function me(Request $request)
    {
        $a = $request->user();
        return response()->json(['admin' => ['id'=>$a->id,'name'=>$a->name,'email'=>$a->email]]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['ok'=>true]);
    }
}

