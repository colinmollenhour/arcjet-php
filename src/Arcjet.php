<?php

declare(strict_types=1);

namespace Arcjet;

use Arcjet\Internal\DecideTransport;
use Arcjet\Internal\Proto;
use Arcjet\Internal\ProtectionException;
use Arcjet\Internal\RequestAdapter;
use Arcjet\Internal\ResponseDecoder;
use Arcjet\Internal\Validation;
use Arcjet\Rule\BotProtection;
use Arcjet\Rule\Shield;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/** Synchronous remote-authoritative client. Rule composition never mutates an existing client. */
final readonly class Arcjet
{
    public const string VERSION = '0.1.0-dev';

    /** @param list<Shield|BotProtection> $rules */
    public function __construct(
        #[\SensitiveParameter] private string $key,
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private array $rules = [],
        private ?LoggerInterface $logger = null,
        private Options $options = new Options(),
    ) {
        if ($key === '' || strlen($key) > 4096 || preg_match('/[^\x21-\x7e]/', $key)) {
            throw new \InvalidArgumentException('The Arcjet key must be a nonempty printable ASCII token without spaces.');
        }
        Validation::rules($rules);
    }

    public function withRule(Shield|BotProtection $rule): self
    {
        return new self($this->key, $this->httpClient, $this->requestFactory, $this->streamFactory, [...$this->rules, $rule], $this->logger, $this->options);
    }

    public function protect(ServerRequestInterface $request, ?string $ip = null, ?string $correlationId = null): Decision
    {
        try {
            $details = (new RequestAdapter($this->options, $this->key))->convert($request, $ip, $correlationId);
            $rules = [];
            foreach ($this->rules as $rule) {
                $proto = new Proto\Rule();
                if ($rule instanceof Shield) {
                    $proto->setShield((new Proto\ShieldRule())->setMode($rule->mode->value)->setCharacteristics($rule->characteristics));
                } else {
                    $proto->setBotV2((new Proto\BotV2Rule())->setMode($rule->mode->value)->setAllow($rule->allow ?? [])->setDeny($rule->deny ?? []));
                }
                $rules[] = $proto;
            }
            $payload = (new Proto\DecideRequest())
                ->setSdkStack(Proto\SDKStack::SDK_STACK_UNSPECIFIED)
                ->setSdkVersion(self::VERSION)
                ->setDetails($details)
                ->setRules($rules)
                ->serializeToString();
            $transport = new DecideTransport($this->httpClient, $this->requestFactory, $this->streamFactory, $this->options, $this->key);
            return ResponseDecoder::decode($transport->send($payload));
        } catch (ProtectionException $error) {
            return $this->failure($error->errorCode, $error->getMessage());
        } catch (\Throwable) {
            return $this->failure('runtime_error', 'Arcjet protection failed while preparing, sending, or reading the decision.');
        }
    }

    private function failure(string $code, string $message): Decision
    {
        try {
            // Never include exception objects/messages, request headers, response
            // payloads, key, URL, or arbitrary caller/service data in logs.
            $this->logger?->error('Arcjet protection failed.', ['code' => $code]);
        } catch (\Throwable) {
            // An unavailable/misbehaving logger must not change security policy.
        }
        return Decision::error($code, $message);
    }

    /** @return array{version: string, rules: int} */
    public function __debugInfo(): array
    {
        return ['version' => self::VERSION, 'rules' => count($this->rules)];
    }
}
