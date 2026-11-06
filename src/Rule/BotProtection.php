<?php

declare(strict_types=1);

namespace Arcjet\Rule;

use Arcjet\Internal\Validation;
use Arcjet\Mode;

final readonly class BotProtection
{
    /**
     * @param list<string>|null $allow
     * @param list<string>|null $deny
     */
    public function __construct(
        public ?array $allow = null,
        public ?array $deny = null,
        public Mode $mode = Mode::DryRun,
    ) {
        if (($allow === null) === ($deny === null)) {
            throw new \InvalidArgumentException('Specify exactly one of allow or deny, including an empty list.');
        }
        if ($deny === []) {
            throw new \InvalidArgumentException('An empty deny list is ambiguous on the Decide protocol; use an empty rule list to disable bot protection.');
        }
        $entities = $allow ?? $deny ?? [];
        Validation::stringList($entities, 'bot identifiers');
        foreach ($entities as $entity) {
            if ($entity === 'SIGNED_BOT') {
                throw new \InvalidArgumentException('SIGNED_BOT requires an HTTPS signature directory URL.');
            }
            if (str_starts_with($entity, 'SIGNED_BOT:')) {
                $url = substr($entity, strlen('SIGNED_BOT:'));
                Validation::httpsUrl($url);
                if (parse_url($url, PHP_URL_PATH) !== '/.well-known/http-message-signatures-directory') {
                    throw new \InvalidArgumentException('SIGNED_BOT requires the well-known HTTP message signature directory path.');
                }
            }
        }
    }
}
