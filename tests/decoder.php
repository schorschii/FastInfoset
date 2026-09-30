<?php
require_once dirname(__DIR__) . '/autoload.php';
require_once __DIR__ . '/common.inc.php';

use FastInfoset\Decoder;
use FastInfoset\DecodingException;

function invalid(string $binary, string $label, ?Decoder $decoder = null): void {
	global $count;
	++$count;
	try {
		($decoder ?? new Decoder())->decode($binary);
	} catch (DecodingException $e) {
		if (!str_contains($e->getMessage(), 'at byte')) {
			throw new RuntimeException('Missing error offset');
		}
		return;
	}
	throw new RuntimeException("Accepted invalid input: $label");
}

$d = new Decoder();
$minimal = HEADER . ROOT . "\xFF";
same(DECL . '<r></r>', $d->decode($minimal), 'double terminator');
same(DECL . '<r></r>', $d->decode(HEADER . ROOT . "\xF0\xF0"), 'single terminators');
same(DECL . '<r></r>', $d->decode("<?xml encoding='finf'?>" . $minimal), 'text declaration');
same(DECL . '<r><r></r></r>', $d->decode(HEADER . ROOT . "\0\xFF\xF0"), 'indexed nested element');
same(DECL . '<r a=""></r>', $d->decode(HEADER . "\x7C\0r\x78\0a\xFF\xFF\xF0"), 'empty attribute and attribute double terminator');
same(DECL . '<r>xx</r>', $d->decode(HEADER . ROOT . "\x90x\xA0\xFF"), 'character vocabulary');
same(DECL . '<r>😀</r>', $d->decode(HEADER . ROOT . "\x86\1\xD8\x3D\xDE\0\xFF"), 'UTF-16 surrogate');
same(DECL . '<r>00FF</r>', $d->decode(HEADER . ROOT . "\x8C\1\0\xFF\xFF"), 'hex algorithm');
same(DECL . '<r a="-1 2"></r>', $d->decode(HEADER . "\x7C\0r\x78\0a\x30\x23\xFF\xFF\0\2\xFF\xF0"), 'attribute short algorithm');
// Initial vocabulary: local names, attribute values, chunks, other strings, element surrogates.
$initial = "\xE0\0\0\1\x20\0\x9E\0\0r\0\0v\0\0t\0\0c\0\0\0";
same(DECL . '<r>t<!--c--></r>', $d->decode($initial . "\0\xA0\xE2\x80\xFF"), 'initial vocabulary');
// Initial restricted alphabet at index 32, two-bit symbols 00, 01, 10, padding 11.
$alphabet = "\xE0\0\0\1\x20\x08\0\0\2abc" . ROOT . "\x88\x80\x1B\xFF";
same(DECL . '<r>abc</r>', $d->decode($alphabet), 'custom restricted alphabet');

$basic = file_get_contents(__DIR__ . '/fixtures/basic.fi');
$expected = DECL . '<?test hello?><!--before--><root xmlns="urn:root" xmlns:p="urn:p" xml:lang="en"><p:item id="same">Hello &amp; 世界 😀</p:item><p:item id="same">Hello &amp; 世界 😀</p:item><empty></empty><scope xmlns=""><plain a="&quot;&lt;&#10;&#9;&#13;">text&lt;raw&gt;<!--inside--></plain></scope></root><!--after-->';
same($expected, $d->decode($basic), 'Java namespace fixture');

same(DECL . '<root><bytes>AAH/</bytes><shorts>-32768 0 32767</shorts><ints>-2147483648 0 2147483647</ints><longs>-9223372036854775808 0 9223372036854775807</longs><bools>true false true false true false true</bools><floats>1.5 INF NaN</floats><doubles>-2.5 -INF</doubles><uuids>00112233-4455-6677-8899-aabbccddeeff</uuids><numeric>-12.3E+4 </numeric><date>2026-09-30T12:34:56Z</date><cdata>&lt;raw&gt;&amp;data</cdata></root>', $d->decode(file_get_contents(__DIR__ . '/fixtures/algorithms.fi')), 'Java algorithms fixture');

foreach ([$minimal, $basic] as $fixture) {
	for ($i = 0; $i < strlen($fixture); ++$i) {
		invalid(substr($fixture, 0, $i), "truncation at $i");
	}
}
foreach ([
	'' => 'empty input',
	$minimal . 'x' => 'trailing data',
	HEADER . "\xF0" => 'missing root',
	HEADER . "\0\xFF" => 'unknown element index',
	HEADER . ROOT . "\xA0\xFF" => 'unknown chunk index',
	HEADER . ROOT . "\x80\0\xFF" => 'XML control character',
	HEADER . ROOT . "\x81\xC0\x80\xFF" => 'overlong UTF-8',
	HEADER . ROOT . "\x84x\xFF" => 'odd UTF-16',
	HEADER . ROOT . "\x85\xDC\0\xFF" => 'unpaired surrogate',
	HEADER . ROOT . "\xE2\1--\xFF" => 'invalid comment',
	HEADER . ROOT . "\xE1\2xml\xFF\xFF" => 'reserved PI target',
	HEADER . "\x3C\01\xFF" => 'invalid name',
	HEADER . ROOT . "\xF0" . ROOT . "\xFF" => 'multiple roots',
	HEADER . "\x7C\0r\x78\0a\xFF\0\xFF\xFF\xF0" => 'duplicate attributes',
	HEADER . ROOT . "\x8C\x28x\xFF" => 'unsupported algorithm',
	"\xE0\0\0\1\x20\x10\0" => 'external vocabulary',
	HEADER . "\xC4\xF0" => 'DTD',
	HEADER . ROOT . "\x83\xFF\xFF\xFF\xFF" => 'oversized string',
] as $input => $label) {
	invalid($input, $label);
}
invalid(HEADER . ROOT . "\0\xFF\xF0", 'depth limit', new Decoder(maxDepth: 1));
invalid($minimal, 'output limit', new Decoder(maxOutputBytes: 8));
invalid(HEADER . ROOT . "\x3C\0s\xFF\xF0", 'table limit', new Decoder(maxTableEntries: 1));
same(DECL . '<r></r>', $d->decode($minimal), 'decoder reuse after prior documents');
try { $d->decode('invalid'); } catch (DecodingException) {}
same(DECL . '<r></r>', $d->decode($minimal), 'decoder reuse after failure');

echo "Passed $count checks\n";
