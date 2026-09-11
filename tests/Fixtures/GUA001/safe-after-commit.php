<?php

namespace Fixtures\GUA001\SafeCase;

use Illuminate\Support\Facades\DB;

final class SendWebhookJob
{
    public static function dispatch(): object
    {
        return new class {
            public function afterCommit(): void {}
        };
    }
}

final class CheckoutService
{
    public function checkout(): void
    {
        DB::transaction(function (): void {
            SendWebhookJob::dispatch()->afterCommit();
        });
    }
}
