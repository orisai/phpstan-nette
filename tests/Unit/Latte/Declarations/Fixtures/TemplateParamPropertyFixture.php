<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures;

/**
 * @template C of TemplateParamPropertyFixtureBound
 */
final class TemplateParamPropertyFixture
{

	/** @var C */
	public TemplateParamPropertyFixtureBound $control;

	public string $title = '';

}
