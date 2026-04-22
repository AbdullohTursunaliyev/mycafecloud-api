<?php

namespace App\Http\Controllers;

use App\Services\LegacyShellService;
use Illuminate\Http\Request;

class ShellController extends Controller
{
    public function __construct(
        private readonly LegacyShellService $shell,
    ) {
    }

    public function sessionState(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required', 'string'],
            'pc_code' => ['required', 'string'],
        ]);

        return response()->json(
            $this->shell->sessionState(
                (string) $data['license_key'],
                (string) $data['pc_code'],
            )
        );
    }

    public function logout(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required', 'string'],
            'pc_code' => ['required', 'string'],
        ]);

        $this->shell->logout(
            (string) $data['license_key'],
            (string) $data['pc_code'],
        );

        return response()->json(['ok' => true]);
    }
}
