<?php declare(strict_types = 1);

$vendorDir = getenv('COMPOSER_VENDOR_DIR');
$tmpDir = dirname(__DIR__) . '/var/tools/PHPStan/'
	. basename(is_string($vendorDir) && $vendorDir !== '' ? $vendorDir : 'vendor');

return [
	'parameters' => [
		'tmpDir' => $tmpDir,
		'resultCachePath' => $tmpDir . '/resultCache.php',
	],
];
