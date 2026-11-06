#!/usr/bin/env python3
"""Generate pinned PHP messages and Python cross-language fixtures offline."""

from __future__ import annotations

import argparse
import ast
import base64
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

import google.protobuf
from google.protobuf import descriptor_pb2, text_format

from fixtures import generate as generate_fixtures

ROOT = Path(__file__).resolve().parents[2]
RESOURCES = ROOT / "resources/proto"
PHP_OUTPUT = ROOT / "src/Internal/Proto"
FIXTURES = ROOT / "tests/Protocol/fixtures"
REVISION = "498b28b17ebc1a652974ab8ae98c60604e892095"
ORIGINAL_SHA256 = "d9235e410d6b87a099da2bffb324186eefce4d7a007352a520040968cca06d6e"
TIMESTAMP_SHA256 = "422a163a2d7051465e0a516584b4d96d18c270ec4e7ad3c21ad33e5c440ebecc"
TIMESTAMP_SOURCE_SHA256 = "fa6c44dca2c90a2aaf3a1e4776cef2e9177f70fba365f236269f0f41ac166c96"
SOURCE = "src/arcjet/proto/decide/v1alpha1/decide_pb2.py"
PROTOC_VERSION = "libprotoc 36.2"
PYTHON_PROTOBUF_VERSION = "6.33.2"
PROTO_NAME = "proto/decide/v1alpha1/decide.proto"
TIMESTAMP_NAME = "google/protobuf/timestamp.proto"


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def extract_literal(path: Path) -> bytes:
    """Inspect Python syntax only: NEVER import, exec or eval the reference file."""
    tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
    calls = [node for node in ast.walk(tree) if isinstance(node, ast.Call)
             and isinstance(node.func, ast.Attribute) and node.func.attr == "AddSerializedFile"]
    if len(calls) != 1 or len(calls[0].args) != 1 or calls[0].keywords:
        raise ValueError("Expected exactly one AddSerializedFile(bytes-literal) call")
    value = ast.literal_eval(calls[0].args[0])
    if not isinstance(value, bytes) or digest(value) != ORIGINAL_SHA256:
        raise ValueError("Upstream descriptor does not match the reviewed SHA256 pin")
    return value


def import_reference(upstream: Path, include: Path, protoc: str) -> None:
    revision = subprocess.check_output(["git", "-C", str(upstream), "rev-parse", "HEAD"], text=True).strip()
    if revision != REVISION:
        raise ValueError(f"Expected upstream revision {REVISION}, found {revision}")
    original = extract_literal(upstream / SOURCE)
    with tempfile.TemporaryDirectory(prefix="arcjet-proto-import-") as directory:
        descriptor_set = Path(directory) / "timestamp.bin"
        subprocess.run([protoc, f"--proto_path={include}", f"--descriptor_set_out={descriptor_set}", "--include_imports", TIMESTAMP_NAME], check=True)
        files = descriptor_pb2.FileDescriptorSet.FromString(descriptor_set.read_bytes()).file
        if len(files) != 1 or files[0].name != TIMESTAMP_NAME:
            raise ValueError("Unexpected timestamp dependency descriptor set")
        timestamp = files[0].SerializeToString(deterministic=True)
    if digest(timestamp) != TIMESTAMP_SHA256 or digest((include / TIMESTAMP_NAME).read_bytes()) != TIMESTAMP_SOURCE_SHA256:
        raise ValueError("Installed timestamp include does not match the reviewed descriptor/source hash pins")
    provenance = {
        "upstream": "https://github.com/arcjet/arcjet-py",
        "revision": REVISION,
        "source": SOURCE,
        "extraction": "ast.parse + ast.literal_eval of AddSerializedFile argument; no upstream code execution",
        "original_descriptor": {"file": "decide.original.bin", "sha256": digest(original), "size": len(original)},
        "timestamp_descriptor": {
            "file": "timestamp.bin", "sha256": digest(timestamp), "size": len(timestamp),
            "source": TIMESTAMP_NAME, "source_sha256": digest((include / TIMESTAMP_NAME).read_bytes()),
            "compiler": PROTOC_VERSION,
        },
        "generation": {"protoc": PROTOC_VERSION, "python_protobuf": PYTHON_PROTOBUF_VERSION,
                       "php_namespace": "Arcjet\\Internal\\Proto", "php_metadata_namespace": "Arcjet\\Internal\\Proto\\Metadata"},
    }
    RESOURCES.mkdir(parents=True, exist_ok=True)
    (RESOURCES / "decide.original.bin").write_bytes(original)
    (RESOURCES / "timestamp.bin").write_bytes(timestamp)
    (RESOURCES / "provenance.json").write_text(json.dumps(provenance, indent=2) + "\n", encoding="utf-8")


def verify_sources() -> tuple[bytes, bytes]:
    provenance = json.loads((RESOURCES / "provenance.json").read_text())
    original = (RESOURCES / "decide.original.bin").read_bytes()
    timestamp = (RESOURCES / "timestamp.bin").read_bytes()
    if provenance["revision"] != REVISION or digest(original) != ORIGINAL_SHA256:
        raise ValueError("Original upstream descriptor/revision pin mismatch")
    if digest(timestamp) != TIMESTAMP_SHA256 or provenance["timestamp_descriptor"]["source_sha256"] != TIMESTAMP_SOURCE_SHA256:
        raise ValueError("Timestamp descriptor/source pin mismatch")
    for label, data in (("original_descriptor", original), ("timestamp_descriptor", timestamp)):
        if digest(data) != provenance[label]["sha256"] or len(data) != provenance[label]["size"]:
            raise ValueError(f"Invalid committed {label}")
    return original, timestamp


def collect(directory: Path, suffix: str | None = None) -> dict[str, bytes]:
    return {str(path.relative_to(directory)): path.read_bytes() for path in directory.rglob("*")
            if path.is_file() and (suffix is None or path.suffix == suffix)} if directory.exists() else {}


def verify_php(original: bytes, timestamp: bytes, php: str) -> None:
    """Independently decode real PHP setter output with the Python runtime."""
    from google.protobuf import descriptor_pool, message_factory

    pool = descriptor_pool.DescriptorPool()
    pool.AddSerializedFile(timestamp)
    pool.AddSerializedFile(original)
    output = subprocess.check_output([php, str(ROOT / "tests/Protocol/export-fixtures.php")], cwd=ROOT, text=True)
    messages = json.loads(output)
    expected_names = {"request-dry_run-allow", "request-dry_run-deny", "request-live-allow", "request-live-deny", "response-bot-deny-ip"}
    if set(messages) != expected_names:
        raise ValueError("PHP cross-language export did not contain the expected five setter examples")
    for name, encoded in messages.items():
        message_name = "DecideRequest" if name.startswith("request-") else "DecideResponse"
        cls = message_factory.GetMessageClass(pool.FindMessageTypeByName(f"proto.decide.v1alpha1.{message_name}"))
        actual = cls.FromString(base64.b64decode(encoded, validate=True))
        expected = cls.FromString((FIXTURES / f"{name}.bin").read_bytes())
        if actual != expected:
            raise ValueError(f"Python decoding of PHP serialization differs from fixture: {name}")
    print(f"Python independently verified {len(messages)} PHP-serialized fixture messages.")


def sync(directory: Path, expected: dict[str, bytes], check: bool, suffix: str | None = None) -> bool:
    current = collect(directory, suffix)
    changes = sorted(name for name in current.keys() | expected.keys() if current.get(name) != expected.get(name))
    for name in changes:
        print(f"{'DRIFT' if check else 'WRITE'} {directory.relative_to(ROOT)}/{name}")
        if not check:
            target = directory / name
            if name not in expected:
                target.unlink()
            else:
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(expected[name])
    return bool(changes)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="Verify generated files without changing them")
    parser.add_argument("--protoc", default=shutil.which("protoc"), help="Path to pinned protoc 36.2")
    parser.add_argument("--verify-php", action="store_true", help="Also decode PHP setter output with real Python protobuf (requires composer install)")
    parser.add_argument("--php", default="php", help="PHP binary used by --verify-php")
    parser.add_argument("--import-upstream", type=Path, help="Explicitly re-extract reviewed upstream revision (bootstrap only)")
    parser.add_argument("--protoc-include", type=Path, help="Installed protoc include directory (required for import)")
    args = parser.parse_args()
    if not args.protoc:
        parser.error("Install protoc 36.2 or supply --protoc /path/to/protoc")
    if google.protobuf.__version__ != PYTHON_PROTOBUF_VERSION:
        parser.error(f"Install pinned build dependency: python -m pip install -r tools/proto/requirements.txt (expected {PYTHON_PROTOBUF_VERSION})")
    version = subprocess.check_output([args.protoc, "--version"], text=True).strip()
    if version != PROTOC_VERSION:
        parser.error(f"Expected {PROTOC_VERSION}, found {version}; compiler upgrades require an explicit reviewed regeneration")
    if args.import_upstream:
        if args.check or not args.protoc_include:
            parser.error("--import-upstream requires --protoc-include and cannot be combined with --check")
        import_reference(args.import_upstream.resolve(), args.protoc_include.resolve(), args.protoc)
    original, timestamp = verify_sources()
    original_descriptor = descriptor_pb2.FileDescriptorProto.FromString(original)
    if original_descriptor.name != PROTO_NAME or list(original_descriptor.dependency) != [TIMESTAMP_NAME]:
        raise ValueError("Unexpected upstream file/dependency")
    # Override language-only options; leave every wire field/enum/service untouched.
    generated_descriptor = descriptor_pb2.FileDescriptorProto.FromString(original)
    generated_descriptor.options.php_namespace = "Arcjet\\Internal\\Proto"
    generated_descriptor.options.php_metadata_namespace = "Arcjet\\Internal\\Proto\\Metadata"
    descriptor_set = descriptor_pb2.FileDescriptorSet()
    descriptor_set.file.add().ParseFromString(timestamp)
    descriptor_set.file.add().CopyFrom(generated_descriptor)
    with tempfile.TemporaryDirectory(prefix="arcjet-proto-generate-") as directory:
        temporary = Path(directory)
        source_set = temporary / "source.bin"
        source_set.write_bytes(descriptor_set.SerializeToString(deterministic=True))
        php = temporary / "php"
        php.mkdir()
        subprocess.run([args.protoc, f"--descriptor_set_in={source_set}", f"--php_out={php}", PROTO_NAME], check=True)
        generated = collect(php / "Arcjet/Internal/Proto", ".php")
        if not generated:
            raise ValueError("protoc did not generate the expected PSR-4 namespace")
        drift = sync(PHP_OUTPUT, generated, args.check, ".php")
    resource_output = {
        "decide.descriptor.textproto": text_format.MessageToString(original_descriptor).encode(),
        "decide.php-descriptor-set.bin": descriptor_set.SerializeToString(deterministic=True),
    }
    # Resources also contain pinned inputs; only synchronize generated resource names.
    for name, value in resource_output.items():
        path = RESOURCES / name
        if not path.exists() or path.read_bytes() != value:
            drift = True
            print(f"{'DRIFT' if args.check else 'WRITE'} resources/proto/{name}")
            if not args.check:
                path.write_bytes(value)
    drift = sync(FIXTURES, generate_fixtures(original, timestamp), args.check) or drift
    if args.check and drift:
        print("Generated protocol files are stale. Run python tools/proto/generate.py", file=sys.stderr)
        return 1
    if args.verify_php:
        verify_php(original, timestamp, args.php)
    print("Protocol generation verified." if args.check else "Protocol generation complete.")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        print(f"Protocol generation failed: {error}", file=sys.stderr)
        sys.exit(1)
