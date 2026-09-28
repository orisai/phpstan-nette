<?php declare(strict_types = 1);

// Deterministic generator for the committed Fleet/ fixture classes PhpRenderWalkScaleTest runs
// over: pure data in, filename => content map out - no randomness, no timestamps, no environment
// reads. Run directly (php fleet-generator.php) to (re)write Fleet/; required from a test it
// only returns the map, which the drift-guard test compares byte-for-byte against Fleet/.

$fleetFile = static function (string $declaration, array $uses, string $body, string $phpdoc = ''): string {
	$useBlock = '';
	foreach ($uses as $use) {
		$useBlock .= "use {$use};\n";
	}

	if ($useBlock !== '') {
		$useBlock .= "\n";
	}

	return "<?php declare(strict_types = 1);\n"
		. "\n"
		. "namespace Tests\\OriPhpstan\\Nette\\Unit\\Latte\\Bridge\\Fixtures\\Fleet;\n"
		. "\n"
		. $useBlock
		. $phpdoc
		. "final class {$declaration}\n"
		. "{\n"
		. $body
		. "}\n";
};

$templateProperty = "\n\t/** @var Template|stdClass */\n\tpublic \$template;\n";

$files = [];

foreach ([1, 2, 3, 4, 5] as $n) {
	$nn = sprintf('%02d', $n);

	$files["FleetTemplate{$nn}.php"] = $fleetFile(
		"FleetTemplate{$nn} extends Template",
		['Nette\Bridges\ApplicationLatte\Template'],
		"\n",
	);

	$files["FleetLiteral{$nn}Presenter.php"] = $fleetFile(
		"FleetLiteral{$nn}Presenter",
		['Nette\Application\UI\Template', 'stdClass'],
		$templateProperty
		. "\n\tpublic function actionDefault(): void\n"
		. "\t{\n"
		. "\t\t\$this->template->title{$n} = 'fleet-{$nn}';\n"
		. "\t\t\$this->template->count{$n} = " . ($n * 7) . ";\n"
		. "\t\t\$this->template->ratio{$n} = {$n}.5;\n"
		. "\t\t\$this->template->active{$n} = " . ($n % 2 === 1 ? 'true' : 'false') . ";\n"
		. "\t}\n\n",
	);

	$files["FleetConditional{$nn}Presenter.php"] = $fleetFile(
		"FleetConditional{$nn}Presenter",
		['Nette\Application\UI\Template', 'stdClass'],
		$templateProperty
		. "\n\t/** @var bool */\n"
		. "\tpublic \$enabled = false;\n"
		. "\n\tpublic function actionDefault(): void\n"
		. "\t{\n"
		. "\t\t\$this->template->always{$n} = 'base-{$nn}';\n"
		. "\n"
		. "\t\tif (\$this->enabled) {\n"
		. "\t\t\t\$this->template->sometimes{$n} = 'branch-{$nn}';\n"
		. "\t\t}\n"
		. "\t}\n\n",
	);

	$files["FleetHelper{$nn}Presenter.php"] = $fleetFile(
		"FleetHelper{$nn}Presenter",
		['Nette\Application\UI\Template', 'stdClass'],
		$templateProperty
		. "\n\tpublic function actionDefault(): void\n"
		. "\t{\n"
		. "\t\t\$this->applyDefaults();\n"
		. "\t}\n"
		. "\n\tprivate function applyDefaults(): void\n"
		. "\t{\n"
		. "\t\t\$this->template->fromHelper{$n} = 'helper-{$nn}';\n"
		. "\t\t\$this->applyExtras();\n"
		. "\t}\n"
		. "\n\tprivate function applyExtras(): void\n"
		. "\t{\n"
		. "\t\t\$this->template->fromExtras{$n} = " . (10 + $n) . ";\n"
		. "\t}\n\n",
	);

	$files["FleetSetFileLiteral{$nn}Presenter.php"] = $fleetFile(
		"FleetSetFileLiteral{$nn}Presenter",
		['Nette\Application\UI\Template', 'stdClass'],
		$templateProperty
		. "\n\tpublic function actionDefault(): void\n"
		. "\t{\n"
		. "\t\t\$this->template->setFile(__DIR__ . '/fleet-{$nn}.latte');\n"
		. "\t}\n\n",
	);

	$files["FleetSetFileConvention{$nn}Presenter.php"] = $fleetFile(
		"FleetSetFileConvention{$nn}Presenter",
		['Nette\Application\UI\Template', 'stdClass'],
		$templateProperty
		. "\n\tpublic function actionDefault(): void\n"
		. "\t{\n"
		. "\t\t\$this->template->setFile(\$this->templateFilePath());\n"
		. "\t}\n"
		. "\n\tprivate function templateFilePath(): string\n"
		. "\t{\n"
		. "\t\treturn __DIR__ . '/fleet-convention-{$nn}.latte';\n"
		. "\t}\n\n",
	);

	$files["FleetSetFileOpaque{$nn}Presenter.php"] = $fleetFile(
		"FleetSetFileOpaque{$nn}Presenter",
		['Nette\Application\UI\Template', 'stdClass'],
		$templateProperty
		. "\n\tpublic function actionDefault(): void\n"
		. "\t{\n"
		. "\t\t\$dynamic = \$this->buildDynamicPath();\n"
		. "\t\t\$this->template->setFile(\$dynamic);\n"
		. "\t}\n"
		. "\n\tprivate function buildDynamicPath(): string\n"
		. "\t{\n"
		. "\t\treturn 'fleet-dynamic-{$nn}.latte';\n"
		. "\t}\n\n",
	);

	$files["FleetDiscovery{$nn}Presenter.php"] = $fleetFile(
		"FleetDiscovery{$nn}Presenter extends Presenter",
		['Nette\Application\UI\Presenter'],
		"\n\tpublic function actionDefault(): void\n"
		. "\t{\n"
		. "\t\t\$this->template->headline{$n} = 'discovery-{$nn}';\n"
		. "\t}\n"
		. "\n\tpublic function renderExtra(): void\n"
		. "\t{\n"
		. "\t}\n\n",
	);

	$files["FleetFallback{$nn}Control.php"] = $fleetFile(
		"FleetFallback{$nn}Control",
		['Nette\Application\UI\Template', 'stdClass', 'function is_file'],
		$templateProperty
		. "\n\tpublic function render(): void\n"
		. "\t{\n"
		. "\t\t\$this->template->setFile(\$this->getTemplateFilePath());\n"
		. "\t}\n"
		. "\n\tprivate function getTemplateFilePath(): string\n"
		. "\t{\n"
		. "\t\t\$file = __DIR__ . '/templates/fleetFallback{$nn}Control.latte';\n"
		. "\t\tif (is_file(\$file)) {\n"
		. "\t\t\treturn \$file;\n"
		. "\t\t}\n"
		. "\n"
		. "\t\t\$tmpFile = __DIR__ . '/templates/@fleetShared{$nn}.latte';\n"
		. "\t\tif (is_file(\$tmpFile)) {\n"
		. "\t\t\treturn \$tmpFile;\n"
		. "\t\t}\n"
		. "\n"
		. "\t\treturn \$file;\n"
		. "\t}\n\n",
	);

	$files["FleetConvention{$nn}Control.php"] = $fleetFile(
		"FleetConvention{$nn}Control",
		['Nette\Application\UI\Template', 'stdClass'],
		$templateProperty
		. "\n\tprotected function getTemplateClass(): string\n"
		. "\t{\n"
		. "\t\treturn FleetTemplate{$nn}::class;\n"
		. "\t}\n\n",
	);

	$files["FleetPhpdoc{$nn}Presenter.php"] = $fleetFile(
		"FleetPhpdoc{$nn}Presenter",
		[],
		"\n\tpublic function actionDefault(): void\n"
		. "\t{\n"
		. "\t\t\$this->template->headline{$n} = 'phpdoc-{$nn}';\n"
		. "\t}\n\n",
		"/**\n * @property-read FleetTemplate{$nn} \$template\n */\n",
	);

	if ($n % 3 === 1) {
		$renderBody = "\n\tpublic function actionDefault(): void\n"
			. "\t{\n"
			. "\t\t\$this->template->render(__DIR__ . '/fleet-render-{$nn}.latte');\n"
			. "\t}\n\n";
	} elseif ($n % 3 === 2) {
		$renderBody = "\n\tpublic function actionDefault(): void\n"
			. "\t{\n"
			. "\t\t\$this->template->render();\n"
			. "\t}\n\n";
	} else {
		$renderBody = "\n\tpublic function actionDefault(): void\n"
			. "\t{\n"
			. "\t\t\$this->template->render(\$this->pickPath());\n"
			. "\t}\n"
			. "\n\tprivate function pickPath(): string\n"
			. "\t{\n"
			. "\t\treturn 'fleet-picked-{$nn}.latte';\n"
			. "\t}\n\n";
	}

	$files["FleetRender{$nn}Presenter.php"] = $fleetFile(
		"FleetRender{$nn}Presenter",
		['Nette\Application\UI\Template', 'stdClass'],
		$templateProperty . $renderBody,
	);

	$files["FleetGetTemplate{$nn}Control.php"] = $fleetFile(
		"FleetGetTemplate{$nn}Control",
		['Latte\Engine'],
		"\n\tpublic function getTemplate(): FleetTemplate{$nn}\n"
		. "\t{\n"
		. "\t\treturn new FleetTemplate{$nn}(new Engine());\n"
		. "\t}\n"
		. "\n\tpublic function render(): void\n"
		. "\t{\n"
		. "\t\t\$this->getTemplate()->render();\n"
		. "\t}\n"
		. "\n\tpublic function renderCaption(): void\n"
		. "\t{\n"
		. "\t\t\$tpl = \$this->getTemplate();\n"
		. "\t\t\$tpl->caption{$n} = 'origin-{$nn}';\n"
		. "\t\t\$tpl->render();\n"
		. "\t}\n\n",
	);

	$files["FleetPlain{$nn}Service.php"] = $fleetFile(
		"FleetPlain{$nn}Service",
		[],
		"\n\tpublic function compute(): int\n"
		. "\t{\n"
		. "\t\treturn " . (40 + $n) . ";\n"
		. "\t}\n\n",
	);
}

ksort($files, SORT_STRING);

if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__) {
	if (!is_dir(__DIR__ . '/Fleet')) {
		mkdir(__DIR__ . '/Fleet');
	}

	foreach ($files as $name => $content) {
		file_put_contents(__DIR__ . '/Fleet/' . $name, $content);
	}

	echo count($files) . " fleet files written\n";
}

return $files;
