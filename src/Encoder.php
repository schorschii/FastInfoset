<?php

namespace FastInfoset;

/** Encodes a UTF-8 XML 1.0 document as Fast Infoset without XML extensions. */
final class Encoder
{
	private const XML_NS = 'http://www.w3.org/XML/1998/namespace';
	private const XMLNS_NS = 'http://www.w3.org/2000/xmlns/';
	private const NAME_START = 'A-Z_a-z\x{C0}-\x{D6}\x{D8}-\x{F6}\x{F8}-\x{2FF}\x{370}-\x{37D}\x{37F}-\x{1FFF}\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}';
	private string $xml = '';
	private int $offset = 0;
	private string $output = '';
	private array $tables = [];

	public function __construct(
		private readonly int $maxDepth = 256,
		private readonly int $maxOutputBytes = 67108864,
		private readonly int $maxTableEntries = 1048576,
		private readonly int $maxIndexedStringBytes = 64,
	) {
		if ($maxDepth < 1 || $maxOutputBytes < 1 || $maxTableEntries < 1 || $maxIndexedStringBytes < 0) {
			throw new \InvalidArgumentException('Limits must be positive; maxIndexedStringBytes may be zero');
		}
	}

	public function encode(string $xml): string
	{
		if (PHP_INT_SIZE < 8) {
			throw new \LogicException('A 64-bit PHP build is required');
		}
		$this->offset = 0;
		$this->output = '';
		$this->tables = array_fill_keys(['prefix', 'namespace', 'local', 'element', 'attribute',
			'value', 'chunk', 'other', 'ncname'], []);
		$this->tables['prefix']['xml'] = 0;
		$this->tables['namespace'][self::XML_NS] = 0;
		// XML end-of-line normalization happens before parsing references.
		$this->xml = str_replace(["\r\n", "\r"], "\n", $xml);
		try {
			if (!preg_match('/\A[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*\z/u', $this->xml)) {
				$this->fail('Invalid UTF-8 or XML character');
			}
			if (str_starts_with($this->xml, "\xEF\xBB\xBF")) {
				$this->offset = 3;
			}
			$standalone = $this->declaration();
			$this->emit("\xE0\x00\x00\x01" . ($standalone === null ? "\0" : "\2" . chr((int) $standalone)));
			$stack = [];
			$scope = ['xml' => self::XML_NS, '' => ''];
			$rootSeen = false;
			$length = strlen($this->xml);
			while ($this->offset < $length) {
				if ($this->take('<!--')) {
					$text = $this->until('-->');
					if (str_contains($text, '--') || str_ends_with($text, '-')) {
						$this->fail('Invalid XML comment');
					}
					$this->emit("\xE2");
					$this->value('other', $text);
				} elseif ($this->take('<?')) {
					$target = $this->readName(false);
					if (strcasecmp($target, 'xml') === 0) {
						$this->fail('Reserved processing instruction target or misplaced declaration');
					}
					if ($this->take('?>')) {
						$text = '';
					} else {
						if (!$this->space()) {
							$this->fail('Expected whitespace after processing instruction target');
						}
						$text = $this->until('?>');
					}
					$this->emit("\xE1");
					$this->identifying('ncname', $target);
					$this->value('other', $text);
				} elseif ($this->take('<![CDATA[')) {
					if (!$stack) {
						$this->fail('CDATA outside root element');
					}
					$this->value('chunk', $this->until(']]>'));
				} elseif ($this->take('</')) {
					$name = $this->readName();
					$this->space();
					$this->expect('>');
					$open = array_pop($stack);
					if ($open === null || $open[0] !== $name) {
						$this->fail('Mismatched closing element');
					}
					$scope = $open[1];
					$this->emit("\xF0");
				} elseif ($this->take('<!')) {
					$this->fail('DTDs and other markup declarations are unsupported');
				} elseif ($this->take('<')) {
					if (!$stack && $rootSeen) {
						$this->fail('Multiple root elements');
					}
					if (count($stack) >= $this->maxDepth) {
						$this->fail('Maximum element depth exceeded');
					}
					$rootSeen = true;
					[$name, $attributes, $empty] = $this->startTag();
					$parentScope = $scope;
					$this->element($name, $attributes, $scope);
					if ($empty) {
						$scope = $parentScope;
						$this->emit("\xF0");
					} else {
						$stack[] = [$name, $parentScope];
					}
				} else {
					$end = strpos($this->xml, '<', $this->offset);
					$end = $end === false ? $length : $end;
					$text = substr($this->xml, $this->offset, $end - $this->offset);
					$this->offset = $end;
					if (!$stack) {
						if (strspn($text, " \t\n") !== strlen($text)) {
							$this->fail('Character content outside root element');
						}
					} else {
						if (str_contains($text, ']]>')) {
							$this->fail('CDATA terminator in character content');
						}
						$this->value('chunk', $this->references($text));
					}
				}
			}
			if (!$rootSeen || $stack) {
				$this->fail('Missing root element or unclosed element');
			}
			$this->emit("\xF0");
			return $this->output;
		} finally {
			$this->xml = '';
			$this->output = '';
			$this->tables = [];
		}
	}

	private function declaration(): ?bool
	{
		if (!preg_match('/\G<\?xml(?=[ \t\n])/A', $this->xml, $m, 0, $this->offset)) {
			return null;
		}
		$this->offset += 5;
		$text = $this->until('?>');
		$s = '[ \t\n]';
		$eq = $s . '*=' . $s . '*';
		$pattern = '/\A' . $s . '+version' . $eq . '([\'"])1\.0\1'
			. '(?:' . $s . '+encoding' . $eq . '([\'"])([A-Za-z][A-Za-z0-9._-]*)\2)?'
			. '(?:' . $s . '+standalone' . $eq . '([\'"])(yes|no)\4)?' . $s . '*\z/';
		if (!preg_match($pattern, $text, $m)) {
			$this->fail('Invalid XML declaration; only XML 1.0 is supported');
		}
		if (isset($m[3]) && $m[3] !== '' && strcasecmp($m[3], 'UTF-8') !== 0) {
			$this->fail('Only UTF-8 input is supported');
		}
		return isset($m[5]) ? $m[5] === 'yes' : null;
	}

	/** @return array{string, array<string, string>, bool} */
	private function startTag(): array
	{
		$name = $this->readName();
		$attributes = [];
		while (true) {
			$space = $this->space();
			if ($this->take('/>')) {
				return [$name, $attributes, true];
			}
			if ($this->take('>')) {
				return [$name, $attributes, false];
			}
			if (!$space) {
				$this->fail('Expected whitespace before attribute');
			}
			$attribute = $this->readName();
			if (array_key_exists($attribute, $attributes)) {
				$this->fail('Duplicate attribute');
			}
			$this->space();
			$this->expect('=');
			$this->space();
			$quote = $this->xml[$this->offset] ?? '';
			if ($quote !== '"' && $quote !== "'") {
				$this->fail('Expected quoted attribute value');
			}
			++$this->offset;
			$value = $this->until($quote);
			if (str_contains($value, '<')) {
				$this->fail('Unescaped less-than sign in attribute');
			}
			// Literal whitespace is normalized; character references are not.
			$attributes[$attribute] = $this->references(str_replace(["\t", "\n"], ' ', $value));
		}
	}

	private function element(string $name, array $attributes, array &$scope): void
	{
		$declarations = [];
		foreach ($attributes as $attribute => $value) {
			if ($attribute !== 'xmlns' && !str_starts_with($attribute, 'xmlns:')) {
				continue;
			}
			$prefix = $attribute === 'xmlns' ? '' : substr($attribute, 6);
			if ($prefix === 'xmlns' || ($prefix === 'xml') !== ($value === self::XML_NS)
				|| $value === self::XMLNS_NS || ($prefix !== '' && $value === '')) {
				$this->fail('Invalid namespace declaration');
			}
			$scope[$prefix] = $value;
			$declarations[$prefix] = $value;
			unset($attributes[$attribute]);
		}
		$flag = $attributes ? 0x40 : 0;
		if ($declarations) {
			$this->emit(chr(0x38 | $flag));
			foreach ($declarations as $prefix => $uri) {
				$this->emit(chr(0xCC | ($prefix !== '' ? 2 : 0) | ($uri !== '' ? 1 : 0)));
				if ($prefix !== '') {
					$this->identifying('prefix', $prefix);
				}
				if ($uri !== '') {
					$this->identifying('namespace', $uri);
				}
			}
			$this->emit("\xF0");
			$flag = 0;
		}
		$this->qualified($this->resolve($name, $scope, false), false, $flag);
		$seen = [];
		foreach ($attributes as $attribute => $value) {
			$q = $this->resolve($attribute, $scope, true);
			$key = $q[1] . "\0" . $q[2];
			if (isset($seen[$key])) {
				$this->fail('Duplicate expanded attribute name');
			}
			$seen[$key] = true;
			$this->qualified($q, true);
			$this->value('value', $value);
		}
		if ($attributes) {
			$this->emit("\xF0");
		}
	}

	/** @return array{string, string, string} */
	private function resolve(string $name, array $scope, bool $attribute): array
	{
		$parts = explode(':', $name, 2);
		if (count($parts) === 1) {
			return ['', $attribute ? '' : $scope[''], $name];
		}
		[$prefix, $local] = $parts;
		if ($prefix === 'xmlns' || !isset($scope[$prefix]) || $scope[$prefix] === '') {
			$this->fail('Name uses an unbound or reserved namespace prefix');
		}
		return [$prefix, $scope[$prefix], $local];
	}

	private function qualified(array $q, bool $attribute, int $flag = 0): void
	{
		$table = $attribute ? 'attribute' : 'element';
		$key = implode("\0", $q);
		if (isset($this->tables[$table][$key])) {
			$this->emit($this->index($this->tables[$table][$key], $attribute ? 2 : 3, $flag));
			return;
		}
		[$prefix, $uri, $local] = $q;
		$this->add($table, $key);
		$this->emit(chr(($attribute ? 0x78 : 0x3C) | $flag | ($prefix !== '' ? 2 : 0) | ($uri !== '' ? 1 : 0)));
		// Namespace declarations (or the built-in xml binding) populated these tables.
		if ($prefix !== '') {
			$this->emit($this->index($this->tables['prefix'][$prefix], 2, 0x80));
		}
		if ($uri !== '') {
			$this->emit($this->index($this->tables['namespace'][$uri], 2, 0x80));
		}
		$this->identifying('local', $local);
	}

	private function identifying(string $table, string $value): void
	{
		if (isset($this->tables[$table][$value])) {
			$this->emit($this->index($this->tables[$table][$value], 2, 0x80));
		} else {
			$this->add($table, $value);
			$this->octets($value, 2, 0);
		}
	}

	private function value(string $table, string $value): void
	{
		$chunk = $table === 'chunk';
		if ($value === '') {
			if (!$chunk) {
				$this->emit("\xFF");
			}
			return;
		}
		if (isset($this->tables[$table][$value])) {
			$this->emit($this->index($this->tables[$table][$value], $chunk ? 4 : 2, $chunk ? 0xA0 : 0x80));
			return;
		}
		$add = strlen($value) <= $this->maxIndexedStringBytes
			&& count($this->tables[$table]) < min($this->maxTableEntries, 1048576);
		if ($add) {
			$this->add($table, $value);
		}
		$this->octets($value, $chunk ? 7 : 5, ($chunk ? 0x80 : 0) | ($add ? ($chunk ? 0x10 : 0x40) : 0));
	}

	/** Encodes a zero-based vocabulary index using X.891 C.25/C.27/C.28. */
	private function index(int $index, int $bit, int $flag): string
	{
		[$small, $medium, $large, $mediumFlag, $largeFlag] = match ($bit) {
			2 => [64, 8256, 1048576, 0x40, 0x60],
			3 => [32, 2080, 526368, 0x20, 0x28],
			4 => [16, 1040, 263184, 0x10, 0x14],
		};
		if ($index < $small) {
			return chr($flag | $index);
		}
		if ($index < $medium) {
			$n = $index - $small;
			return chr($flag | $mediumFlag | ($n >> 8)) . chr($n & 255);
		}
		if ($index < $large) {
			$n = $index - $medium;
			return chr($flag | $largeFlag | ($n >> 16)) . pack('n', $n & 65535);
		}
		$n = $index - $large;
		return chr($flag | ($bit === 3 ? 0x30 : 0x18)) . substr(pack('N', $n), 1);
	}

	private function octets(string $value, int $bit, int $flag): void
	{
		$length = strlen($value);
		[$small, $largeFlag] = match ($bit) {2 => [64, 0x60], 5 => [8, 0x0C], 7 => [2, 3]};
		if ($length <= $small) {
			$this->emit(chr($flag | ($length - 1)));
		} elseif ($length <= $small + 256) {
			$this->emit(chr($flag | $small) . chr($length - $small - 1));
		} else {
			if ($length > 4294967296) {
				$this->fail('String exceeds Fast Infoset length limit');
			}
			$this->emit(chr($flag | $largeFlag) . pack('N', $length - $small - 257));
		}
		$this->emit($value);
	}

	private function add(string $table, string $value): void
	{
		$count = count($this->tables[$table]);
		if ($count >= min($this->maxTableEntries, 1048576)) {
			$this->fail('Maximum vocabulary size exceeded');
		}
		$this->tables[$table][$value] = $count;
	}

	private function references(string $text): string
	{
		if (!str_contains($text, '&')) {
			return $text;
		}
		$result = preg_replace_callback('/&([^;&<]*+);|&/', function (array $m): string {
			$reference = $m[1] ?? '';
			$predefined = ['lt' => '<', 'gt' => '>', 'amp' => '&', 'apos' => "'", 'quot' => '"'];
			if (isset($predefined[$reference])) {
				return $predefined[$reference];
			}
			if (preg_match('/\A#([0-9]+)\z/', $reference, $digits)) {
				$number = ltrim($digits[1], '0');
				$cp = strlen($number) <= 7 ? (int) $number : 0;
			} elseif (preg_match('/\A#x([0-9a-fA-F]+)\z/', $reference, $digits)) {
				$number = ltrim($digits[1], '0');
				$cp = strlen($number) <= 6 ? (int) hexdec($number) : 0;
			} else {
				$this->fail('Unknown entity or malformed character reference');
			}
			if (!in_array($cp, [9, 10, 13], true)
				&& !($cp >= 0x20 && $cp <= 0xD7FF)
				&& !($cp >= 0xE000 && $cp <= 0xFFFD)
				&& !($cp >= 0x10000 && $cp <= 0x10FFFF)) {
				$this->fail('Invalid XML character reference');
			}
			return match (true) {
				$cp < 0x80 => chr($cp),
				$cp < 0x800 => chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 63)),
				$cp < 0x10000 => chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 63)) . chr(0x80 | ($cp & 63)),
				default => chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 63)) . chr(0x80 | (($cp >> 6) & 63)) . chr(0x80 | ($cp & 63)),
			};
		}, $text);
		return $result ?? $this->fail('Unable to parse character references');
	}

	private function readName(bool $qualified = true): string
	{
		if (!preg_match('/\G[^ \t\n\r\/=<>?\'"!\[\]]+/A', $this->xml, $m, 0, $this->offset)) {
			$this->fail('Expected XML name');
		}
		$name = $m[0];
		$parts = $qualified ? explode(':', $name) : [$name];
		if (count($parts) > 2) {
			$this->fail('Invalid qualified XML name');
		}
		$pattern = '/\A[' . self::NAME_START . '][' . self::NAME_START . '0-9.\x{2D}\x{B7}\x{300}-\x{36F}\x{203F}-\x{2040}]*\z/u';
		foreach ($parts as $part) {
			if (!preg_match($pattern, $part)) {
				$this->fail('Invalid XML name');
			}
		}
		$this->offset += strlen($name);
		return $name;
	}

	private function space(): bool
	{
		$length = strspn($this->xml, " \t\n", $this->offset);
		$this->offset += $length;
		return $length > 0;
	}

	private function take(string $token): bool
	{
		if (substr_compare($this->xml, $token, $this->offset, strlen($token)) !== 0) {
			return false;
		}
		$this->offset += strlen($token);
		return true;
	}

	private function expect(string $token): void
	{
		if (!$this->take($token)) {
			$this->fail('Expected ' . $token);
		}
	}

	private function until(string $token): string
	{
		$end = strpos($this->xml, $token, $this->offset);
		if ($end === false) {
			$this->fail('Unterminated XML construct; expected ' . $token);
		}
		$value = substr($this->xml, $this->offset, $end - $this->offset);
		$this->offset = $end + strlen($token);
		return $value;
	}

	private function emit(string $bytes): void
	{
		if (strlen($bytes) > $this->maxOutputBytes - strlen($this->output)) {
			$this->fail('Maximum output size exceeded');
		}
		$this->output .= $bytes;
	}

	private function fail(string $message): never
	{
		throw new EncodingException($message . ' at byte ' . $this->offset);
	}
}
