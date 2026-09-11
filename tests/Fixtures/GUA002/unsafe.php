<?php

namespace Fixtures\GUA002\UnsafeCase;

use Illuminate\Database\Eloquent\Model;

final class Wallet extends Model
{
    protected $guarded = [];
}

final class WalletService
{
    public function credit(int $walletId, int $amount): void
    {
        $wallet = Wallet::findOrFail($walletId);
        $wallet->balance += $amount;
        $wallet->save();
    }
}
