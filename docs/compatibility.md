# Compatibility and verification status

This is a **community, unofficial, experimental PHP port**, not an official or Arcjet-endorsed SDK. Its scope is Bot Protection, Shield WAF, and remote IP Analysis. It does not claim full Python SDK parity or production verification.

## Runtime and integration contract

| Area | Contract |
| --- | --- |
| Runtime | PHP 8.4+, Composer 2, `ctype`, `filter`, and `json` extensions, and `google/protobuf ^5.36.2`. No Python, protoc, WebAssembly runtime, or `mbstring` extension is needed by applications. |
| Incoming requests | PSR-7 `ServerRequestInterface`. |
| Outbound transport | Caller-injected PSR-18 client and PSR-17 request/stream factories. No bundled client or HTTP discovery. |
| Logging and middleware | Optional PSR-3 logger and PSR-15 middleware. |
| Service protocol | Unary Connect, unframed binary protobuf, `proto.decide.v1alpha1.DecideService/Decide`. The upstream interface is alpha. |
| SDK identity | Honest PHP version/User-Agent with the protocol's unspecified SDK-stack value; no Python impersonation or invented PHP enum. |
| Distribution | Composer name `arcjet/arcjet-php`; source in the [public GitHub repository](https://github.com/colinmollenhour/arcjet-php). The documented installation uses a local path repository. No Packagist release is assumed. |

The source reference is [arcjet-py 1.5.0, revision `498b28b17ebc1a652974ab8ae98c60604e892095`](https://github.com/arcjet/arcjet-py/tree/498b28b17ebc1a652974ab8ae98c60604e892095). Protocol generation pins that reference; it does not pin the hosted service's future behavior.

Generated PHP code uses protoc 36.2 with the matching PHP runtime floor 5.36.2. The separate Python 6.33.2 tooling pin supports offline descriptor/fixture work; it is not the PHP runtime version. See [Protobuf's language-specific version mapping](https://protobuf.dev/support/version-support/#numbering) and [cross-version runtime guarantee](https://protobuf.dev/support/cross-version-runtime-guarantee/).

The validated profile uses the pure-PHP Composer runtime without native `ext-protobuf`. A preloaded extension supplies its own classes and version; this port has not validated that path. Disable it to reproduce the documented checks. The Composer package constraint does not validate a loaded native extension's compatibility.

## Deliberate differences from Python

| Behavior | PHP contract and cost |
| --- | --- |
| Evaluation | Every valid protection call goes to Decide. There is no local WebAssembly bot shortcut, local WAF, or user-agent heuristic. Each call adds network latency, service availability dependency, and account usage. |
| Default rule mode | PHP defaults to `Mode::DryRun`; the Python reference requires explicit mode. LIVE requires explicit opt-in. |
| Empty bot denylist | Rejected because the protobuf repeated fields cannot distinguish empty deny from empty allow. Empty allow remains supported; omit the bot rule to disable it. |
| Client IP trust | Uses validated `REMOTE_ADDR`, or an explicit application override. `X-Forwarded-For` is considered only behind explicitly trusted peers and walked right to left. No permissive forwarding-header fallback. |
| Metadata privacy | Always omits Authorization and Proxy-Authorization, supports additional header redaction, and excludes the body by default. Queries, cookies, other unredacted headers, and the manual-IP reporting marker are still disclosed. Redaction can reduce available evaluation or signature-verification inputs. |
| Request text | Preserves valid UTF-8 header values; otherwise interprets each value as Latin-1. Invalid UTF-8 in decoded paths becomes U+FFFD. Raw paths prefer server `RAW_URI`/`REQUEST_URI` over rebuilt PSR-7 targets; exactness depends on the adapter/server. Body bytes are not normalized. |
| Body handling | Opt-in forwarding requires bounded, readable, seekable streams and restores their original positions. Oversized or unsafe bodies produce ERROR and middleware HTTP 503 even with fail-open; no silent omission or truncation. Choose route-level disclosure policy or tune the default 64 KiB cap within the whole-request limit. Shield does not analyze request bodies. |
| Error policy | Runtime failures return explicit ERROR decisions with a typed failure origin. Middleware defaults to HTTP 503 for ERROR; explicit `failOpen` delegates only on classified transport/service ERROR, including remote aggregate ERROR. Request, internal, and unclassified failures remain 503. No error is relabeled ALLOW. |
| Challenges | Preserves CHALLENGE; middleware blocks with HTTP 403. No challenge presentation or verification. |
| Shield reasons | Preserves both `shieldTriggered` and `suspicious`. |
| IP presence | Preserves optional enrichment messages and conservatively maps ambiguous default-valued proto3 scalars to nullable fields. Missing information is not safety. |

Aggregate service decisions remain authoritative, including DRY_RUN responses. A per-rule DENY is not an aggregate DENY. Exact signatures, defaults, fields, and disclosure rules live in the [API reference](api.md), not this comparison.

## Limits of this implementation

- No local protection engine, cache, background Report RPC, locally generated fingerprint, or offline protection fallback.
- No rate limiting, email validation, sensitive-information detection, filter rules, prompt protection, or framework-specific adapters. Generated protocol types for other rules do not make them public supported features.
- IP Analysis is optional service-returned enrichment, not client-IP extraction, a separate lookup endpoint, or a local database. An empty rule list can request it, subject to account/service support.
- No local signed-bot verifier, directory fetcher, or signature cache. Redacted covered headers, absent request bodies, and folded repeated headers can prevent service verification. Full signed-request parity is not established.
- No field-level query, cookie, or body redaction. Extra header redaction does not scrub secrets elsewhere in the request.
- No automatic retries. The upstream RPC does not establish a retry-safe contract here.
- No portable transport timeout, redirect, TLS, or buffering controls in PSR-18. A correctly configured real HTTP implementation is required; a deadline header alone is insufficient.

See [architecture](architecture.md) for the remote-only design and its rejected alternatives.

## What has and has not been established

| Evidence | What it establishes | What it does not establish |
| --- | --- | --- |
| Pinned protobuf generation and offline cross-language fixtures | Source-grounded field numbers and serialization compatibility with the checked-in Python reference. | Acceptance of an authenticated PHP request by the live service. |
| Fake-client tests and the synthetic offline example | SDK request adaptation, typed decisions, and PSR integration without network access. | Bot detection, WAF effectiveness, live IP intelligence, or remote signed-bot verification. |
| [Hosted offline CI](https://github.com/colinmollenhour/arcjet-php/actions/workflows/ci.yml), passed for `2b2c387` on 2026-11-06 | Automated offline package, test, analysis, dependency, and protocol checks. | Authenticated service behavior: this workflow does not use a live key or execute the manual live matrix. |
| [Authenticated synthetic live validation, 2026-11-06](live-validation.md) | Bounded PHP 8.5 service acceptance, CURL policies, official Shield test-threshold/mode behavior, and selected-IP enrichment; a separate PHP 8.4 subset repeated two enrichment cases, three CURL policies, and the LIVE Shield sequence. | Identical full-matrix coverage on both runtimes, arbitrary-attack effectiveness, live signed-bot verification, full SDK parity, vendor endorsement, or production certification. |
| Opt-in live smoke script | A way to send one synthetic request using a supplied key. | Reproduction of the full validation matrix, comprehensive security evaluation, or release certification. |

The canonical [live validation reference](live-validation.md) separates the corrected PHP 8.5 run from the fresh, first-pass PHP 8.4 subset. Native-extension compatibility remains unverified. Hosted offline CI is separate evidence, not an authenticated live workflow or production certification; this documentation update does not claim another test-suite or PR-check run.

Remaining deployment gates include the chosen runtime/transport, missing-enrichment and operational-failure handling, actual ingress proxy settings and disclosure, and any genuine signed-request requirements. The manual-IP marker's independent effect and arbitrary-attack effectiveness remain unproven. A successful synthetic run does not establish production readiness.

The [live smoke example](../examples/live-smoke.php) is deliberately opt-in. A successful HTTP response or non-error decision alone is not proof that every rule or enrichment feature works correctly.
