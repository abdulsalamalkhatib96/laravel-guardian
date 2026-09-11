<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class FluentHttpPaymentService
{
    public function charge(): void
    {
        DB::transaction(function (): void {
            Http::withHeaders(['X-Test' => '1'])->timeout(5)->post('https://example.test/pay');
        });
    }
}
