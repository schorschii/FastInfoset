<?php
require_once dirname(__DIR__) . '/autoload.php';
require_once __DIR__ . '/common.inc.php';

use FastInfoset\Decoder;
use FastInfoset\DecodingException;
use FastInfoset\Encoder;
use FastInfoset\EncodingException;

function invalidXml(string $xml, string $label, ?Encoder $encoder = null): void {
	global $count;
	++$count;
	try {
		($encoder ?? new Encoder())->encode($xml);
	} catch (EncodingException $e) {
		if (!str_contains($e->getMessage(), 'at byte')) {
			throw new RuntimeException('Missing encoder error offset');
		}
		return;
	}
	throw new RuntimeException("Accepted invalid XML: $label");
}

$d = new Decoder();
$encoder = new Encoder();
$encoderCases = [
	['<r/>', DECL . '<r></r>'],
	['<r a=""/>', DECL . '<r a=""></r>'],
	['<r><r a="x">x</r><r a="x">x</r></r>', DECL . '<r><r a="x">x</r><r a="x">x</r></r>'],
	['<r a="0">0<x/>0</r>', DECL . '<r a="0">0<x></x>0</r>'],
	['<r>æ世界😀 &#x1F600;&#128512;&#00097;&#x000061;</r>', DECL . '<r>æ世界😀 😀😀aa</r>'],
	['<r>&lt;&gt;&amp;&apos;&quot;&amp;lt;</r>', DECL . '<r>&lt;&gt;&amp;\'"&amp;lt;</r>'],
	["<r a='\t\n\r\n&#9;&#10;&#13;'>&#13;\r\n\r</r>", DECL . "<r a=\"   &#9;&#10;&#13;\">&#13;\n\n</r>"],
	['<r><![CDATA[<a>&unknown;]]><![CDATA[]]>x</r>', DECL . '<r>&lt;a&gt;&amp;unknown;x</r>'],
	['<?before data?><!--before--><r><!--inside--><?after?></r><!--after-->', DECL . '<?before data?><!--before--><r><!--inside--><?after?></r><!--after-->'],
	['<r><?pi   data  ?><?pi   data  ?></r>', DECL . '<r><?pi data  ?><?pi data  ?></r>'],
	["\xEF\xBB\xBF<?xml version='1.0' encoding='utf-8'?><r/>", DECL . '<r></r>'],
	["<?xml version = '1.0' standalone = 'yes' ?><r/>", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><r></r>'],
	['<?xml version="1.0" encoding="UTF-8" standalone="no"?><r/>', '<?xml version="1.0" encoding="UTF-8" standalone="no"?><r></r>'],
	[" \n<!--x--><r/> \t\n", DECL . '<!--x--><r></r>'],
	['<r xml:lang="en" xml:space="preserve"/>', DECL . '<r xml:lang="en" xml:space="preserve"></r>'],
	['<r xmlns:xml="http://www.w3.org/XML/1998/namespace" xml:lang="de"/>', DECL . '<r xmlns:xml="http://www.w3.org/XML/1998/namespace" xml:lang="de"></r>'],
	['<p:r p:a="1" a="2" xmlns:p="urn:p"/>', DECL . '<p:r xmlns:p="urn:p" p:a="1" a="2"></p:r>'],
	['<r xmlns="urn:r"><x xmlns=""><r/></x><r/></r>', DECL . '<r xmlns="urn:r"><x xmlns=""><r></r></x><r></r></r>'],
	['<r xmlns:p="urn:one"><p:x/><s xmlns:p="urn:two"><p:x/></s><p:x/></r>', DECL . '<r xmlns:p="urn:one"><p:x></p:x><s xmlns:p="urn:two"><p:x></p:x></s><p:x></p:x></r>'],
	['<r xmlns:a="urn:same" xmlns:b="urn:same"><a:x/><b:x/></r>', DECL . '<r xmlns:a="urn:same" xmlns:b="urn:same"><a:x></a:x><b:x></b:x></r>'],
	['<é:根 xmlns:é="urn:unicode" é:名="值"/>', DECL . '<é:根 xmlns:é="urn:unicode" é:名="值"></é:根>'],
];
same(HEADER . ROOT . "\xF0\xF0", $encoder->encode('<r/>'), 'encoder minimal bytes');
same(HEADER . ROOT . "\x90x\0\xF0\xA0\xF0\xF0", $encoder->encode('<r>x<r/>x</r>'), 'encoder vocabulary reference bytes');

foreach (['basic', 'algorithms'] as $fixture) {
	$xml = $d->decode(file_get_contents(__DIR__ . "/fixtures/$fixture.fi"));
	$encoderCases[] = [$xml, $xml];
}
$xml = DECL . '<root>';
for ($pass = 0; $pass < 2; ++$pass) {
	for ($i = 0; $i < 8300; ++$i) {
		$xml .= "<n$i a$i=\"v$i\">text$i</n$i>";
	}
}
$xml .= '</root>';
$encoderCases[] = [$xml, $xml];
$xml = DECL . '<root>';
foreach ([1, 2, 3, 8, 9, 64, 65, 258, 259, 264, 265, 320, 321, 1024] as $length) {
	$name = str_repeat('x', $length);
	$xml .= '<' . $name . ' a="' . str_repeat('y', $length) . '">' . str_repeat('z', $length) . '</' . $name . '>';
}
$xml .= '</root>';
$encoderCases[] = [$xml, $xml];

// Optional export for independent validation with tests/VerifyEncoding.java.
$interopDir = getenv('FI_INTEROP_DIR');
if ($interopDir !== false && !is_dir($interopDir)) {
	mkdir($interopDir, 0700, true);
}
foreach ($encoderCases as $i => [$input, $expected]) {
	$binary = $encoder->encode($input);
	same($expected, $d->decode($binary), "encoder round trip $i");
	if ($interopDir !== false) {
		file_put_contents($interopDir . "/case-$i.xml", $input);
		file_put_contents($interopDir . "/case-$i.fi", $binary);
	}
}

foreach ([
	'', ' ', '<!--only-->', '<?pi data?>', '<r>', '<r', '<r/', '<r /', '<r / >',
	'<r></s>', '<r></ r>', '<r></r x>', '<r/><s/>', 'text<r/>', '<r/>text',
	'<r>&</r>', '<r>&amp</r>', '<r>&unknown;</r>', '<r>&#0;</r>', '<r>&#xD800;</r>',
	'<r>&#x110000;</r>', '<r>&#xFFFE;</r>', '<r>&#9999999999999999999999;</r>',
	'<r>&#X41;</r>', '<r>&#-1;</r>', '<r>&#x;</r>', '<r>&# 65;</r>',
	'<r a="<"/>', '<r a="&"/>', '<r a=1/>', '<r a="x"a="y"/>', '<r a="x" a="y"/>',
	'<r a/>', '<r a="unterminated>', '<1r/>', '<:r/>', '<r:/>', '<a:b:c/>', '<r a:b:c="x"/>',
	'<r>]]></r>', '<![CDATA[x]]><r/>', '<r><![CDATA[x</r>', '<r><!--x--y--></r>',
	'<r><!--x---></r>', '<r><!--x</r>', '<r><?pi x</r>', '<r><?xml x?></r>',
	'<r><?XML?></r>', '<r><?p:x?></r>', '<?xml?><r/>', ' <?xml version="1.0"?><r/>',
	'<?xml version="1.1"?><r/>', '<?xml encoding="UTF-8" version="1.0"?><r/>',
	'<?xml version="1.0" encoding="UTF-16"?><r/>', '<?xml version="1.0" standalone="maybe"?><r/>',
	'<?xml version="1.0" version="1.0"?><r/>', '<!DOCTYPE r><r/>',
	'<!DOCTYPE r [<!ENTITY x "value">]><r>&x;</r>',
	'<p:r/>', '<r p:a="x"/>', '<r xmlns:p=""/>', '<r xmlns:xmlns="urn:x"/>',
	'<r xmlns="http://www.w3.org/XML/1998/namespace"/>', '<r xmlns:xml="urn:x"/>',
	'<r xmlns:p="http://www.w3.org/XML/1998/namespace"/>', '<r xmlns="http://www.w3.org/2000/xmlns/"/>',
	'<r xmlns:p="http://www.w3.org/2000/xmlns/"/>', '<xmlns:r/>',
	'<r xmlns:a="urn:x" xmlns:b="urn:x" a:c="1" b:c="2"/>',
	'<r xmlns:p="urn:x" xmlns:p="urn:y"/>',
	"<r>\0</r>", "<r>\xC0\x80</r>", "<r>\xED\xA0\x80</r>", "\xFF\xFE<\0r\0/\0>\0",
] as $i => $input) {
	invalidXml($input, "malformed input $i");
}
$complete = '<r xmlns:p="urn:p" a="v"><p:x>text&amp;more</p:x></r>';
for ($i = 0; $i < strlen($complete); ++$i) {
	invalidXml(substr($complete, 0, $i), "XML truncation at $i");
}
invalidXml('<r><r/></r>', 'encoder depth limit', new Encoder(maxDepth: 1));
invalidXml('<r/>', 'encoder output limit', new Encoder(maxOutputBytes: 8));
invalidXml('<r><s/></r>', 'encoder table limit', new Encoder(maxTableEntries: 1));
same(DECL . '<r><r a="x">x</r><r a="y">y</r><r a="z">z</r></r>', $d->decode((new Encoder(maxTableEntries: 2))->encode('<r><r a="x">x</r><r a="y">y</r><r a="z">z</r></r>')), 'value vocabulary fills without losing values');
same(DECL . '<r>xx<r></r>xx</r>', $d->decode((new Encoder(maxIndexedStringBytes: 0))->encode('<r>xx<r/>xx</r>')), 'disabled value indexing');
try { $encoder->encode('<invalid>'); } catch (EncodingException) {}
same(DECL . '<r></r>', $d->decode($encoder->encode('<r/>')), 'encoder reuse after failure');

echo "Passed $count checks\n";
