<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Fixtures\MaxLevel;

use Nette\Forms\Controls\RadioList;
use Nette\Forms\Controls\SelectBox;
use Nette\Forms\Controls\TextInput;
use Nette\Forms\Form;

/**
 * @extends BaseTemplate<PageControl>
 */
final class PageTemplate extends BaseTemplate
{

	public Form $form;

	public TextInput $name;

	public SelectBox $country;

	public RadioList $gender;

	/** @var mixed */
	public $loose;

	/** @var list<string> */
	public array $items = [];

}
