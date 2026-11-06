<?php

declare(strict_types=1);

namespace Arcjet;

enum RuleState: int
{
    case Unspecified = 0;
    case Run = 1;
    case NotRun = 2;
    case DryRun = 3;
    case Cached = 4;
}
