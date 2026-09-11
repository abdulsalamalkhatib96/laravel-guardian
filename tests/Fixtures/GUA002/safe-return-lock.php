<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WalletReturnLock extends Model
{
    protected $table = 'wallets';
}

class WalletReturnLockService
{
    public function credit(int $walletId, int $amount): mixed
    {
        return DB::transaction(function () use ($walletId, $amount) {
            $wallet = WalletReturnLock::query()->whereKey($walletId)->lockForUpdate()->firstOrFail();
            $wallet->balance += $amount;
            $wallet->save();

            return $wallet;
        });
    }
}
