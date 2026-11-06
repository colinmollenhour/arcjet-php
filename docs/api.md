# API reference

All public classes use the `Arcjet` namespace unless another namespace is shown. Configuration and returned value objects are immutable. Generated classes under `Arcjet\Internal` are not public API. Signature blocks use reference notation; runnable usage is in the [README](../README.md) and [examples](../examples/).

## Client

```php
new Arcjet\Arcjet(
    string $key,
    Psr\Http\Client\ClientInterface $httpClient,
    Psr\Http\Message\RequestFactoryInterface $requestFactory,
    Psr\Http\Message\StreamFactoryInterface $streamFactory,
    array $rules = [],
    ?Psr\Log\LoggerInterface $logger = null,
    Arcjet\Options $options = new Arcjet\Options(),
);

Arcjet\Arcjet::protect(
    Psr\Http\Message\ServerRequestInterface $request,
    ?string $ip = null,
    ?string $correlationId = null,
): Arcjet\Decision;

Arcjet\Arcjet::withRule(
    Arcjet\Rule\BotProtection|Arcjet\Rule\Shield $rule,
): Arcjet\Arcjet;
```

| Parameter | Meaning |
| --- | --- |
| `key` | Nonempty printable ASCII token without spaces, at most 4,096 bytes. Load the site key from `ARCJET_KEY` or a secret manager. Never derive it from the incoming request. |
| `httpClient` | Required PSR-18 implementation. Configure real timeouts, verified TLS, and disabled redirects on this client. |
| `requestFactory`, `streamFactory` | Required PSR-17 factories for the outbound request and protobuf body. |
| `rules` | Ordered list of `BotProtection` and/or `Shield` objects. Default `[]` requests a remote decision and optional IP enrichment without these protection rules. It is not an offline lookup. |
| `logger` | Optional PSR-3 logger. Runtime failure logging excludes keys and raw request/response data. |
| `options` | Omission uses the defaults below; `null` is not accepted. |
| `request` | Incoming PSR-7 server request, including trusted server parameters. The SDK does not modify its headers or attributes. |
| `ip` | Optional application-controlled, validated IPv4/IPv6 override. Do not pass an untrusted HTTP header directly. |
| `correlationId` | Optional 1–256 printable ASCII bytes, sent in the dedicated protocol field. Request-ID headers are not implicitly trusted or copied into this field. |

`protect()` makes one synchronous remote Decide call when request adaptation succeeds. It does not retry, evaluate rules locally, cache a decision, or send a Report RPC. `withRule()` returns a separate client with the rule appended; the original client is unchanged. `Arcjet::VERSION` is the SDK's reported version (`'0.1.0-dev'` in this checkout), not a published-release guarantee.

Invalid configuration throws `InvalidArgumentException` at construction or `withRule()`. Runtime request, transport, and protocol failures return an `ERROR` decision; they do not become `ALLOW`. See [failure behavior](#failure-behavior).

## Options

```php
new Arcjet\Options(
    string $baseUrl = 'https://decide.arcjet.com',
    int $deadlineMs = 500,
    array $trustedProxies = [],
    bool $includeBody = false,
    int $maxBodyBytes = 65536,
    int $maxResponseBytes = 1048576,
    array $redactHeaders = [],
);
```

Each parameter is a readonly public property.

| Property | Constraints and behavior |
| --- | --- |
| `baseUrl` | Absolute HTTPS origin or base path, without user information, query, fragment, whitespace, or backslashes. The Decide RPC path is appended. Treat this as trusted deployment configuration: the destination receives the site key and request metadata. |
| `deadlineMs` | Integer from 1 through 9,999,999,999. Sent as `Connect-Timeout-Ms`; it does **not** enforce a local deadline. |
| `trustedProxies` | List of exact IPv4/IPv6 addresses or CIDRs. Empty means no proxy is trusted. Invalid entries throw. See [IP address extraction](#ip-address-extraction). |
| `includeBody` | Opt-in disclosure of the exact request body bytes. Default `false` leaves the body unread. See [request disclosure](#request-disclosure). |
| `maxBodyBytes` | Inclusive request-body byte limit, 0 through 1,048,576. Oversized bodies fail rather than truncate. |
| `maxResponseBytes` | Inclusive response-body byte limit, 1 through 1,048,576. Oversized responses return `ERROR`. |
| `redactHeaders` | List of additional HTTP header names to omit, case-insensitively. `Authorization` and `Proxy-Authorization` are always omitted. |

The whole serialized protection request has a separate fixed 1 MiB cap. The limits do not impose a portable memory or wall-clock bound on the injected HTTP client's own response buffering. Configure the transport's timeouts and resource limits separately.

The [README transport example](../README.md#protect-an-http-request) uses Symfony's PSR-18 adapter with TLS verification, bounded inactivity and overall timeouts, and `max_redirects: 0`. PSR-18 itself has no settings for these requirements. A redirected request or unverified TLS connection can disclose the key; the deadline header alone cannot stop a blocked worker.

### Remote transport

The SDK sends `POST {baseUrl}/proto.decide.v1alpha1.DecideService/Decide` with an unframed protobuf body and these headers:

| Header | Value |
| --- | --- |
| `Authorization` | `Bearer` followed by the configured key. |
| `Content-Type`, `Accept` | `application/proto` |
| `Connect-Protocol-Version` | `1` |
| `Connect-Timeout-Ms` | Decimal `deadlineMs`. |
| `User-Agent` | `arcjet-php/` followed by `Arcjet::VERSION`. |

Successful responses require HTTP 200 and `application/proto` content type; media-type parameters are permitted. The SDK does not advertise compression and rejects response content encodings other than absent or `identity`. Non-200 responses become generic `http_status` errors; their bodies are not exposed as diagnostics.

## Rules and modes

```php
new Arcjet\Rule\BotProtection(
    ?array $allow = null,
    ?array $deny = null,
    Arcjet\Mode $mode = Arcjet\Mode::DryRun,
);

new Arcjet\Rule\Shield(
    Arcjet\Mode $mode = Arcjet\Mode::DryRun,
    array $characteristics = [],
);
```

Each constructor parameter is a readonly public property. Arrays are lists, not associative arrays.

### Bot Protection

Exactly one of `allow` or `deny` must be non-null.

| Configuration | Meaning for detected bots |
| --- | --- |
| `allow: ['CATEGORY:SEARCH_ENGINE']` | Permits matching bots; denies other detected bots. |
| `allow: []` | Denies every detected bot. This does not itself classify every browser request as a bot. |
| `deny: ['CATEGORY:AI']` | Denies matching bots; permits other detected bots. |
| `deny: []` | Rejected. Empty allow and empty deny have indistinguishable repeated fields on the upstream wire protocol. Omit the Bot Protection rule to disable it. |
| Both lists or neither list | Rejected. |

Identifiers accept uppercase bot names matching `[A-Z][A-Z0-9_]*`, `CATEGORY:` followed by that pattern, or a signed-bot directory identifier. The service determines whether a category or bot name is recognized; constructor validation does not guarantee service support.

Source-known categories are `ACADEMIC`, `ADVERTISING`, `AI`, `AMAZON`, `APPLE`, `ARCHIVE`, `BOTNET`, `FEEDFETCHER`, `GOOGLE`, `META`, `MICROSOFT`, `MONITOR`, `OPTIMIZER`, `PREVIEW`, `PROGRAMMATIC`, `SEARCH_ENGINE`, `SLACK`, `SOCIAL`, `TOOL`, `UNKNOWN`, `VERCEL`, `WEBHOOK`, and `YAHOO`. Prefix each with `CATEGORY:`.

A signed-bot identifier has the form `SIGNED_BOT:https://example.com/.well-known/http-message-signatures-directory`. Its URL must use HTTPS, have that exact path, and contain no user information, query, or fragment. Bare `SIGNED_BOT` is rejected. Verification is remote, not a local signature-verification feature; [disclosure limitations](#signed-bot-disclosure-limits) apply.

### Shield WAF

`characteristics` is a list of nonempty strings without whitespace or control characters, forwarded to the service. An empty list leaves service defaults in effect. This SDK does not interpret those expressions or implement a local WAF engine.

### Mode

| Enum case | Wire value | Meaning |
| --- | --- | --- |
| `Mode::DryRun` | 1 | Default. Observe rule results without assuming they are aggregate denials. |
| `Mode::Live` | 2 | Explicit enforcement mode. |

The safe PHP default differs deliberately from the reference Python SDK's required explicit mode. **The aggregate service conclusion is authoritative.** A dry-run rule result can say `DENY` while the aggregate decision says `ALLOW`. Never reconstruct the aggregate by scanning `results` for denials. Rules are serialized in declaration order; that does not promise remote evaluation order.

## Decisions and rule results

```php
new Arcjet\Decision(
    string $id,
    Arcjet\Conclusion $conclusion,
    Arcjet\Reason\Reason $reason,
    array $results = [],
    ?Arcjet\IpDetails $ip = null,
    int $ttl = 0,
);

new Arcjet\RuleResult(
    string $ruleId,
    Arcjet\RuleState $state,
    Arcjet\Conclusion $conclusion,
    Arcjet\Reason\Reason $reason,
    int $ttl = 0,
    ?string $fingerprint = null,
);
```

Every constructor parameter is a readonly public property. `Decision::$results` is a list of `RuleResult`. IDs can be empty when absent from the response. `ttl` is the service's lifetime in seconds, not evidence of a local cache. A result's nullable `fingerprint` is service-supplied; PHP does not generate one locally.

Both types expose `isAllowed(): bool`, `isDenied(): bool`, `isChallenged(): bool`, and `isErrored(): bool`. Each matches exactly one conclusion; **an ERROR is neither allowed nor denied**. Checking only `!$decision->isDenied()` is not a complete protection policy. `Decision` also exposes `hasIpDetails(): bool`.

| `Conclusion` case | Wire value | Application handling |
| --- | --- | --- |
| `Unspecified` | 0 | Not a valid successful aggregate response. Decoding returns `ERROR`. |
| `Allow` | 1 | Request may proceed under this protection policy. |
| `Deny` | 2 | Block the request. |
| `Challenge` | 3 | Handle explicitly. No challenge renderer or verifier is included. |
| `Error` | 4 | Protection failed or the service returned an error. Choose a deliberate error policy. |

`RuleState` cases are `Unspecified` (0), `Run` (1), `NotRun` (2), `DryRun` (3), and `Cached` (4). Unknown rule states map to `Unspecified`; unknown rule conclusions fail decoding. A service-supplied `Cached` state does not imply an SDK cache.

### Reasons

All reasons derive from `Arcjet\Reason\Reason`, with a readonly `string $type` and `isBot(): bool`, `isShield(): bool`, and `isError(): bool` helpers.

| Type and constructor | Readonly fields and meaning |
| --- | --- |
| `BotReason(array $allowed, array $denied, bool $verified, bool $spoofed)` | `type = 'BOT'`; `allowed` and `denied` are lists of service-matched bot identifiers. `verified` and `spoofed` preserve service verification flags; they are not local checks. |
| `ShieldReason(bool $shieldTriggered, bool $suspicious)` | `type = 'SHIELD'`; preserves both service flags. |
| `ErrorReason(string $message, string $code = 'remote_error')` | `type = 'ERROR'`; caller-visible diagnostic and error classification. Messages can contain untrusted or sensitive remote data; do not render them to visitors or log them without review. |
| `GenericReason(string $type = 'UNKNOWN', string $protobuf = '')` | Unsupported/future reason type and opaque serialized reason bytes, including unknown fields. It does not override the aggregate conclusion. Do not treat it as JSON or display its raw bytes. |

### Failure behavior

Invalid site keys, rules, options, and proxy configuration throw `InvalidArgumentException` during configuration. Runtime adaptation failures—including missing or invalid peer IP, invalid explicit correlation ID, unsafe/unreadable body streams, or size-limit violations—return a synthetic `ERROR` without treating the request as inspected.

Transport exceptions, non-200 HTTP responses, malformed or oversized responses, unexpected response types, missing decisions, and unknown/unspecified aggregate conclusions also return `ERROR`. A synthetic failure has an empty `id`, `ttl = 0`, no IP details, and an `ErrorReason`. A valid service `ERROR` decision retains its returned fields. No automatic retries occur.

Middleware does not log or expose raw service errors. The injected logger receives sanitized failure information only; application code remains responsible for protecting the diagnostic data on returned objects.

| `ErrorReason::$code` | Condition and response |
| --- | --- |
| `invalid_ip` | Supply a valid server `REMOTE_ADDR` or trusted application IP override. |
| `invalid_forwarded_ip` | Correct the trusted proxy's malformed or oversized forwarding chain. |
| `invalid_correlation_id` | Use 1–256 printable ASCII bytes or omit the argument. |
| `secret_in_request` | Remove the SDK key from incoming metadata or opted-in body. |
| `request_body` | Use a readable, seekable body within the cap, or disable body disclosure. |
| `request_too_large` | Reduce disclosed metadata/body to fit the fixed serialized-request cap. |
| `response_too_large` | Response is unreadable, stalled, or exceeds the configured cap; investigate the service/transport. |
| `http_status` | Service returned non-200. Check account/key configuration and service availability privately. |
| `content_type`, `content_encoding` | Check for an incompatible endpoint, intermediary, or unsupported response compression. |
| `malformed_response`, `missing_decision`, `invalid_conclusion` | Response violates the expected protocol. Do not treat it as an allow decision. |
| `runtime_error` | Unexpected runtime or transport failure. Inspect transport configuration and private operational diagnostics. |
| `remote_error` | Default code for a decoded service error reason; its message remains untrusted diagnostic data. |

## IP address extraction

Address extraction selects the address sent to Arcjet. It does **not** provide IP intelligence.

1. A non-null `protect($request, ip: $ip)` override must be a valid IPv4/IPv6 address and takes precedence. It is trusted application input.
2. Otherwise, the SDK requires a valid `REMOTE_ADDR` from the request's server parameters.
3. If that direct peer is not in `Options::$trustedProxies`, forwarding headers cannot replace it.
4. For a trusted direct peer, the SDK walks `X-Forwarded-For` right to left while the current hop is trusted. It stops at the first untrusted address. A malformed address encountered in this trusted suffix returns `ERROR`.

The forwarding header is limited to 8,192 bytes and 64 comma-separated entries. IPv4/IPv6 literals are accepted; port-suffixed addresses, bracket syntax, and `unknown` are not accepted as IP literals. `Forwarded`, `X-Real-IP`, and vendor-specific headers are not used for selection. Ignoring a header for IP selection does not redact it from the disclosed header map.

Trust only actual ingress proxy addresses or networks. Do not trust every address to make forwarded headers work. Private or loopback addresses are syntactically valid but can have no useful remote enrichment. A missing peer address is an error, not a safe-IP result.

## IP Analysis fields

`Decision::$ip` is `?IpDetails`, populated only when the service returns IP enrichment. Configure `rules: []` for enrichment without Bot Protection or Shield rules; this still calls the remote Decide service and depends on service/account support. There is no separate IP rule, lookup RPC, local database, or local risk classifier.

All `IpDetails` constructor parameters are readonly public properties. The scalar defaults are `null`; `bots` defaults to `[]`; `threat` defaults to `null`.

| Properties | PHP type | Meaning |
| --- | --- | --- |
| `latitude`, `longitude` | `?float` | Approximate location coordinates. |
| `accuracyRadius` | `?int` | Service-reported location accuracy radius. |
| `timezone`, `postalCode`, `city`, `region` | `?string` | Location labels from the service. |
| `country`, `countryName`, `continent`, `continentName` | `?string` | Service-provided codes and names. |
| `asn` | `?string` | Autonomous system number, preserved as a string. |
| `asnName`, `asnDomain`, `asnType`, `asnCountry` | `?string` | Autonomous system metadata. |
| `service` | `?string` | Service/provider label. |
| `hosting`, `vpn`, `proxy`, `tor`, `relay`, `abuser` | `?bool` | Service risk/network flags. `null` means unavailable or ambiguous, not `false`. |
| `bots` | `array<string, string>` | Service-provided bot metadata map. |
| `threat` | `?ThreatIntelligence` | Optional threat enrichment message. |

`hasLocation(): bool` tests for non-null country or city, or both coordinates. `hasAsn(): bool` tests for a non-null ASN. `hasThreat(): bool` tests for a threat object. These are presence helpers, not safety decisions. `isHosting()`, `isVpn()`, `isProxy()`, `isTor()`, `isRelay()`, and `isAbuser()` each return `?bool`.

All `ThreatIntelligence` constructor parameters are readonly public properties:

| Properties | PHP type and default | Meaning |
| --- | --- | --- |
| `riskLevel`, `confidence`, `reputation` | `?string = null` | Service classifications, not PHP enums. |
| `isSafe` | `?bool = null` | Service safety flag. Absence is not a safe result. |
| `networkTypes`, `activities`, `entities` | `array = []` | Lists of service classification strings. |
| `entityName`, `service` | `?string = null` | Service labels. |
| `backgroundNoise` | `?int = null` | Service-reported background-noise value. |

**Presence limitation:** the IP and threat fields use plain proto3 scalars without presence. Their default empty-string, zero, and false values cannot be distinguished from omission. Decoding conservatively represents these ambiguous defaults as `null`; a real zero coordinate or false flag is therefore also unknown. A decoded risk flag is `true` or `null`, never a confirmed `false`. Message presence is retained. Missing `ip`, missing `threat`, or a nullable flag is never evidence that an address is safe.

## Request disclosure

The remote service receives the following projection of the incoming request, along with the configured rules and SDK identity:

| Field | Disclosure |
| --- | --- |
| `ip` | Validated selected address. |
| `method` | Incoming HTTP method. |
| `protocol` | URI scheme, not the HTTP version. |
| `host` | URI hostname, including a non-default port when present. |
| `path`, `raw_path` | Percent-decoded URI path and original encoded URI path. |
| `query` | Encoded URI query, including the leading `?` when nonempty. **No query-parameter redaction is provided.** |
| `headers` | Lowercased header names and joined values. All unredacted headers are sent, including forwarding, signature, session, and custom-secret headers. Values join with `, `, except Cookie values join with `; `. |
| `cookies` | Raw joined Cookie header, duplicated from the header map. Adding `Cookie` to `redactHeaders` omits it from both fields. Cookies are not parsed or individually redacted. |
| `correlation_id` | Explicit argument only, if provided. |
| `body` | Excluded by default. With `includeBody: true`, exact bytes read from the beginning of the stream, within `maxBodyBytes`. Parsed body objects and uploaded-file objects are not serialized separately. |

`Authorization` and `Proxy-Authorization` never enter the metadata. Additional header redaction applies only to headers; it does not scrub the query, path, or opted-in body. The SDK refuses a projected request containing its site key, but this is not general secret detection. Review all disclosure before sending production traffic. The incoming request's headers and URI remain unchanged.

Default body exclusion does not read or consume the body stream. Opt-in reading requires a readable, seekable stream, saves its original position, reads from the beginning, and restores that position in a `finally` block, including after an inspection failure. A stream that cannot support this contract returns `ERROR`; the SDK does not consume a nonseekable body or silently send a truncated body.

### Signed-bot disclosure limits

Signature headers such as `Signature`, `Signature-Input`, and `Signature-Agent` are forwarded unless explicitly redacted. `Authorization` remains excluded. The original encoded path is forwarded, but the protocol's string-valued header map folds repeated values rather than preserving arbitrary separate header lines. Body bytes are absent unless `includeBody` is enabled.

Redacting a covered header, withholding a signed body, or folding signature-sensitive repeated fields can prevent remote signature verification. Enabling body disclosure does not establish complete signed-request parity. There is no local signature verifier, directory fetcher, signature cache, or guarantee that every signed request can be verified by this projection.

## PSR-15 middleware

```php
new Arcjet\Middleware\ArcjetMiddleware(
    Arcjet\Arcjet $arcjet,
    Psr\Http\Message\ResponseFactoryInterface $responseFactory,
    bool $failOpen = false,
);

Arcjet\Middleware\ArcjetMiddleware::process(
    Psr\Http\Message\ServerRequestInterface $request,
    Psr\Http\Server\RequestHandlerInterface $handler,
): Psr\Http\Message\ResponseInterface;
```

| Aggregate conclusion | Default response |
| --- | --- |
| `Allow` | Delegates to the next handler. |
| `Deny` | Generic HTTP 403. |
| `Challenge` | Generic HTTP 403; no challenge flow is implemented. |
| `Error` | Generic HTTP 503. Invalid remote aggregate conclusions become ERROR during decoding. |

`failOpen: true` delegates on `ERROR` only; it does not allow DENY or CHALLENGE. Fail-open leaves the attached decision as `ERROR`, not `ALLOW`.

Before delegation, middleware adds the decision as request attribute `ArcjetMiddleware::DECISION_ATTRIBUTE` (`'arcjet.decision'`). The handler can retrieve it with `$request->getAttribute(ArcjetMiddleware::DECISION_ATTRIBUTE)`. Denial/unavailability responses have empty bodies and `Cache-Control: no-store`; they contain no raw service message or credential.
