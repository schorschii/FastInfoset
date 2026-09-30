<?php

namespace FastInfoset;

/** Decodes an X.891 Fast Infoset document into UTF-8 XML without XML extensions. */
final class Decoder
{
	private const XML_NS = 'http://www.w3.org/XML/1998/namespace';
	private string $data = '';
	private int $offset = 0;
	private array $tables = [];
	private string $output = '';

	public function __construct(
		private readonly int $maxDepth = 256,
		private readonly int $maxOutputBytes = 67108864,
		private readonly int $maxTableEntries = 1048576,
	) {
		if ($maxDepth < 1 || $maxOutputBytes < 1 || $maxTableEntries < 1) {
			throw new \InvalidArgumentException('Limits must be positive');
		}
	}

	public function decode(string $binary): string
	{
		if (PHP_INT_SIZE < 8) {
			throw new \LogicException('A 64-bit PHP build is required');
		}
		$this->data = $binary;
		$this->offset = 0;
		$this->output = '';
		$this->tables = array_fill_keys(['prefix', 'namespace', 'local', 'element', 'attribute',
			'value', 'chunk', 'other', 'ncname', 'uri', 'alphabet', 'algorithm'], []);
		$this->tables['prefix'][] = 'xml';
		$this->tables['namespace'][] = self::XML_NS;
		try {
			if (str_starts_with($binary, '<?xml ')) {
				if (!preg_match("/\\A<\\?xml (?:version='1\\.[01]' )?encoding='finf'(?: standalone='(?:yes|no)')?\\?>/", $binary, $m)) {
					$this->fail('Invalid Fast Infoset XML declaration');
				}
				$this->offset = strlen($m[0]);
			}
			if ($this->read(4) !== "\xE0\x00\x00\x01") {
				$this->fail('Invalid Fast Infoset header or unsupported version');
			}
			$flags = $this->byte();
			if ($flags & 0x98) {
				$this->fail('Reserved document flag, notations, or unparsed entities are unsupported');
			}
			if ($flags & 0x40) {
				$count = $this->sequenceCount();
				for ($i = 0; $i < $count; ++$i) {
					$this->utf8($this->octets(2));
					$this->octets(2);
				}
			}
			if ($flags & 0x20) {
				$this->initialVocabulary();
			}
			if ($flags & 4) {
				$this->utf8($this->octets(2)); // Original encoding; output is always UTF-8.
			}
			$standalone = '';
			if ($flags & 2) {
				$b = $this->byte();
				if ($b > 1) {
					$this->fail('Invalid standalone flag');
				}
				$standalone = ' standalone="' . ($b ? 'yes' : 'no') . '"';
			}
			$version = ($flags & 1) ? $this->value('other') : '1.0';
			if ($version !== '1.0') {
				$this->fail('Only XML 1.0 output is supported');
			}
			$this->emit('<?xml version="1.0" encoding="UTF-8"' . $standalone . '?>');
			$root = false;
			while (true) {
				$b = $this->byte();
				if ($b === 0xF0) {
					break;
				}
				if ($b < 0x80 && !$root) {
					$root = true;
					if ($this->element($b, ['xml' => self::XML_NS, '' => ''], 1)) {
						break;
					}
				} elseif ($b === 0xE1 || $b === 0xE2) {
					$this->misc($b);
				} else {
					$this->fail('Invalid or unsupported document item');
				}
			}
			if (!$root || $this->offset !== strlen($this->data)) {
				$this->fail('Missing root element or trailing data');
			}
			return $this->output;
		} finally {
			$this->data = '';
			$this->tables = [];
			$this->output = '';
		}
	}

	/** Returns true when a double terminator also closes the parent. */
	private function element(int $b, array $scope, int $depth): bool
	{
		if ($depth > $this->maxDepth) {
			$this->fail('Maximum element depth exceeded');
		}
		$attributes = (bool) ($b & 0x40);
		$declarations = [];
		if (($b & 0x3F) === 0x38) {
			while (($n = $this->byte()) !== 0xF0) {
				if (($n & 0xFC) !== 0xCC) {
					$this->fail('Invalid namespace attribute');
				}
				$prefix = ($n & 2) ? $this->identifying('prefix') : '';
				$uri = ($n & 1) ? $this->identifying('namespace') : '';
				if (isset($declarations[$prefix]) || $prefix === 'xmlns'
					|| ($prefix === 'xml') !== ($uri === self::XML_NS)
					|| $uri === 'http://www.w3.org/2000/xmlns/' || ($prefix !== '' && $uri === '')) {
					$this->fail('Invalid or duplicate namespace declaration');
				}
				if ($prefix !== '') {
					$this->name($prefix);
				}
				$declarations[$prefix] = $uri;
				$scope[$prefix] = $uri;
			}
			$b = $this->byte();
			if ($b & 0x40) {
				$this->fail('Unexpected attribute flag after namespace attributes');
			}
		}
		$q = $this->qualified($b, false);
		$this->checkScope($q, $scope, false);
		$this->emit('<' . $q[3]);
		foreach ($declarations as $prefix => $uri) {
			$this->emit(' xmlns' . ($prefix === '' ? '' : ':' . $prefix) . '="' . $this->escape($uri, true) . '"');
		}
		$empty = false;
		if ($attributes) {
			$seen = [];
			while (true) {
				$a = $this->byte();
				if ($a === 0xF0 || $a === 0xFF) {
					$empty = $a === 0xFF;
					break;
				}
				$aq = $this->qualified($a, true);
				$this->checkScope($aq, $scope, true);
				$key = $aq[1] . "\0" . $aq[2];
				if (isset($seen[$key]) || $aq[3] === 'xmlns') {
					$this->fail('Duplicate or reserved attribute');
				}
				$seen[$key] = true;
				$this->emit(' ' . $aq[3] . '="' . $this->escape($this->value('value'), true) . '"');
			}
		}
		$this->emit('>');
		$parentClosed = false;
		if (!$empty) {
			while (true) {
				$b = $this->byte();
				if ($b === 0xF0 || $b === 0xFF) {
					$parentClosed = $b === 0xFF;
					break;
				}
				if ($b < 0x80) {
					if ($this->element($b, $scope, $depth + 1)) {
						break;
					}
				} elseif ($b <= 0xB8) {
					$this->emit($this->escape($this->chunk($b)));
				} elseif ($b === 0xE1 || $b === 0xE2) {
					$this->misc($b);
				} else {
					$this->fail('Invalid or unsupported element item');
				}
			}
		}
		$this->emit('</' . $q[3] . '>');
		return $parentClosed;
	}

	private function qualified(int $b, bool $attribute): array
	{
		$table = $attribute ? 'attribute' : 'element';
		$b = $attribute ? $b : ($b & 0xBF);
		$literal = $attribute ? 0x78 : 0x3C;
		if (($b & 0xFC) === $literal) {
			$state = $b & 3;
			if ($state === 2) {
				$this->fail('Prefix without namespace');
			}
			$prefix = ($state & 2) ? $this->identifying('prefix', true) : '';
			$uri = ($state & 1) ? $this->identifying('namespace', true) : '';
			$local = $this->identifying('local');
			$q = $this->makeName($prefix, $uri, $local);
			$this->add($table, $q);
			return $q;
		}
		if ($attribute) {
			$index = $this->index($b, 2);
		} else {
			$index = $this->index($b, 3);
		}
		return $this->lookup($table, $index);
	}

	private function makeName(string $prefix, string $uri, string $local): array
	{
		$this->name($local);
		if ($prefix !== '') {
			$this->name($prefix);
			if ($uri === '') {
				$this->fail('Prefix without namespace');
			}
		}
		return [$prefix, $uri, $local, ($prefix === '' ? '' : $prefix . ':') . $local];
	}

	private function checkScope(array $q, array $scope, bool $attribute): void
	{
		if ($attribute && $q[0] === '') {
			if ($q[1] !== '') {
				$this->fail('Unprefixed attribute has a namespace');
			}
			return;
		}
		if (!array_key_exists($q[0], $scope) || $scope[$q[0]] !== $q[1]) {
			$this->fail('Name uses an unbound namespace');
		}
	}

	private function identifying(string $table, bool $indexOnly = false): string
	{
		$b = $this->byte();
		if ($b & 0x80) {
			return $this->lookup($table, $this->index($b & 0x7F, 2));
		}
		if ($indexOnly) {
			$this->fail('Expected a vocabulary index');
		}
		$s = $this->utf8($this->octets(2, $b));
		$this->add($table, $s);
		return $s;
	}

	private function value(string $table, bool $initial = false): string
	{
		$b = $this->byte();
		if ($b === 0xFF && !$initial) {
			return '';
		}
		if ($b & 0x80) {
			if ($initial) {
				$this->fail('Initial vocabulary requires literal strings');
			}
			return $this->lookup($table, $this->index($b & 0x7F, 2));
		}
		$kind = ($b >> 4) & 3;
		if ($kind < 2) {
			$raw = $this->octets(5, $b & 15);
			$s = $kind === 0 ? $this->utf8($raw) : $this->utf16($raw);
		} else {
			$next = $this->byte();
			$id = (($b & 15) << 4) | ($next >> 4);
			$raw = $this->octets(5, $next & 15);
			if ($kind === 3 && $id === 9) {
				$this->fail('CDATA algorithm is only valid in character content');
			}
			$s = $kind === 2 ? $this->alphabet($id, $raw) : $this->algorithm($id, $raw);
		}
		if (!$initial && ($b & 0x40)) {
			$this->add($table, $s);
		}
		return $s;
	}

	private function chunk(int $b): string
	{
		if ($b >= 0xA0) {
			return $this->lookup('chunk', $this->index($b - 0xA0, 4));
		}
		$kind = ($b >> 2) & 3;
		if ($kind < 2) {
			$raw = $this->octets(7, $b & 3);
			$s = $kind === 0 ? $this->utf8($raw) : $this->utf16($raw);
		} else {
			$next = $this->byte();
			$id = (($b & 3) << 6) | ($next >> 2);
			$raw = $this->octets(7, $next & 3);
			$s = $kind === 2 ? $this->alphabet($id, $raw) : $this->algorithm($id, $raw);
		}
		if ($b & 0x10) {
			$this->add('chunk', $s);
		}
		return $s;
	}

	private function misc(int $b): void
	{
		if ($b === 0xE2) {
			$s = $this->value('other');
			if (str_contains($s, '--') || str_ends_with($s, '-')) {
				$this->fail('Invalid XML comment');
			}
			$this->emit('<!--' . $s . '-->');
		} else {
			$target = $this->identifying('ncname');
			$this->name($target);
			$s = $this->value('other');
			if (strcasecmp($target, 'xml') === 0 || str_contains($s, '?>')) {
				$this->fail('Invalid processing instruction');
			}
			$this->emit('<?' . $target . ($s === '' ? '' : ' ' . $s) . '?>');
		}
	}

	private function initialVocabulary(): void
	{
		$a = $this->byte();
		$b = $this->byte();
		if ($a & 0xF0) {
			$this->fail('External vocabularies and reserved vocabulary flags are unsupported');
		}
		foreach ([8 => 'alphabet', 4 => 'algorithm', 2 => 'prefix', 1 => 'namespace'] as $flag => $table) {
			if ($a & $flag) {
				$this->initialStrings($table, false);
			}
		}
		foreach ([128 => 'local', 64 => 'ncname', 32 => 'uri', 16 => 'value', 8 => 'chunk', 4 => 'other'] as $flag => $table) {
			if ($b & $flag) {
				$this->initialStrings($table, $flag <= 8);
			}
		}
		foreach ([2 => 'element', 1 => 'attribute'] as $flag => $table) {
			if (!($b & $flag)) {
				continue;
			}
			$count = $this->sequenceCount();
			for ($i = 0; $i < $count; ++$i) {
				$flags = $this->byte();
				if ($flags > 3 || $flags === 2) {
					$this->fail('Invalid name surrogate flags');
				}
				$prefix = ($flags & 2) ? $this->lookup('prefix', $this->index($this->byte(), 2) + 1) : '';
				$uri = ($flags & 1) ? $this->lookup('namespace', $this->index($this->byte(), 2) + 1) : '';
				$local = $this->lookup('local', $this->index($this->byte(), 2));
				$this->add($table, $this->makeName($prefix, $uri, $local));
			}
		}
	}

	private function initialStrings(string $table, bool $encoded): void
	{
		$count = $this->sequenceCount();
		for ($i = 0; $i < $count; ++$i) {
			$this->add($table, $encoded ? $this->value($table, true) : $this->utf8($this->octets(2)));
		}
	}

	private function sequenceCount(): int
	{
		$b = $this->byte();
		if ($b < 128) {
			return $b + 1;
		}
		if ($b > 0x8F) {
			$this->fail('Invalid sequence length');
		}
		return (($b & 15) << 16) + $this->uint(2) + 129;
	}

	private function index(int $b, int $bit): int
	{
		[$small, $medium, $large, $base, $top] = match ($bit) {
			2 => [64, 0x60, 0x70, 8256, 0],
			3 => [32, 0x28, 0x30, 2080, 526368],
			4 => [16, 0x14, 0x18, 1040, 263184],
		};
		if ($b < $small) {
			$index = $b;
		} elseif ($b < $medium) {
			$index = (($b - $small) << 8) + $this->byte() + $small;
		} elseif ($b < $large) {
			$index = (($b - $medium) << 16) + $this->uint(2) + $base;
		} elseif ($b === $large && $bit !== 2) {
			$index = $this->uint(3) + $top;
		} else {
			$this->fail('Invalid vocabulary index encoding');
		}
		if ($index >= 1048576) {
			$this->fail('Vocabulary index exceeds format limit');
		}
		return $index;
	}

	private function octets(int $bit, ?int $b = null): string
	{
		$b ??= $this->byte();
		[$small, $large] = match ($bit) {2 => [64, 96], 5 => [8, 12], 7 => [2, 3]};
		if ($b < $small) {
			$length = $b + 1;
		} elseif ($b === $small) {
			$length = $this->byte() + $small + 1;
		} elseif ($b === $large) {
			$length = $this->uint(4) + $small + 257;
		} else {
			$this->fail('Invalid octet string length');
		}
		return $this->read($length);
	}

	private function alphabet(int $id, string $raw): string
	{
		$alphabet = match ($id) {
			0 => '0123456789-+.E ',
			1 => '0123456789-:TZ ',
			default => $id >= 32 ? $this->lookup('alphabet', $id - 32) : $this->fail('Reserved restricted alphabet'),
		};
		$chars = preg_split('//u', $alphabet, -1, PREG_SPLIT_NO_EMPTY);
		$count = count($chars);
		if ($count < 2) {
			$this->fail('Restricted alphabet is too small');
		}
		$bits = 2;
		while ((1 << $bits) <= $count) {
			++$bits;
		}
		$s = '';
		$end = strlen($raw) * 8;
		$pos = 0;
		while ($pos + $bits <= $end) {
			$value = 0;
			for ($j = 0; $j < $bits; ++$j, ++$pos) {
				$value = ($value << 1) | ((ord($raw[intdiv($pos, 8)]) >> (7 - $pos % 8)) & 1);
			}
			if ($value === (1 << $bits) - 1) {
				if ($end - $pos >= 8) {
					$this->fail('Premature restricted alphabet padding');
				}
				break;
			}
			if ($value >= $count) {
				$this->fail('Invalid restricted alphabet character');
			}
			$s .= $chars[$value];
		}
		for (; $pos < $end; ++$pos) {
			if (!(ord($raw[intdiv($pos, 8)]) & (1 << (7 - $pos % 8)))) {
				$this->fail('Invalid restricted alphabet padding');
			}
		}
		return $this->utf8($s);
	}

	private function algorithm(int $id, string $raw): string
	{
		if ($id === 0) {
			return strtoupper(bin2hex($raw));
		}
		if ($id === 1) {
			return base64_encode($raw);
		}
		if ($id === 9) {
			return $this->utf8($raw);
		}
		if ($id === 5) {
			$unused = ord($raw[0]) >> 4;
			$length = strlen($raw) * 8;
			if ($unused > 7 || $length - $unused < 5) {
				$this->fail('Invalid boolean encoding');
			}
			$values = [];
			for ($i = 4; $i < $length - $unused; ++$i) {
				$values[] = (ord($raw[intdiv($i, 8)]) & (1 << (7 - $i % 8))) ? 'true' : 'false';
			}
			return implode(' ', $values);
		}
		[$width, $format] = match ($id) {
			2 => [2, 'n'], 3 => [4, 'N'], 4 => [8, 'J'],
			6 => [4, 'G'], 7 => [8, 'E'], 8 => [16, ''],
			default => $this->fail('Unsupported encoding algorithm ' . $id),
		};
		if (strlen($raw) % $width !== 0) {
			$this->fail('Invalid encoding algorithm payload length');
		}
		$values = [];
		for ($i = 0, $n = strlen($raw); $i < $n; $i += $width) {
			$part = substr($raw, $i, $width);
			if ($id === 8) {
				$hex = bin2hex($part);
				$values[] = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
					. '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
				continue;
			}
			$v = unpack($format, $part)[1];
			if ($id === 2 && $v >= 32768) {
				$v -= 65536;
			} elseif ($id === 3 && $v >= 2147483648) {
				$v -= 4294967296;
			}
			$values[] = is_float($v)
				? (is_nan($v) ? 'NaN' : (is_infinite($v) ? ($v < 0 ? '-INF' : 'INF') : str_replace(',', '.', sprintf($id === 6 ? '%.9g' : '%.17g', $v))))
				: (string) $v;
		}
		return implode(' ', $values);
	}

	private function utf8(string $s): string
	{
		if (!preg_match('/\A[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*\z/u', $s)) {
			$this->fail('Invalid UTF-8 or XML character');
		}
		return $s;
	}

	private function utf16(string $raw): string
	{
		if (strlen($raw) % 2) {
			$this->fail('Odd UTF-16 byte length');
		}
		$s = '';
		for ($i = 0, $n = strlen($raw); $i < $n; $i += 2) {
			$cp = (ord($raw[$i]) << 8) | ord($raw[$i + 1]);
			if ($cp >= 0xD800 && $cp <= 0xDBFF) {
				$i += 2;
				if ($i >= $n) {
					$this->fail('Truncated UTF-16 surrogate pair');
				}
				$low = (ord($raw[$i]) << 8) | ord($raw[$i + 1]);
				if ($low < 0xDC00 || $low > 0xDFFF) {
					$this->fail('Invalid UTF-16 surrogate pair');
				}
				$cp = 0x10000 + (($cp - 0xD800) << 10) + $low - 0xDC00;
			} elseif ($cp >= 0xDC00 && $cp <= 0xDFFF) {
				$this->fail('Unpaired UTF-16 surrogate');
			}
			$s .= match (true) {
				$cp < 0x80 => chr($cp),
				$cp < 0x800 => chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 63)),
				$cp < 0x10000 => chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 63)) . chr(0x80 | ($cp & 63)),
				default => chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 63)) . chr(0x80 | (($cp >> 6) & 63)) . chr(0x80 | ($cp & 63)),
			};
		}
		return $this->utf8($s);
	}

	private function name(string $s): void
	{
		$start = 'A-Z_a-z\x{C0}-\x{D6}\x{D8}-\x{F6}\x{F8}-\x{2FF}\x{370}-\x{37D}\x{37F}-\x{1FFF}\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}';
		if (!preg_match('/\A[' . $start . '][' . $start . '0-9.\x{2D}\x{B7}\x{300}-\x{36F}\x{203F}-\x{2040}]*\z/u', $s)) {
			$this->fail('Invalid XML name');
		}
	}

	private function escape(string $s, bool $attribute = false): string
	{
		$map = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', "\r" => '&#13;'];
		if ($attribute) {
			$map += ['"' => '&quot;', "\n" => '&#10;', "\t" => '&#9;'];
		}
		return strtr($s, $map);
	}

	private function add(string $table, mixed $value): void
	{
		if (count($this->tables[$table]) >= $this->maxTableEntries) {
			$this->fail('Maximum vocabulary size exceeded');
		}
		$this->tables[$table][] = $value;
	}

	private function lookup(string $table, int $index): mixed
	{
		return $this->tables[$table][$index] ?? $this->fail("Invalid $table vocabulary index $index");
	}

	private function emit(string $s): void
	{
		if (strlen($s) > $this->maxOutputBytes - strlen($this->output)) {
			$this->fail('Maximum output size exceeded');
		}
		$this->output .= $s;
	}

	private function byte(): int
	{
		if (!isset($this->data[$this->offset])) {
			$this->fail('Unexpected end of input');
		}
		return ord($this->data[$this->offset++]);
	}

	private function uint(int $bytes): int
	{
		$value = 0;
		for ($i = 0; $i < $bytes; ++$i) {
			$value = ($value << 8) | $this->byte();
		}
		return $value;
	}

	private function read(int $length): string
	{
		if ($length < 0 || $length > strlen($this->data) - $this->offset) {
			$this->fail('Unexpected end of input');
		}
		$s = substr($this->data, $this->offset, $length);
		$this->offset += $length;
		return $s;
	}

	private function fail(string $message): never
	{
		throw new DecodingException($message . ' at byte ' . $this->offset);
	}
}
