<?php

namespace Fixtures\GUA002\SafeAtomicCase;

use Illuminate\Database\Eloquent\Model;

final class Wallet extends Model {}

final class WalletService
{
    public function credit(int $walletId, int $amount): void
    {
        Wallet::whereKey($walletId)->increment('balance', $amount);
    }
}
