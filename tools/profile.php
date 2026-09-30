#!/usr/bin/env php
<?php declare(strict_types = 1);

// Usage: tools/profile.php <name> [--flags|--stdout]

$root = dirname(__DIR__);
$name = $argv[1] ?? '';
$mode = $argv[2] ?? null;

$fail = static function (string $message): void {
	fwrite(STDERR, $message . "\n");
	exit(1);
};

if ($mode !== null && $mode !== '--flags' && $mode !== '--stdout') {
	$fail(sprintf('Unknown mode "%s", expected --flags or --stdout.', $mode));
}

$profileFile = $root . '/tools/profiles/' . $name . '.json';
if (preg_match('~^[a-z0-9-]+$~', $name) !== 1 || !is_file($profileFile)) {
	$fail(sprintf('Unknown profile "%s".', $name));
}

$decode = static function (string $file) use ($fail): array {
	$content = file_get_contents($file);
	$data = $content === false ? null : json_decode($content, true);
	if (!is_array($data)) {
		$fail(sprintf('Cannot read "%s".', $file));
	}

	return $data;
};

$profile = $decode($profileFile);
$ignored = $profile['ignore-platform-req'] ?? [];
$updateFlags = $profile['update-flags'] ?? [];

// PROFILE_PHP_VERSION stands in for the running PHP in tests.
$phpCeiling = $profile['php-ceiling'] ?? null;
$runningPhp = getenv('PROFILE_PHP_VERSION');
$runningPhp = is_string($runningPhp) && $runningPhp !== '' ? $runningPhp : PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
if (is_string($phpCeiling) && version_compare($runningPhp, $phpCeiling, '>')) {
	$ignored[] = 'php';
}

if ($mode === '--flags') {
	echo implode(' ', array_merge(
		$updateFlags,
		array_map(static fn (string $req): string => '--ignore-platform-req=' . $req, $ignored),
	)), "\n";
	exit(0);
}

$composer = $decode($root . '/composer.json');
foreach (['require' => 'require-dev', 'require-dev' => 'require'] as $section => $otherSection) {
	foreach ($profile[$section] ?? [] as $package => $constraint) {
		$target = !isset($composer[$section][$package]) && isset($composer[$otherSection][$package])
			? $otherSection
			: $section;
		$composer[$target][$package] = $constraint;
	}
}

$composer['config']['vendor-dir'] = 'vendor-' . $name;

$json = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

if ($mode === '--stdout') {
	echo $json;
	exit(0);
}

if (file_put_contents($root . '/composer.' . $name . '.json', $json) === false) {
	$fail(sprintf('Cannot write "composer.%s.json".', $name));
}
