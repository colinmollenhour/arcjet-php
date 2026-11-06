<?php

declare(strict_types=1);

namespace Arcjet;

/** Failure origin, not the application's enforcement action. Missing origin is fail-closed. */
enum FailureKind: string
{
    case Request = 'REQUEST';
    case Internal = 'INTERNAL';
    case Transport = 'TRANSPORT';
    case Service = 'SERVICE';
}
