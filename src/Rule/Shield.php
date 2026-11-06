<?php

declare(strict_types=1);

namespace Arcjet\Rule;

use Arcjet\Internal\Validation;
use Arcjet\Mode;

final readonly class Shield
{
    /** @param list<string> $characteristics */
    public function __construct(public Mode $mode = Mode::DryRun, public array $characteristics = [])
    {
        Validation::stringList($characteristics, 'characteristics');
    }
}
