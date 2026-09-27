<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Closure;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

final class EscapeHoles
{

	/** @var array<Closure> */
	private array $builders = [];

	private static ?Closure $staticBuilder = null;

	private ?ApplicationForm $storedForm = null;

	/** @var array<ApplicationForm> */
	private array $forms = [];

	// (a) by-ref closure passed directly as argument, never assigned to a var
	public function ea(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$this->register(function () use (&$form): void {
			$form->addText('x');
		});
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	// (b) by-ref closure stored to property or array
	public function eb(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$this->builders[] = function () use (&$form): void {
			$form->addText('x');
		};
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	// (c) by-ref closure returned
	public function ec(bool $cond): ?Closure
	{
		$form = new ApplicationForm();
		$form->addText('a');

		if ($cond) {
			return function () use (&$form): void {
				$form->addText('x');
			};
		}

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);

		return null;
	}

	// (d) tracked var stored into a property
	public function ed(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$this->storedForm = $form;
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	// (e) tracked var stored into an array literal assigned to another var
	public function ee(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$meta = 'x';
		$pair = [$form, $meta];
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	// tracked var appended to a local array
	public function ef(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$arr = [];
		$arr[] = $form;
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	// tracked var appended to an array property
	public function eg(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$this->forms[] = $form;
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	// variable-bound by-ref closure stored into an array
	public function eh(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$build = function () use (&$form): void {
			$form->addText('x');
		};
		$this->builders[] = $build;
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	// variable-bound by-ref closure stored into a static property
	public function ei(): void
	{
		$form = new ApplicationForm();
		$form->addText('a');
		$build = function () use (&$form): void {
			$form->addText('x');
		};
		self::$staticBuilder = $build;
		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  a: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  ...<IComponent>,
			}
			OUTPUT);
	}

	private function register(Closure $closure): void
	{
	}

}
