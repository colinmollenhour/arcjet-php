"""Run with: python -m unittest discover -s tools/proto -p 'test_*.py'."""

from __future__ import annotations

import tempfile
from pathlib import Path
import unittest
from unittest.mock import patch

from google.protobuf import descriptor_pb2, descriptor_pool, message_factory

import generate
from fixtures import generate as generate_fixtures


class ExtractionTest(unittest.TestCase):
    def setUp(self) -> None:
        self.original, self.timestamp = generate.verify_sources()

    def extract(self, source: str) -> bytes:
        with tempfile.TemporaryDirectory(prefix="arcjet-proto-extract-test-") as directory:
            path = Path(directory) / "untrusted_pb2.py"
            path.write_text(source)
            return generate.extract_literal(path)

    def test_only_extracts_literal_without_executing_module(self) -> None:
        source = "raise RuntimeError('Must never execute upstream Python')\nDESCRIPTOR = pool.AddSerializedFile(" + repr(self.original) + ")\n"
        self.assertEqual(self.original, self.extract(source))

    def test_rejects_executable_argument_without_evaluating_it(self) -> None:
        with self.assertRaises(ValueError):
            self.extract("DESCRIPTOR = pool.AddSerializedFile(__import__('builtins').print('Must never execute'))")

    def test_rejects_changed_descriptor(self) -> None:
        with self.assertRaisesRegex(ValueError, "SHA256"):
            self.extract("DESCRIPTOR = pool.AddSerializedFile(b'changed descriptor')")

    def test_rejects_ambiguous_or_missing_calls(self) -> None:
        for source in ("pass", "pool.AddSerializedFile(b'a')\npool.AddSerializedFile(b'b')", "pool.AddSerializedFile(b'a', extra=True)"):
            with self.subTest(source=source), self.assertRaisesRegex(ValueError, "exactly one"):
                self.extract(source)

    def test_php_override_changes_only_language_options(self) -> None:
        descriptor_set = descriptor_pb2.FileDescriptorSet.FromString((generate.RESOURCES / "decide.php-descriptor-set.bin").read_bytes())
        self.assertEqual(2, len(descriptor_set.file))
        self.assertEqual(self.timestamp, descriptor_set.file[0].SerializeToString(deterministic=True))
        original = descriptor_pb2.FileDescriptorProto.FromString(self.original)
        generated = descriptor_set.file[1]
        self.assertEqual("Arcjet\\Internal\\Proto", generated.options.php_namespace)
        self.assertEqual("Arcjet\\Internal\\Proto\\Metadata", generated.options.php_metadata_namespace)
        generated.options.php_namespace = original.options.php_namespace
        generated.options.php_metadata_namespace = original.options.php_metadata_namespace
        self.assertEqual(original, generated)

    def test_real_python_fixture_generation_is_reproducible(self) -> None:
        expected = generate_fixtures(self.original, self.timestamp)
        self.assertEqual(24, len(expected))
        self.assertEqual(expected, generate.collect(generate.FIXTURES))

    def test_check_mode_detects_drift_without_writing(self) -> None:
        with tempfile.TemporaryDirectory(prefix="arcjet-proto-check-test-") as directory:
            root = Path(directory)
            destination = root / "output"
            destination.mkdir()
            stale = destination / "stale.php"
            stale.write_bytes(b"stale")
            with patch.object(generate, "ROOT", root), patch("builtins.print"):
                self.assertTrue(generate.sync(destination, {"new.php": b"new"}, check=True))
            self.assertEqual(b"stale", stale.read_bytes())
            self.assertFalse((destination / "new.php").exists())

    def test_official_pool_resolves_timestamp_dependency(self) -> None:
        pool = descriptor_pool.DescriptorPool()
        pool.AddSerializedFile(self.timestamp)
        pool.AddSerializedFile(self.original)
        cls = message_factory.GetMessageClass(pool.FindMessageTypeByName("proto.decide.v1alpha1.DecideResponse"))
        response = cls.FromString((generate.FIXTURES / "response-timestamp.bin").read_bytes())
        self.assertEqual(123456789, response.decision.reason.rate_limit.reset_time.nanos)


if __name__ == "__main__":
    unittest.main()
