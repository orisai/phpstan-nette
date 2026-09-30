#!/usr/bin/env php
<?php declare(strict_types = 1);

// Writes composer.corpus-<profile>.json: the profile's composer file with every package the committed
// tests/Corpus/manifest.<profile>.json header names pinned to the recorded version.

$root = dirname(__DIR__, 2);
$profile = $argv[1] ?? '';
if (preg_match('~^[a-z0-9-]+$~', $profile) !== 1) {
	fwrite(STDERR, "Usage: tools/corpus/manifest-composer.php <profile>\n");
	exit(1);
}

$fail = static function (string $message): void {
	fwrite(STDERR, $message . "\n");
	exit(1);
};

$manifestFile = $root . '/tests/Corpus/manifest.' . $profile . '.json';
$manifest = is_file($manifestFile) ? json_decode((string) file_get_contents($manifestFile), true) : null;
if (!is_array($manifest)) {
	$fail(sprintf('Cannot read "%s".', $manifestFile));
}

if ($profile === 'default') {
	$json = file_get_contents($root . '/composer.json');
} else {
	$output = [];
	$command = implode(
		' ',
		array_map('escapeshellarg', [PHP_BINARY, $root . '/tools/profile.php', $profile, '--stdout']),
	);
	exec($command, $output, $exitCode);
	if ($exitCode !== 0) {
		$fail(sprintf('tools/profile.php %s failed.', $profile));
	}

	$json = implode("\n", $output);
}

$composer = is_string($json) ? json_decode($json, true) : null;
if (!is_array($composer)) {
	$fail(sprintf('Cannot read the composer file of profile "%s".', $profile));
}

foreach ($manifest as $package => $version) {
	if ($package === 'templates') {
		continue;
	}

	if (!is_string($version) || preg_match('~^v?(\d+\.\d+\.\d+)$~', $version, $m) !== 1) {
		$fail(sprintf(
			'The manifest records "%s" for %s, not a release version.',
			(string) json_encode($version),
			$package,
		));
	}

	$section = isset($composer['require-dev'][$package]) && !isset($composer['require'][$package])
		? 'require-dev'
		: 'require';
	$composer[$section][$package] = $m[1];
}

$target = $root . '/composer.corpus-' . $profile . '.json';
$encoded = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
if (file_put_contents($target, $encoded) === false) {
	$fail(sprintf('Cannot write "%s".', $target));
}

echo sprintf("Written %s\n", basename($target));
