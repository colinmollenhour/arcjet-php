<?php

declare(strict_types=1);

namespace Arcjet;

enum Mode: int
{
    case DryRun = 1;
    case Live = 2;
}
