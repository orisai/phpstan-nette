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

if ($mode === '--flags') {
	echo implode(' ', array_map(static fn (string $req): string => '--ignore-platform-req=' . $req, $ignored)), "\n";
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
