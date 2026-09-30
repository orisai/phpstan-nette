<?php declare(strict_types = 1);

use Tester\Assert;

function testTemplate(array $templates): void
{
	$latte = new Latte\Engine;
	$latte->setLoader(new Latte\Loaders\StringLoader($templates));
	Assert::match('', $latte->renderToString('main'));
}

testTemplate([
	'main' => '{embed "embed"}{/embed}',
	'embed' => 'plain',
]);
