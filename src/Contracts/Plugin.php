<?php

declare(strict_types=1);

namespace Guardian\Contracts;

use Guardian\Application\GuardianRegistry;

interface Plugin
{
    public function register(GuardianRegistry $registry): void;
}
