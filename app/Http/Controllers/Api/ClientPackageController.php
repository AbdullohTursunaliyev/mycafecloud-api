<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientTransaction;
use App\Models\Package;
use App\Models\Shift;
use App\Models\PackageSale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientPackageController extends Controller
{
    /**
     * POST /clients/{id}/packages/attach
     * body: { package_id, payment_method }  // payment_method: balance|cash|card
     */
    public function attach(Request $request, $id)
    {
        $request->validate([
            'package_id' => 'required|integer',
            'payment_method' => 'required|string|in:balance,cash,card',
        ]);

        $operator = $request->user('operator'); // auth:operator
        $tenantId = $operator->tenant_id;

        $client = Client::where('tenant_id', $tenantId)->findOrFail($id);

        $package = Package::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->findOrFail((int)$request->package_id);

        $payMethod = (string)$request->payment_method;
        $amount = (int)$package->price;

        return DB::transaction(function () use ($operator, $tenantId, $client, $package, $payMethod, $amount) {

            $shiftId = null;

            // 0) Agar clientda hali tugamagan aktiv paket bo‘lsa — yangi paket qo‘shilmasin
            $activePackage = DB::table('client_packages as cp')
                ->join('packages as p', 'p.id', '=', 'cp.package_id')
                ->where('cp.tenant_id', $tenantId)
                ->where('cp.client_id', $client->id)
                ->where('cp.status', 'active')
                ->where('cp.remaining_min', '>', 0)
                ->where(function ($q) {
                    $q->whereNull('cp.expires_at')
                        ->orWhere('cp.expires_at', '>', now());
                })
                ->where('p.zone', $package->zone) // <<< faqat shu zonada bloklaydi
                ->lockForUpdate()
                ->first();

            if ($activePackage) {
                return response()->json([
                    'message' => 'У клиента уже есть активный пакет на эту зону. Сначала дождитесь окончания пакета, потом можно добавить новый.',
                ], 422);
            }

            // 1) BALANCE: client balansidan yechamiz
            if ($payMethod === 'balance') {
                if ((int)$client->balance < $amount) {
                    return response()->json([
                        'message' => 'Недостаточно средств на балансе клиента.',
                    ], 422);
                }

                $client->balance = (int)$client->balance - $amount;
                $client->save();
            }

            // 2) CASH/CARD: shift ochiq bo‘lishi shart + shift_id bog‘laymiz
            if ($payMethod === 'cash' || $payMethod === 'card') {
                $shift = Shift::query()
                    ->where('tenant_id', $tenantId)
                    ->whereNull('closed_at')
                    ->latest('id')
                    ->first();

                if (!$shift) {
                    return response()->json([
                        'message' => 'Смена закрыта. Нельзя оформить пакет через кассу.',
                    ], 422);
                }

                $shiftId = $shift->id;

                // buni qoldirish mumkin (analytics uchun), lekin endi kassada TX orqali ham ko‘rinadi
                if ($payMethod === 'cash') {
                    $shift->increment('packages_cash_total', $amount);
                } else {
                    $shift->increment('packages_card_total', $amount);
                }
            }

            // ✅ EXPIRES: 24 soat emas.
            // Variant A (tavsiya): expires_at NULL (agar nullable bo‘lsa)
            $expiresAt = null;

            // Variant B (agar DBda expires_at NOT NULL bo‘lsa): juda uzoq muddat qo‘yib turamiz
            // $expiresAt = now()->addYears(5);

            $clientPackageId = DB::table('client_packages')->insertGetId([
                'tenant_id' => $tenantId,
                'client_id' => $client->id,
                'package_id' => $package->id,
                'remaining_min' => (int)$package->duration_min,
                'expires_at' => $expiresAt,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ✅ MUHIM: ClientTransaction yozamiz (kassa/summary shundan hisoblaydi)
            ClientTransaction::create([
                'tenant_id' => $tenantId,
                'client_id' => $client->id,
                'operator_id' => $operator->id,
                'shift_id' => $shiftId,           // cash/card bo‘lsa bor, balance bo‘lsa null
                'type' => 'package',              // <<< MUHIM
                'amount' => $amount,
                'bonus_amount' => 0,
                'payment_method' => $payMethod,   // balance/cash/card
                'comment' => 'Покупка пакета: ' . $package->name,
                'promotion_id' => null,
            ]);

            // sale yozuvi (audit + report)
            PackageSale::create([
                'tenant_id' => $tenantId,
                'client_id' => $client->id,
                'package_id' => $package->id,
                'payment_method' => $payMethod,
                'shift_id' => $shiftId,
                'operator_id' => $operator->id,
                'amount' => $amount,
                'meta' => [
                    'client_package_id' => $clientPackageId,
                    'package_name' => $package->name,
                    'zone' => $package->zone,
                    'duration_min' => $package->duration_min,
                ],
            ]);

            $client->refresh();

            return response()->json([
                'data' => [
                    'client' => $client,
                    'client_package_id' => $clientPackageId,
                    'package' => $package,
                    'payment_method' => $payMethod,
                    'amount' => $amount,
                    'shift_id' => $shiftId,
                ],
            ]);
        });
    }
}
