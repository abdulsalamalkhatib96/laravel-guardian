<?php

namespace Fixtures\GUA001\UnsafeCase;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class PaymentGateway
{
    public function capture(): void
    {
        Http::post('https://payments.example.test/capture', ['amount' => 100]);
    }
}

final class CheckoutService
{
    public function __construct(private PaymentGateway $gateway) {}

    public function checkout(): void
    {
        DB::transaction(function (): void {
            $this->gateway->capture();
        });
    }
}
