# Authenticated live-validation reference

On 2026-11-06, this experimental PHP port completed a bounded PHP 8.5 synthetic matrix and a fresh, smaller PHP 8.4 subset against Arcjet's real Decide service. Authenticated interoperability and the outcomes below were observed; production readiness, general WAF effectiveness, and vendor endorsement were not established.

## Run scope

| Area | Executed scope |
| --- | --- |
| Runtime | PHP 8.5.4 matrix and PHP 8.4.27 subset; SDK `0.1.0-dev`. The live harness did not record the protobuf implementation; native `ext-protobuf` was not separately validated. PHP 8.4 ran in a CLI container with the checkout/dependencies mounted read-only. |
| Transport | Symfony HTTP Client 7.4.23 PSR-18 adapter; TLS peer/host verification enabled; redirects disabled; 4 s inactivity timeout, 5 s maximum duration, 2,500 ms service deadline. |
| Endpoint | `https://decide.arcjet.com/proto.decide.v1alpha1.DecideService/Decide`; unary Connect with binary protobuf. |
| Call budget | 42 actual remote calls across both runs, sequential with one-second gaps and no retries: 41 valid-key HTTP 200/protobuf responses and one independent wrong-key HTTP 401. |
| Inputs | Synthetic `example.com` request metadata, fabricated browser-like or curl User-Agents, and public resolver addresses as modeled client IPs. No genuine browser, crawler, or signer identity. |
| SDK identity | The SDK source sends PHP's own User-Agent and the protocol's `UNSPECIFIED` SDK-stack value; service responses were HTTP 200. Those fields were not separately recorded by the live harness, and acceptance is not vendor endorsement or a supported-SDK commitment. |
| Assertion boundary | Per-runtime counts below are checks across calls, not independent test counts. The PHP 8.5 correction does not apply to the fresh PHP 8.4 execution. |

| Runtime | Remote calls | Valid-key HTTP 200 | Wrong-key HTTP 401 | Supported assertions |
| --- | --- | --- | --- | --- |
| PHP 8.5.4 | 30 | 29 | 1 | 867 passed, zero failed after the offline correction described below. |
| PHP 8.4.27 | 12 | 12 | 0 | 350 passed, zero failed on the first execution; no reassessment. |

The SDK source was unchanged. The PHP 8.4 subset made fresh authenticated calls; it was not an offline replay, and the corrected PHP 8.5 artifacts were not modified by the PHP 8.4 run. Hosted offline CI passed for `2b2c387` on the same date; it is separate from these manual authenticated runs and does not use a live key or execute this matrix. This documentation update does not claim another offline-suite or PR-check run. Request durations were diagnostics, not performance statistics.

## Observed outcomes

| Cases | PHP 8.5 calls | PHP 8.4 calls | Observed result and boundary |
| --- | --- | --- | --- |
| IP enrichment and selection | 6 | 2 | Both runtimes returned ALLOW with empty-rule enrichment for `1.1.1.1` and `8.8.8.8`. PHP 8.5 additionally covered a benign Shield request, untrusted/trusted forwarding-header cases, and an explicit IP override. The prepared protobuf contained the expected selected address. This is synthetic selection/enrichment evidence, not real ingress validation. |
| CURL bot policies | 5 | 3 | Both runtimes observed LIVE `deny: ['CURL']` denying curl, LIVE `allow: ['CURL']` allowing it, and DRY_RUN retaining a CURL rule DENY with aggregate ALLOW. Only PHP 8.5 also covered LIVE `allow: []` denying curl and a fabricated browser-like User-Agent allowed under the CURL deny rule. Typed bot reasons matched the tested policies; no cryptographic verification was demonstrated. |
| Official Shield LIVE test | 7 | 7 | Each runtime used a fresh isolated sequence: a benign baseline and the first five suspicious-header requests were allowed. The sixth suspicious request produced a Shield rule DENY and aggregate DENY, with `shieldTriggered = true` and `suspicious = true`. |
| Official Shield DRY_RUN test | 7 | 0 | PHP 8.5 only: a separate benign baseline and first five suspicious-header requests were allowed. The sixth produced a DRY_RUN rule DENY with both flags true, but aggregate ALLOW. |
| Generic attack-like metadata | 3 | 0 | PHP 8.5 only: one SQL-injection query, one cross-site-scripting query, and one path-traversal sample were all ALLOWED, with Shield not triggered. Arbitrary-payload effectiveness is inconclusive, not validated. |
| Combined rules | 1 | 0 | PHP 8.5 only: a benign fabricated browser-like request returned typed Bot Protection and Shield results, both ALLOW. This is not comprehensive rule-interaction coverage. |
| Negative authentication | 1 | 0 | PHP 8.5 only: an independent synthetic wrong key returned HTTP 401; the SDK returned an ERROR with `FailureKind::Service` and `http_status`. This was not a mutation of the valid key and does not cover all service failures. |

The six PHP 8.5 IP cases include one harmless opted-in body on an empty-rule request; body forwarding was not repeated on PHP 8.4. Prepared-protobuf checks established body projection, and stream checks established byte/cursor preservation. Other checks covered default body exclusion, inbound Authorization redaction, request fields, and rule serialization. **Body forwarding is SDK transport behavior only: [Shield does not analyze request bodies](https://docs.arcjet.com/limitations/#shield-analysis-does-not-use-the-request-body).** The SDK's strict body-reading/refusal policy is unchanged.

Resolver enrichment included AS13335 for `1.1.1.1` and AS15169 for `8.8.8.8`. These are dated service observations, not fixed geolocation/reputation fixtures, real traffic origins, or safety guarantees. Default-valued proto3 IP flags remain ambiguous and nullable. The manual-IP reporting marker was present in the outbound projection, but its independent service-side effect was not isolated.

## Shield mechanism and corrected expectation

The [official testing guide](https://docs.arcjet.com/testing/#trigger-each-rule-in-a-test) documents `x-arcjet-suspicious: true`: five requests are allowed, and the sixth triggers Shield. Each executed sequence used a fresh fingerprint with these configured characteristics:

```text
ip.src
http.request.headers["x-arcjet-test-run"]
```

The synthetic test-run header stayed constant within each sequence; PHP 8.5 used distinct values for LIVE and DRY_RUN, and PHP 8.4 used a fresh LIVE value. Returned fingerprints were constant within each seven-call sequence; PHP 8.5's LIVE and DRY_RUN fingerprints were distinct. PHP 8.4 did not repeat the DRY_RUN comparison. A header isolates state only when it is a [configured fingerprint characteristic](https://docs.arcjet.com/fingerprints/); a changing path or correlation ID alone does not do so.

The original PHP 8.5 harness recorded 870 passing and 10 failing assertions and exited unsuccessfully. All ten failures assumed `ShieldReason::$suspicious` immediately mirrored the special header on each of the first five requests. Both Shield flags were actually false before the threshold and true on the sixth, in both modes. The documented threshold and aggregate-mode expectations passed throughout.

The unsupported early-flag expectation was corrected offline using the saved sanitized observations, with no additional network calls or SDK changes. Original diagnostic checks were retained. Reevaluation produced PHP 8.5's 867 supported passing assertions; it was not a clean first-pass run or an SDK bug fix. The later PHP 8.4 subset used supported expectations on its first execution and required no correction; its 350 passing assertions are separate evidence.

The special header exercises a deterministic, stateful service mechanism, not arbitrary attack detection. Shield state survives client/process replacement. The [official quick start](https://docs.arcjet.com/sdk/bun/shield/quick-start/#5-simulate-a-suspicious-request) warns of a 15-minute block for a triggered fingerprint; neither run measured its expiry or attempted a reset. Normal analysis is asynchronous, so these synthetic thresholds do not establish how individual real attack strings behave.

## Minimal reproduction boundary

The public [live smoke example](../examples/live-smoke.php) requires the optional Symfony transport and Nyholm dependencies described in the [README](../README.md#run-the-examples), a site key supplied securely as `ARCJET_KEY`, and explicit CLI opt-in:

```sh
php examples/live-smoke.php --live
```

This sends **one real request**, can consume account usage, and prints the aggregate conclusion and IP-detail presence without raw service data. It uses its own shorter default timeouts; it does not execute or reproduce the 30-call PHP 8.5 matrix or 12-call PHP 8.4 subset. Those runs did not validate the SDK's default 500 ms service deadline or the example's 0.5 s inactivity / 1 s maximum-duration transport settings. A non-error result alone is not a successful feature matrix.

A comparable bounded matrix needs an instrumented real PSR-18 transport, prepared-protobuf checks, and typed rule-result assertions in addition to aggregate decisions. The Shield comparison requires one benign baseline plus exactly six sequential special-header requests per mode, with separate configured synthetic fingerprints and a stop on unexpected baseline error/denial. Such calls create real remote state and use quota; they are not an offline sandbox. Keys, raw payloads, and opaque identifiers are not appropriate public reproduction output.

## Unverified claims and deployment gates

- No genuine signed-bot traffic, cryptographic signature verification, or full signed-request parity was tested. Header forwarding and identifier validation are not verification; see [signed-bot disclosure limits](api.md#signed-bot-disclosure-limits).
- No load, latency-distribution, performance, production-traffic, dashboard/account, or production-certification assessment was performed.
- No comprehensive WAF attack coverage, body-attack detection, or general SQL-injection/cross-site-scripting prevention was established. The three generic metadata samples did not trigger Shield.
- PHP 8.4 coverage is limited to the stated subset: no Shield DRY_RUN, forwarding/override variants, body forwarding, combined rules, wrong-key control, browser/empty-allow controls, or generic attack-like samples were repeated there. Neither runtime's run validates native `ext-protobuf`, other transports, all operational failures, absent-enrichment handling, or the shorter default timeout budgets.
- Actual proxy trust, disclosure policy, runtime/transport controls, application error handling, and any signed-request requirements remain deployment-specific gates. The service's alpha protocol and intelligence can change.

See [compatibility and implementation limits](compatibility.md) and the [API reference](api.md) for the supported contract.
