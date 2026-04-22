<?php

namespace App\Services;

use App\Enums\PaymentMethod;

class PaymentAggregationService
{
    public function salesSummary($query, array $types): object
    {
        $typesSql = $this->quotedList($types);
        $cash = $this->quote(PaymentMethod::Cash->value);
        $card = $this->quote(PaymentMethod::Card->value);
        $balance = $this->quote(PaymentMethod::Balance->value);

        return $query->selectRaw("
            COALESCE(SUM(CASE WHEN payment_method = {$cash} AND type IN ({$typesSql}) THEN amount ELSE 0 END), 0) AS cash_sales_total,
            COALESCE(SUM(CASE WHEN payment_method = {$card} AND type IN ({$typesSql}) THEN amount ELSE 0 END), 0) AS card_sales_total,
            COALESCE(SUM(CASE WHEN payment_method = {$balance} AND type IN ({$typesSql}) THEN amount ELSE 0 END), 0) AS balance_sales_total
        ")->first();
    }

    public function salesByTypeAndMethod($query, array $types, ?array $methods = null): object
    {
        $methods ??= [PaymentMethod::Cash->value, PaymentMethod::Card->value];

        $selects = [];
        foreach ($methods as $method) {
            $quotedMethod = $this->quote($method);
            foreach ($types as $type) {
                $quotedType = $this->quote($type);
                $selects[] = sprintf(
                    "COALESCE(SUM(CASE WHEN type = %s AND payment_method = %s THEN amount ELSE 0 END), 0) AS %s_%s",
                    $quotedType,
                    $quotedMethod,
                    $method,
                    $type,
                );
            }
        }

        return $query->selectRaw(implode(",\n", $selects))->first();
    }

    public function returnSummary($query, bool $absolute = false): object
    {
        $cash = $this->quote(PaymentMethod::Cash->value);
        $card = $this->quote(PaymentMethod::Card->value);
        $balance = $this->quote(PaymentMethod::Balance->value);
        $amountExpr = $absolute ? 'ABS(amount)' : 'amount';

        return $query->selectRaw("
            COALESCE(SUM(CASE WHEN payment_method = {$cash} THEN {$amountExpr} ELSE 0 END), 0) AS returns_cash_total,
            COALESCE(SUM(CASE WHEN payment_method = {$card} THEN {$amountExpr} ELSE 0 END), 0) AS returns_card_total,
            COALESCE(SUM(CASE WHEN payment_method = {$balance} THEN {$amountExpr} ELSE 0 END), 0) AS returns_balance_total
        ")->first();
    }

    public function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function quotedList(array $values): string
    {
        return implode(', ', array_map(fn(string $value) => $this->quote($value), $values));
    }
}
