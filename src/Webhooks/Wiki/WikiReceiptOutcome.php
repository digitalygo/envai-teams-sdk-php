<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks\Wiki;

enum WikiReceiptOutcome: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
    case Collision = 'collision';
}
