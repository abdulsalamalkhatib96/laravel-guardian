<?php

namespace Fixtures\GUA002\SafeLockCase;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class Wallet extends Model
{
    protected $guarded = [];
}

final class WalletService
{
    public function credit(int $walletId, int $amount): void
    {
        DB::transaction(function () use ($walletId, $amount): void {
            $wallet = Wallet::query()->lockForUpdate()->findOrFail($walletId);
            $wallet->balance += $amount;
            $wallet->save();
        });
    }
}
