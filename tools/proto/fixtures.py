"""Cross-language examples serialized by real Python protobuf, never by hand."""

from __future__ import annotations

import json

from google.protobuf import descriptor_pool, json_format, message_factory


def examples() -> dict[str, tuple[str, dict]]:
    """Return authored values; generate.py derives both bytes and expectations."""
    result = {}
    for mode in ("DRY_RUN", "LIVE"):
        for list_name in ("allow", "deny"):
            result[f"request-{mode.lower()}-{list_name}"] = (
                "DecideRequest",
                {
                    "sdk_stack": "SDK_STACK_UNSPECIFIED",
                    "sdk_version": "0.1.0-fixture",
                    "details": {
                        "ip": "203.0.113.42",
                        "method": "POST",
                        "protocol": "https:",
                        "host": "example.test",
                        "path": "/search/☃",
                        "raw_path": "/search/%E2%98%83",
                        "query": "?q=arcjet&sort=asc",
                        "headers": {
                            "accept": "application/json",
                            "authorization": "<redacted>",
                            "user-agent": "curl/8.0.0",
                        },
                        # ProtoJSON uses base64 for the body bytes, including NUL/FF.
                        "body": "AHNub3dtYW46IOKYg/8=",
                        "extra": {"tenant": "example", "userId": "fixture-user"},
                        "cookies": "session=fixture",
                        "correlation_id": "fixture-correlation",
                    },
                    "rules": [
                        {"shield": {"mode": f"MODE_{mode}", "characteristics": ["ip.src", "http.host"]}},
                        {
                            "bot_v2": {
                                "mode": f"MODE_{mode}",
                                list_name: ["CATEGORY:SEARCH_ENGINE", "CURL", "SIGNED_BOT:https://bots.example.test/directory"],
                            }
                        },
                    ],
                    "characteristics": ["ip.src", "userId"],
                    "metadata_json": {"attempt": "1", "feature": '"fixture"'},
                    "local_warnings": [{"code": "fixture", "message": "Offline contract example"}],
                },
            )

    bot_reason = {"bot_v2": {"allowed": ["GOOGLE_CRAWLER"], "denied": ["CURL"], "verified": True, "spoofed": True}}
    shield_reason = {"shield": {"shield_triggered": True, "suspicious": True}}
    result["response-bot-deny-ip"] = (
        "DecideResponse",
        {
            "decision": {
                "id": "lreq_fixture_bot",
                "conclusion": "CONCLUSION_DENY",
                "reason": bot_reason,
                "rule_results": [
                    {"rule_id": "bot-rule", "state": "RULE_STATE_RUN", "conclusion": "CONCLUSION_DENY", "reason": bot_reason, "ttl": 60, "fingerprint": "bot-fingerprint"},
                    {"rule_id": "shield-rule", "state": "RULE_STATE_DRY_RUN", "conclusion": "CONCLUSION_DENY", "reason": shield_reason, "ttl": 10},
                    {"rule_id": "legacy-bot-rule", "state": "RULE_STATE_CACHED", "conclusion": "CONCLUSION_DENY", "reason": {"bot": {"bot_type": "BOT_TYPE_AUTOMATED", "bot_score": 99, "user_agent_match": True, "ip_hosting": True, "ip_vpn": True, "ip_proxy": True, "ip_tor": True, "ip_relay": True}}},
                ],
                "ttl": 60,
                "ip_details": {
                    "latitude": 37.75, "longitude": -122.5, "accuracy_radius": 20,
                    "timezone": "America/Los_Angeles", "postal_code": "94107", "city": "San Francisco",
                    "region": "CA", "country": "US", "country_name": "United States",
                    "continent": "NA", "continent_name": "North America", "asn": "64500",
                    "asn_name": "Example Network", "asn_domain": "example.test", "asn_type": "hosting", "asn_country": "US",
                    "service": "fixture-cloud", "is_hosting": True, "is_vpn": True, "is_proxy": True,
                    "is_tor": True, "is_relay": True, "is_abuser": True,
                    "bots": {"fixture": "automated", "search": "verified"},
                    "threat": {
                        "risk_level": "high", "confidence": "high", "reputation": "poor", "is_safe": False,
                        "network_types": ["hosting", "vpn"], "activities": ["automation", "scanning"],
                        "entities": ["fixture"], "entity_name": "Example Network", "service": "fixture-intelligence", "background_noise": 7,
                    },
                },
            },
            "extra": {"fixture": "python", "trace": "offline"},
        },
    )
    for name, conclusion, reason, state in (
        ("shield-deny", "DENY", shield_reason, "RUN"),
        ("dry-run-allow", "ALLOW", bot_reason, "DRY_RUN"),
        ("challenge", "CHALLENGE", shield_reason, "RUN"),
        ("error", "ERROR", {"error": {"message": "Fixture upstream error"}}, "NOT_RUN"),
        ("timestamp", "DENY", {"rate_limit": {"max": 100, "count": 101, "remaining": 0, "reset_time": "2026-11-06T12:34:56.123456789Z", "reset_in_seconds": 30, "window_in_seconds": 60}}, "RUN"),
    ):
        result[f"response-{name}"] = (
            "DecideResponse",
            {"decision": {"id": f"lreq_fixture_{name}", "conclusion": f"CONCLUSION_{conclusion}", "reason": reason,
                          "rule_results": [{"rule_id": "fixture-rule", "state": f"RULE_STATE_{state}", "conclusion": "CONCLUSION_DENY" if state == "DRY_RUN" else f"CONCLUSION_{conclusion}", "reason": reason}]}},
        )
    result["response-absent-decision"] = ("DecideResponse", {})
    result["response-unknown-conclusion"] = ("DecideResponse", {"decision": {"id": "lreq_fixture_future", "conclusion": 12345}})
    return result


def generate(original: bytes, timestamp: bytes) -> dict[str, bytes]:
    """Use an isolated official protobuf pool; upstream modules are not imported."""
    pool = descriptor_pool.DescriptorPool()
    pool.AddSerializedFile(timestamp)
    pool.AddSerializedFile(original)
    output = {}
    for name, (message_name, values) in examples().items():
        cls = message_factory.GetMessageClass(pool.FindMessageTypeByName(f"proto.decide.v1alpha1.{message_name}"))
        message = json_format.ParseDict(values, cls(), descriptor_pool=pool)
        wire = message.SerializeToString(deterministic=True)
        # Derive expectations from decoding the serialized Python message, not inputs.
        decoded = cls.FromString(wire)
        expectation = {
            "message": message_name,
            "value": json_format.MessageToDict(decoded, descriptor_pool=pool),
        }
        output[f"{name}.bin"] = wire
        output[f"{name}.json"] = (json.dumps(expectation, ensure_ascii=False, indent=2, sort_keys=True) + "\n").encode()
    return output
