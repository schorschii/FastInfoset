<?php

const DECL = '<?xml version="1.0" encoding="UTF-8"?>';
const HEADER = "\xE0\0\0\1\0";
const ROOT = "\x3C\0r";

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
	throw new ErrorException($message, 0, $severity, $file, $line);
});

$count = 0;
function same(mixed $expected, mixed $actual, string $label): void {
	global $count;
	++$count;
	if ($expected !== $actual) {
		throw new RuntimeException("Failed: $label\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
	}
}
