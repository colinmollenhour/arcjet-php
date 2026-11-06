# How to regenerate the Decide protocol

Regenerate the full internal PHP message package and cross-language fixtures from
the committed descriptors. Normal generation and checks are offline and need no
upstream checkout. The PHP SDK runtime needs only Composer's `google/protobuf`
package, not these developer tools, Python, or `protoc`.

## Prerequisites

- Python 3.10 or newer, with the exact dependency in `requirements.txt`.
- `protoc` 36.2 (`protoc --version` must print `libprotoc 36.2`).
- PHP and `composer install` for PHP verification only.

## Regenerate and verify

1. Create an isolated Python environment and install the pinned build dependency:

   ```sh
   python3 -m venv /tmp/arcjet-proto-venv
   /tmp/arcjet-proto-venv/bin/python -m pip install -r tools/proto/requirements.txt
   ```

2. Generate the checked-in artifacts from the repository root:

   ```sh
   /tmp/arcjet-proto-venv/bin/python tools/proto/generate.py
   ```

   Use `--protoc /PATH/TO/protoc` if the compiler is not on `PATH`.
   Generated PHP lives under `src/Internal/Proto/`, with the namespaces
   `Arcjet\Internal\Proto` and `Arcjet\Internal\Proto\Metadata`.
   Timestamp classes come from the official PHP runtime and are not duplicated.

3. Check reproducibility without writing generated files:

   ```sh
   /tmp/arcjet-proto-venv/bin/python tools/proto/generate.py --check
   /tmp/arcjet-proto-venv/bin/python -m unittest discover -s tools/proto -p 'test_*.py'
   php vendor/bin/phpunit tests/Protocol
   ```

   `--check` exits 1 for changed or missing artifacts and stale generated PHP or
   fixture files. It verifies the original hash and timestamp provenance first.
   It creates only temporary compiler output, never modifies committed files,
   and does not inspect `arcjet-py/`.

4. Verify the reverse wire direction with Python independently decoding messages
   built with the actual PHP setters:

   ```sh
   /tmp/arcjet-proto-venv/bin/python tools/proto/generate.py --check --verify-php
   ```

   This verifies five messages, including maps deliberately inserted in a
   different order. Use `--php /PATH/TO/php` to select a PHP interpreter.

## Re-extract the reviewed upstream descriptor

Run this step only to reproduce the original import. Normal regeneration uses
the committed binary inputs:

```sh
/tmp/arcjet-proto-venv/bin/python tools/proto/generate.py \
  --import-upstream /PATH/TO/arcjet-py \
  --protoc-include /PATH/TO/protoc/include
```

The import requires upstream revision
`498b28b17ebc1a652974ab8ae98c60604e892095` and descriptor SHA256
`d9235e410d6b87a099da2bffb324186eefce4d7a007352a520040968cca06d6e`.
It extracts the `AddSerializedFile` byte literal from
`src/arcjet/proto/decide/v1alpha1/decide_pb2.py` using `ast.parse` and
`ast.literal_eval`. It never imports or executes the upstream module.
It compiles `google/protobuf/timestamp.proto` from the installed compiler's
include directory and records its source and descriptor hashes.

For an upstream upgrade, review and explicitly update the revision and original
hash pins in `generate.py`, the offline PHP pin test, and compiler/runtime pins
as needed. Re-import, regenerate, and review wire-schema and fixture changes.
Do not replace the upstream bytes with a reserialized or PHP-modified descriptor.
