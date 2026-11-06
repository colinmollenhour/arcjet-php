# Why this port uses remote evaluation

This community PHP port delegates Bot Protection, Shield WAF, and IP Analysis to Arcjet's service. PHP handles request adaptation, configuration, protocol encoding, decision decoding, and application integration. It does not reimplement the protection algorithms.

## Relationship to the Python SDK

The reference is [arcjet-py at revision `498b28b`](https://github.com/arcjet/arcjet-py/tree/498b28b17ebc1a652974ab8ae98c60604e892095). The Python SDK can evaluate some bot rules in a local WebAssembly component. A local LIVE denial can short-circuit the request; otherwise, it sends the configured rules to the remote Decide service. Shield evaluation, signed-bot verification, and IP intelligence depend on the service.

A PHP WebAssembly component integration would introduce a separate runtime and binding maintenance burden. User-agent heuristics would be easier to install but would not reproduce Arcjet protection. Remote evaluation avoids both compromises at the cost of a service request for every protection call.

## Protocol fidelity

The service uses unary Connect with binary Protocol Buffers. Generated PHP messages come from the upstream serialized descriptor, rather than independently guessed field numbers or a fabricated REST API. Runtime use needs neither Python nor a protobuf compiler.

The upstream protocol is `proto.decide.v1alpha1`. It has no PHP SDK-stack value. This port identifies itself honestly and does not impersonate another SDK or invent an enum member. The protocol is an upstream alpha interface: pinned generation and conformance fixtures reduce accidental drift, but cannot guarantee future service compatibility.

## Network and availability costs

Every protection call adds synchronous network latency and depends on Arcjet's availability and account limits. There is no local denial cache, background Report call, or offline fallback engine in this version.

PSR-18 standardizes sending HTTP requests, not connection deadlines, automatic redirects, or TLS settings. Those remain responsibilities of the injected HTTP client. A server deadline header does not interrupt a blocked PHP worker. A TLS-verifying client with bounded connection and overall timeouts is part of a safe deployment.

## Trust and privacy boundaries

A client IP is security-sensitive input. An arbitrary visitor can supply forwarding headers; a deployment's trusted proxy configuration determines whether such headers are authoritative. Explicit application-supplied IP overrides are trusted application inputs, not a mechanism for reading visitor-controlled headers without validation.

Request metadata leaves the application for evaluation. Limiting disclosed data reduces exposure but can also reduce the inputs available for evaluation or signature verification. Bodies are excluded by default. Optional body forwarding uses a generic protobuf field; it does not enable Shield body analysis. Shield uses headers and query metadata, [not request bodies](https://docs.arcjet.com/limitations/#shield-analysis-does-not-use-the-request-body). Signed-request requirements still need deliberate consideration of exact bytes, body size, and the HTTP stream's behavior. See the [API disclosure reference](api.md#request-disclosure) for the actual projection and configuration options.

Opting into body forwarding is strict: an oversized or unsafe-to-read body produces an error rather than silently omitting or truncating it. This can reject large uploads unless the application deliberately configures its body cap or route policy. Request-local failures do not qualify for outage fail-open.

## Decisions are not authentication

IP intelligence describes risk and network context; it does not establish identity. Missing enrichment is unavailable information, not a clean bill of health. Bot classifications and WAF results likewise complement rather than replace application authorization.

An ERROR conclusion records unavailable or invalid protection, not successful inspection. Typed failure origins distinguish request-local and internal problems from operational transport or service failures. The middleware's optional fail-open policy applies only to explicit operational errors; request-local, internal, and unclassified errors remain closed. DRY_RUN results are observations; the aggregate service decision remains authoritative rather than being reconstructed from individual rule conclusions.

## Verification boundary

Offline conformance tests compare generated messages and binary fixtures against the Python protocol. They establish serialization and SDK behavior, not the effectiveness of Arcjet's hosted protection.

Authenticated synthetic interoperability was observed on PHP 8.5 and a smaller PHP 8.4 subset on 2026-11-06. The [live validation reference](live-validation.md) separates per-runtime coverage from untested effectiveness, signed-bot, and production claims. These manual checks are distinct from hosted offline CI; neither establishes deployment safety or future service compatibility. This port remains experimental, not production-certified or an official or Arcjet-endorsed SDK.
