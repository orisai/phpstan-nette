<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope;

use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\Base\NameScopeParent;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\Imported;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\Imported\ImportedOption;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\Imported\ImportedOption as AliasedOption;
use Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\Traits\NameScopeTrait;

/**
 * @template C
 */
final class NameScopeTemplate extends NameScopeParent
{

	use NameScopeTrait;

	public const ModeA = 'a';

	public const ModeB = 'b';

	/** @var list<SiblingOption> */
	public array $siblings = [];

	/** @var array<int, ImportedOption> */
	public array $imported = [];

	/** @var array<string, list<AliasedOption>> */
	public array $nested = [];

	/** @var array{first: SiblingOption, second?: ImportedOption|null, NameScopeTemplate::ModeA: int} */
	public array $shape;

	/** @var SiblingOption|null */
	public $nullableUnion;

	/** @var ?AliasedOption */
	public $nullable;

	/** @var NameScopeTemplate::Mode* */
	public string $mode = self::ModeA;

	/** @var self::Mode* */
	public string $selfMode = self::ModeB;

	/** @var Imported\ImportedOption */
	public $partiallyQualified;

	/** @var \Tests\OriPhpstan\Nette\Unit\Latte\Declarations\Fixtures\NameScope\SiblingOption */
	public $fullyQualified;

	/** @var int<0, max> */
	public int $range = 0;

	/** @var non-empty-string */
	public string $keyword = 'x';

	/** @var list<C> */
	public array $templated = [];

	/** @var class-string<SiblingOption> */
	public string $classString = SiblingOption::class;

	/** @var array<int, SiblingOption> some description */
	public array $described = [];

	public ?SiblingOption $native = null;

}
