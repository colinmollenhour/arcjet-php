# Decide descriptor provenance

| File | Meaning |
| --- | --- |
| `decide.original.bin` | Exact serialized upstream `FileDescriptorProto` byte literal, unchanged. |
| `timestamp.bin` | `FileDescriptorProto` compiled from the pinned compiler's installed `google/protobuf/timestamp.proto`. |
| `provenance.json` | Upstream revision, extraction method, SHA256 hashes, and generation versions. |
| `decide.descriptor.textproto` | Human-readable, lossless descriptor representation of the original schema. |
| `decide.php-descriptor-set.bin` | `FileDescriptorSet` containing timestamp and the Decide descriptor with only PHP namespace options overridden. |

The descriptor is the canonical schema source. `protoc --descriptor_set_in`
consumes it directly; a handwritten or lossy `.proto` reconstruction is not part
of the build. All messages, enums, oneofs, map fields, optional presence, field
numbers, and services are retained. Generated code includes the complete schema,
not only the SDK's supported Shield and BotV2 subset.

The source belongs to the [Arcjet Python SDK](https://github.com/arcjet/arcjet-py)
at the revision in `provenance.json` (Apache-2.0; repository `NOTICE` applies).
The timestamp descriptor derives from Google's BSD-3-Clause Protocol Buffers
schema; see [`TIMESTAMP-LICENSE.txt`](TIMESTAMP-LICENSE.txt).

The package intentionally does not generate Google well-known types; Composer's
`google/protobuf` provides `Google\Protobuf\Timestamp` and its metadata.
No Python dependency, compiler, upstream checkout, or descriptor-file read is
required by the PHP runtime.

See [generation instructions](../../tools/proto/README.md) for regeneration,
offline drift checks, and PHP/Python wire verification.
