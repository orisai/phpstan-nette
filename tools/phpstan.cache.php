<?php declare(strict_types = 1);

$vendorDir = getenv('COMPOSER_VENDOR_DIR');

return [
	'parameters' => [
		'resultCachePath' => dirname(__DIR__) . '/var/tools/PHPStan/resultCache.'
			. (is_string($vendorDir) && $vendorDir !== '' ? $vendorDir : 'vendor') . '.php',
	],
];
