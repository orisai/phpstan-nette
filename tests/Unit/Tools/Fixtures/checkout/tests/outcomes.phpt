<?php declare(strict_types = 1);

use Latte\Loaders\StringLoader;
use Tester\Assert;

$latte = new Latte\Engine;

Assert::exception(
	fn() => $latte->compile('{foreach}'),
	Latte\CompileException::class,
	'Missing arguments in {foreach}',
);

Assert::error(
	fn() => $latte->renderToString('{=' . "1}\n"),
	E_USER_DEPRECATED,
);

$latte->setLoader(new StringLoader(array(
	'main' => '{include "inc"}',
	'inc' => '{sandbox "x"}',
)));

Assert::throws(function () use ($latte) {
	$latte->renderToString('main');
}, '\Latte\SecurityViolationException');

$template = '{$a}';
$template .= '{$b}';
$latte->compile($template);

foreach (['{$c}'] as $template) {
	$latte->compile($template);
}

Assert::same(['x' => '{$d}'], []);
