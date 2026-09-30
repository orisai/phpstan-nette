#!/usr/bin/env php
<?php declare(strict_types = 1);

use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use OriPhpstan\Nette\Tools\Corpus\Harvester;
use OriPhpstan\Nette\Tools\Corpus\HarvestSource;
use OriPhpstan\Nette\Tools\Corpus\PhptTemplateExtractor;
use OriPhpstan\Nette\Tools\Corpus\UpstreamPackage;

$root = dirname(__DIR__, 2);
$profile = $argv[1] ?? '';
if (preg_match('~^[a-z0-9-]+$~', $profile) !== 1) {
	fwrite(STDERR, "Usage: tools/corpus/harvest.php <profile>\n");
	exit(1);
}

$vendorDir = $profile === 'default' ? 'vendor' : 'vendor-' . $profile;
$environmentVendorDir = getenv('COMPOSER_VENDOR_DIR');
if (is_string($environmentVendorDir) && $environmentVendorDir !== '' && $environmentVendorDir !== $vendorDir) {
	fwrite(STDERR, sprintf(
		"Profile \"%s\" uses %s, but COMPOSER_VENDOR_DIR is %s.\n",
		$profile,
		$vendorDir,
		$environmentVendorDir,
	));
	exit(1);
}

if (!is_file($root . '/' . $vendorDir . '/autoload.php')) {
	fwrite(STDERR, sprintf("%s/autoload.php does not exist, install the profile first.\n", $vendorDir));
	exit(1);
}

require $root . '/' . $vendorDir . '/autoload.php';

$installedVersions = ProjectInstalledVersions::get();
$latteVersion = $installedVersions->getVersion('latte/latte');
if ($latteVersion === null) {
	fwrite(STDERR, "latte/latte is not installed.\n");
	exit(1);
}

$corpusDirectory = $root . '/var/corpus';
$sources = [];
try {
	foreach (UpstreamPackage::all() as $package) {
		$prettyVersion = $installedVersions->getPrettyVersion($package->package);
		if ($prettyVersion === null) {
			throw new RuntimeException(sprintf('%s is not installed.', $package->package));
		}

		$checkout = $package->checkout($corpusDirectory, $prettyVersion);
		$sources[] = new HarvestSource(
			$package->package,
			$prettyVersion,
			$checkout,
			$package->testDirectories($checkout, (int) $latteVersion),
		);
	}
} catch (RuntimeException $exception) {
	fwrite(STDERR, $exception->getMessage() . "\n");
	exit(1);
}

$outputDirectory = $corpusDirectory . '/templates/' . $profile;
$counts = (new Harvester(new PhptTemplateExtractor()))->harvest($outputDirectory, $sources);
foreach ($sources as $source) {
	echo sprintf(
		"%s %s (%s): %d inline, %d files\n",
		$source->package,
		$source->version,
		implode(', ', $source->testDirectories),
		$counts[$source->package]['inline'],
		$counts[$source->package]['files'],
	);
}

echo sprintf("Written to var/corpus/templates/%s\n", $profile);
