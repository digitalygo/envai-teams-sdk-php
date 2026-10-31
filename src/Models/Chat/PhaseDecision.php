<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

enum PhaseDecision: string
{
    case Blocked = 'BLOCKED';
    case NotBlocked = 'NOT BLOCKED';
}
