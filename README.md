# Pure PHP Fast Infoset encoder and decoder

Converts between UTF-8 XML and binary Fast Infoset (ITU-T X.891 / ISO/IEC 24824-1).
Requires **64-bit PHP 8.1+**, with no other PHP packages, XML extensions, Java/FFI, or any external processes at runtime.

```php
require 'autoload.php';

$encoder = new FastInfoset\Encoder();
$binary = $encoder->encode(file_get_contents('document.xml'));
file_put_contents('document.fi', $binary);

$decoder = new FastInfoset\Decoder();
$xml = $decoder->decode(file_get_contents('document.fi'));
file_put_contents('document.xml', $xml);
```

Invalid, truncated, and unsupported input throws `FastInfoset\DecodingException` with a byte offset. A decoder can be reused across documents, including after a failed decode.

The encoder's `encode(string $xml): string` method throws `FastInfoset\EncodingException` for malformed or unsupported XML, with a byte offset into the input after XML line-ending normalization. Encoder instances can also be reused after success or failure.

## Command line

```sh
php bin/fi-encode document.xml > document.fi

php bin/fi-decode document.fi > document.xml
```

Both commands accept `-` for stdin and `--help` for usage. Errors go to stderr with exit status 1. Conversion completes before output is written, so malformed documents do not produce partial output.

## Encoder features

- Pure PHP parsing of UTF-8, namespace-aware XML 1.0, with an optional UTF-8 BOM and XML declaration. Standalone metadata is preserved.
- Elements, attributes, namespace declarations and scope changes, Unicode names and text, comments, processing instructions, and mixed content.
- XML line endings and attribute whitespace are normalized correctly; referenced whitespace is preserved.
- Vocabulary indexes for repeated names, attribute values, character content, comments, and processing instructions. Short strings are indexed by default, long strings are written literally to avoid retaining them in the vocabulary.
- Malformed XML, unbound prefixes, duplicate expanded attributes, and invalid character references are rejected.

The encoder emits UTF-8 Fast Infoset strings using an in-document vocabulary. It does not infer binary data types from XML text or emit restricted alphabets, initial/external vocabularies, or custom encoding algorithms. CDATA is encoded as ordinary character content. The decoder can read these documents, as can the Java reference implementation.

Input must be UTF-8 XML 1.0. Other input encodings, DTDs and entity declarations, XML 1.1, fragments, and processing instruction targets containing colons are unsupported.

Whitespace outside the root, attribute ordering relative to namespace declarations, quote style, empty-element syntax, and CDATA boundaries are not preserved.

```php
$encoder = new FastInfoset\Encoder(
    maxDepth: 256,
    maxOutputBytes: 64 * 1024 * 1024,
    maxTableEntries: 1_048_576, // Per vocabulary table; also capped by the format.
    maxIndexedStringBytes: 64, // Set to zero to disable value/text indexing.
);
```

When a value table fills, subsequent new values are written literally. New names require vocabulary entries and throw if their table is full.

## Decoder features

- Literal and indexed element and attribute names, namespace declarations, namespace scopes, and vocabulary reuse.
- UTF-8 and big-endian UTF-16 strings, including surrogate pairs.
- All string-length and name/value index encodings.
- Embedded initial vocabularies, including name surrogates and restricted alphabets.
- Built-in numeric and date/time restricted alphabets and embedded custom alphabets.
- Built-in hex, base64, short, int, long, boolean, float, double, UUID, and CDATA encoding algorithms. Binary values are rendered as XML lexical strings.
- Comments, processing instructions, single/double terminators, additional document data, original character encoding metadata, and standalone metadata.
- Optional Fast Infoset XML declaration before the binary header.
- XML character/name validation, namespace binding checks, and duplicate attribute checks.

This implementation supports XML 1.0 documents, not every optional X.891 feature. External vocabularies, application-defined encoding algorithms, DTDs, notations, unparsed entities, unexpanded entity references, XML 1.1, and document fragments are explicitly rejected. It never fetches external resources. Embedded algorithm URIs can be read, but their algorithms cannot be executed.

Output preserves the supported XML content rather than the original lexical formatting: empty elements use explicit end tags, CDATA becomes escaped text, and numeric algorithm values may use equivalent lexical representations. Additional document data and the original character encoding are not emitted.

Both APIs hold the input, vocabulary, and output in memory; they are not streaming parsers. Use suitable input-size limits in your application. Decoder limits are configurable:

```php
$decoder = new FastInfoset\Decoder(
    maxDepth: 256,
    maxOutputBytes: 64 * 1024 * 1024,
    maxTableEntries: 1_048_576, // Per vocabulary table.
);
```

These limits bound nesting, final output, and table entry counts, not total process memory or the size of temporary decoded values.

## Tests

```sh
php tests/run.php
composer test
```

The fixtures were produced by the Metro Fast Infoset 1.2.18 Java encoder. Additional byte-level tests cover initial vocabularies, restricted alphabets, terminators, limits, malformed input, and every truncation of the small fixtures. Encoder tests cover round trips, XML normalization and validation, namespace scope restoration, byte-level encodings, limits, and vocabulary reuse across 8,300 distinct element/attribute names and values.

To independently verify the PHP encoder with the Java decoder, export the encoder test cases and compare the parsed XML structures:

```sh
FI_INTEROP_DIR=/tmp/fi-interop php -n tests/run.php
java -cp /path/to/FastInfoset-1.2.18.jar tests/VerifyEncoding.java /tmp/fi-interop
```

Format references: [ITU-T X.891](https://www.itu.int/rec/T-REC-X.891) and [Metro Fast Infoset reference implementation](https://github.com/javaee/metro-fi).

Have a lot of fun.
