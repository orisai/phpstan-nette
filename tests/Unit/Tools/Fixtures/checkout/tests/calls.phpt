<?php declare(strict_types = 1);

use Latte\Loaders\StringLoader;
use Tester\Assert;

$latte = new Latte\Engine;

Assert::match('<p>1</p>', $latte->compile('<p>{=1}</p>'));

Assert::match('x', $latte->renderToString(<<<XX
	<ul n:if="true">
		<li>\x41</li>
	</ul>
	XX));

$template = <<<'XX'
	{foreach [1, 2] as $item}{$item}{/foreach}
	XX;

$latte->render($template);
$latte->renderToString($template);

Assert::exception(
	fn() => $latte->compile('{if}'),
	Latte\CompileException::class,
);

$latte->createTemplate(<<<'XX'
	{block content}
	{$title}
	{/block}
	XX);

$latte->setLoader(new StringLoader([
	'a.latte' => '{include "b.latte"}',
	'b.latte' => <<<'XX'
		{$b}
		XX,
]));

$latte->renderToString('a.latte');
$latte->compile(__DIR__ . '/templates/page.latte');
$latte->renderToString("{\$interpolated} $latte");

function renderIt(Latte\Engine $latte, string $template): string
{
	return $latte->renderToString($template);
}
