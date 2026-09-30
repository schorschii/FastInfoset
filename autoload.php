<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'FastInfoset\\')) {
		$file = __DIR__ . '/src/' . substr($class, 12) . '.php';
		if (is_file($file)) {
			require $file;
		}
	}
});
