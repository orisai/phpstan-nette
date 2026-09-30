<?php declare(strict_types = 1);

// The analysed application's own install (its PHPStan, Latte and Nette) plus this library's sources.
require getenv('SMOKE_APP_DIR') . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
	$prefix = 'OriPhpstan\\Nette\\';
	if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
		return;
	}

	$file = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) {
		require $file;
	}
});
