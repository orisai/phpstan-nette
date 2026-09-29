<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Parity\Factory\Fixtures;

use Nette\Application\Attributes\TemplateVariable;
use Nette\Application\UI\Presenter;

final class ParityTemplateVariablePresenter extends Presenter
{

	#[TemplateVariable]
	public string $uninitialized;

	#[TemplateVariable]
	public string $initialized = 'x';

	#[TemplateVariable]
	public ?int $nullable = null;

	public string $unmarked = '';

}
