<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

enum WebhookOutcome: string
{
    case Dispatched = 'dispatched';
    case Duplicate = 'duplicate';
    case Collision = 'collision';
    case ClaimContended = 'claim_contended';
    case InvalidScope = 'invalid_scope';
    case ValidationFailed = 'validation_failed';
    case VerificationFailed = 'verification_failed';
    case IdentityInvalid = 'identity_invalid';
    case StoreFailed = 'store_failed';
    case DispatchFailed = 'dispatch_failed';
    case MarkDispatchedFailed = 'mark_dispatched_failed';
    case MarkFailed = 'mark_failed';
}
