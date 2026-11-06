<?php

declare(strict_types=1);

namespace Arcjet;

enum Conclusion: int
{
    case Unspecified = 0;
    case Allow = 1;
    case Deny = 2;
    case Challenge = 3;
    case Error = 4;
}
