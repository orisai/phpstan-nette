<?php declare(strict_types = 1);

$vendorDir = getenv('COMPOSER_VENDOR_DIR');
if (!is_string($vendorDir) || $vendorDir === '') {
	$script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
	if (
		$script !== false
		&& preg_match(
			'~^' . preg_quote(dirname(__DIR__), '~') . '/vendor-([^/]+)/~',
			$script,
			$m,
		) === 1
	) {
		fwrite(STDERR, sprintf(
			"%s runs from vendor-%s but COMPOSER_VENDOR_DIR is unset, so the tests would load vendor/; run make tests PROFILE=%s ARGS=<path>.\n",
			$script,
			$m[1],
			$m[1],
		));
		exit(1);
	}

	$vendorDir = 'vendor';
	// Test neon files include %env.COMPOSER_VENDOR_DIR%; $_SERVER/$_ENV carry it into Symfony Process spawns.
	putenv('COMPOSER_VENDOR_DIR=' . $vendorDir);
	$_SERVER['COMPOSER_VENDOR_DIR'] = $vendorDir;
	$_ENV['COMPOSER_VENDOR_DIR'] = $vendorDir;
}

return require __DIR__ . '/../' . $vendorDir . '/autoload.php';
