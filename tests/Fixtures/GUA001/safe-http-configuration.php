<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class HttpConfigurationOnlyService
{
    public function configure(): void
    {
        DB::transaction(function (): void {
            $request = Http::withHeaders(['X-Test' => '1'])->timeout(5);
        });
    }
}
