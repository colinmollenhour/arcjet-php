# Security policy

## Report a vulnerability

Report vulnerabilities privately to the repository maintainer using the host's private vulnerability reporting feature when available. Do not open a public issue containing exploit details, API keys, request bodies, cookies, or customer traffic. If private reporting is unavailable, request a private contact channel without disclosing the vulnerability.

This community PHP port is not an official Arcjet SDK. Report problems in this library to its maintainer; consult [Arcjet's security policy](https://github.com/arcjet/arcjet-py/security/policy) for problems in the upstream service or Python SDK.

## Protect credentials and request data

- Keep the Arcjet secret key in server-side configuration. Never expose it in browser code, logs, fixtures, or exception reports.
- Use an HTTPS service endpoint and a PSR-18 client that verifies TLS certificates. Do not enable automatic redirects for authenticated service requests.
- Configure outbound timeouts in your HTTP client. PSR-18 does not define a portable timeout option.
- Trust forwarding headers only when the direct peer is a configured trusted proxy. Restrict direct access to the application behind that proxy.
- Treat IP and bot intelligence as risk signals, not as authentication or authorization.
- Decide explicitly how your application handles unavailable protection. An error is not proof that a request is safe. Middleware fail-open is limited to classified transport/service failures; request failures, internal failures, and unclassified errors remain HTTP 503.
- Opted-in body inspection is strict: oversized, unreadable, or nonseekable bodies return ERROR and middleware HTTP 503 even with fail-open. Size the body cap and route-level disclosure policy for uploads; the SDK never silently omits or truncates an opted-in body to claim successful inspection.

See the README and API documentation for this SDK's data handling and decision behavior.
