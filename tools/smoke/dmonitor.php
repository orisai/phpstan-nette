#!/usr/bin/env php
<?php declare(strict_types = 1);

use Nette\Neon\Entity;
use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use Symfony\Component\Process\Process;

// Analyses a real Latte 3 application (dmonitor, next to this library by default) with its own PHPStan
// and vendor, and fails on any internal error. Local only.

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$app = realpath($argv[1] ?? $root . '/../../../apps/fr/dmonitor');
if ($app === false || !is_file($app . '/vendor/composer/installed.php')) {
	fwrite(STDERR, sprintf("No installed application at %s.\n", $argv[1] ?? $root . '/../../../apps/fr/dmonitor'));
	exit(1);
}

$installed = require $app . '/vendor/composer/installed.php';
$versions = $installed['versions'];
// The library is not installed in the application; its analysis reads the application's versions.
$versions[ProjectInstalledVersions::PACKAGE] = ['version' => '1.0.0.0', 'pretty_version' => '1.0.0'];

$scratch = $root . '/var/smoke/dmonitor';
FileSystem::delete($scratch);
FileSystem::createDir($scratch);
FileSystem::write($scratch . '/phpstan.neon', Neon::encode([
	'includes' => [$root . '/extension.neon'],
	'parameters' => [
		'level' => 8,
		'paths' => [$app . '/src'],
		'tmpDir' => $scratch . '/tmp',
		'fileExtensions' => ['php', 'latte'],
		'orisai' => ['nette' => [
			'latte' => [
				'enabled' => true,
				'engineLoader' => __DIR__ . '/dmonitor-engine.php',
			],
		]],
	],
	'services' => [
		'orisai.nette.installedVersions' => [
			'factory' => new Entity(
				ProjectInstalledVersions::class . '::fromRawData',
				[[['root' => [], 'versions' => $versions]]],
			),
			'autowired' => false,
		],
	],
], true));

$process = new Process(
	[
		PHP_BINARY,
		$app . '/vendor/bin/phpstan',
		'analyse',
		'-c',
		$scratch . '/phpstan.neon',
		'--error-format=json',
		'--no-progress',
		'--memory-limit=2G',
		// Before the container is built: the extension's services are this library's classes.
		'--autoload-file=' . __DIR__ . '/dmonitor-bootstrap.php',
	],
	$app,
	['SMOKE_APP_DIR' => $app, 'XDEBUG_MODE' => 'off', 'COMPOSER' => false, 'COMPOSER_VENDOR_DIR' => false],
	null,
	null,
);
$started = microtime(true);
$process->run();
$elapsed = microtime(true) - $started;

try {
	$result = Json::decode($process->getOutput(), Json::FORCE_ARRAY);
} catch (Throwable $e) {
	fwrite(STDERR, "PHPStan crashed:\n" . $process->getErrorOutput() . $process->getOutput() . "\n");
	exit(1);
}

$internal = [];
foreach ($result['errors'] as $error) {
	$internal[] = $error;
}

$findings = 0;
$templateFindings = 0;
$identifiers = [];
foreach ($result['files'] as $file => $fileResult) {
	foreach ($fileResult['messages'] as $message) {
		$findings++;
		$identifier = $message['identifier'] ?? '(none)';
		if (in_array($identifier, ['orisaiNette.latte.internalError', 'phpstan.parse'], true)) {
			$internal[] = sprintf('%s:%d %s: %s', $file, $message['line'] ?? 0, $identifier, $message['message']);
		}

		if (substr($file, -6) === '.latte') {
			$templateFindings++;
			$identifiers[$identifier] = ($identifiers[$identifier] ?? 0) + 1;
		}
	}
}

arsort($identifiers);
printf(
	"%s: %d findings, %d of them in templates, %.1f s, PHPStan exit code %d\n",
	$app,
	$findings,
	$templateFindings,
	$elapsed,
	(int) $process->getExitCode(),
);
foreach ($identifiers as $identifier => $count) {
	printf("  %5d %s\n", $count, $identifier);
}

if ($internal !== []) {
	fwrite(STDERR, sprintf("%d internal errors:\n%s\n", count($internal), implode("\n", $internal)));
	exit(1);
}

echo "No internal errors.\n";
