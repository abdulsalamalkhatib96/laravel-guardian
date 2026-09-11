<?php

declare(strict_types=1);

namespace Guardian\Application\Console;

use Guardian\Application\Guardian;
use Illuminate\Console\Command;

final class ArtisanListCommand extends Command
{
    protected $signature = 'guardian:list';
    protected $description = 'List installed Guardian correctness rules';

    public function handle(Guardian $guardian): int
    {
        $rows = [];
        foreach ($guardian->registry()->rules() as $rule) {
            $rows[] = [(string) $rule->id(), strtoupper($rule->defaultSeverity()->value), $rule->title()];
        }
        $this->table(['Rule', 'Default severity', 'Title'], $rows);
        return self::SUCCESS;
    }
}
