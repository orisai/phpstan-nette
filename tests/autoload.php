<?php declare(strict_types = 1);

$vendorDir = getenv('COMPOSER_VENDOR_DIR');
if (!is_string($vendorDir) || $vendorDir === '') {
	$vendorDir = 'vendor';
	// Test neon files include %env.COMPOSER_VENDOR_DIR%; $_SERVER/$_ENV carry it into Symfony Process spawns.
	putenv('COMPOSER_VENDOR_DIR=' . $vendorDir);
	$_SERVER['COMPOSER_VENDOR_DIR'] = $vendorDir;
	$_ENV['COMPOSER_VENDOR_DIR'] = $vendorDir;
}

return require __DIR__ . '/../' . $vendorDir . '/autoload.php';
