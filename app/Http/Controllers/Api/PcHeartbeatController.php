<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PcHeartbeatService;
use Illuminate\Http\Request;

class PcHeartbeatController extends Controller
{
    public function __construct(
        private readonly PcHeartbeatService $heartbeat,
    ) {
    }

    public function heartbeat(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required', 'string'],
            'pc_code' => ['required', 'string'],
            'metrics' => ['nullable', 'array'],
            'metrics.cpu_name' => ['nullable', 'string', 'max:200'],
            'metrics.ram_total_mb' => ['nullable', 'integer', 'min:0'],
            'metrics.gpu_name' => ['nullable', 'string', 'max:200'],
            'metrics.mac_address' => ['nullable', 'string', 'max:64'],
            'metrics.ip_address' => ['nullable', 'ip'],
            'metrics.disks' => ['nullable', 'array', 'max:12'],
            'metrics.disks.*.name' => ['nullable', 'string', 'max:10'],
            'metrics.disks.*.total_gb' => ['nullable', 'numeric', 'min:0'],
            'metrics.disks.*.free_gb' => ['nullable', 'numeric', 'min:0'],
            'metrics.disks.*.used_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return response()->json($this->heartbeat->heartbeat($data));
    }

    public function ack(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required', 'string'],
            'pc_code' => ['required', 'string'],
            'command_id' => ['required', 'integer'],
            'status' => ['required', 'string', 'in:done,failed'],
            'error' => ['nullable', 'string', 'max:2000'],
        ]);

        $response = $this->heartbeat->acknowledge($data);
        if (isset($response['status_code'])) {
            $statusCode = (int) $response['status_code'];
            unset($response['status_code']);

            return response()->json($response, $statusCode);
        }

        return response()->json($response);
    }
}
