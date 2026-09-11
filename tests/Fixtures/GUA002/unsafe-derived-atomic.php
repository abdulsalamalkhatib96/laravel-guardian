<?php

namespace Fixtures\GUA002\UnsafeDerivedAtomicCase;

use Illuminate\Database\Eloquent\Model;

final class Wallet extends Model {}

final class WalletService
{
    public function setBalance(int $walletId, int $target): void
    {
        $wallet = Wallet::findOrFail($walletId);
        $delta = $target - $wallet->balance;
        $wallet->increment('balance', $delta);
    }
}
