<?php

declare(strict_types=1);

namespace Guardian\Application\Console;

use Guardian\Application\Guardian;
use Illuminate\Console\Command;

final class ArtisanExplainCommand extends Command
{
    protected $signature = 'guardian:explain {rule : Rule id, e.g. GUA-001}';
    protected $description = 'Explain a Guardian rule and its remediation strategies';

    public function handle(Guardian $guardian): int
    {
        $id = (string) $this->argument('rule');
        if ($guardian->registry()->findRule($id) === null) {
            $this->error("Unknown Guardian rule: {$id}");
            return self::FAILURE;
        }

        $file = dirname(__DIR__, 3).'/docs/rules/'.$id.'.md';
        if (is_file($file)) {
            $this->line((string) file_get_contents($file));
            return self::SUCCESS;
        }

        $this->line($id.' — '.$guardian->registry()->findRule($id)?->title());
        return self::SUCCESS;
    }
}
