<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use Nette\Utils\Json;
use Symfony\Component\Process\Process;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\IsolatedPhpstanConfig;
use Tests\OriPhpstan\Nette\Toolkit\VendorDirectory;
use function dirname;
use const PHP_BINARY;

/**
 * Runs the dumpComponent() fixtures through a real PHPStan analysis (full autoload,
 * the interprocedural form-shape store populated by the collectors) and asserts the whole
 * rendered component-shape block that ComponentShapeDumpRule reports for each call.
 */
final class InferenceComponentShapeDumpTest extends BaseTestCase
{

	/** @var array<int, string>|null */
	private static ?array $dumpsByLine = null;

	public function testFlatFormLeafControls(): void
	{
		self::assertSame(
			<<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  age: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, int|null>,
			  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  save: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
			}
			OUTPUT,
			self::dumpAtLine(26),
		);
	}

	public function testNestedContainer(): void
	{
		self::assertSame(
			<<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  address: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    city: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			    zip: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT,
			self::dumpAtLine(47),
		);
	}

	public function testReplicatorHasDynamicChildren(): void
	{
		self::assertSame(
			<<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  items: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomReplicatorContainer<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    label: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  }>>+own*mixed*{
			    ...<IComponent>,
			  },
			}
			OUTPUT,
			self::dumpAtLine(67),
		);
	}

	public function testControlContainingForm(): void
	{
		self::assertSame(
			<<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\ControlWithFormDump{
			  form: Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			    name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			}
			OUTPUT,
			self::dumpAtLine(85),
		);
	}

	public function testFormWithNonFormSubcomponentRendersTypeOnly(): void
	{
		self::assertSame(
			<<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  widget: Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\MyNonFormComponent<*any*, *unknown*>,
			}
			OUTPUT,
			self::dumpAtLine(104),
		);
	}

	public function testFormContainerSubComponent(): void
	{
		self::assertSame(
			<<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			  inner: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT,
			self::dumpAtLine(123),
		);
	}

	/**
	 * The declared return class is substituted for a chain terminal the walk could not follow, so the
	 * class is the ONLY thing known about this component - the origin was never read. Closed and
	 * empty would be a positive claim that the form owns nothing, which is the false proof the
	 * directly-returned sibling below already avoids; both are open now, for the one reason.
	 */
	public function testOpaqueBuilderChainFallsBackToDeclaredReturnTypeAndOpens(): void
	{
		self::assertSame(
			<<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  ...<IComponent>,
			}
			OUTPUT,
			self::dumpAtLine(41),
		);
	}

	public function testOpaqueBuilderChainReturnedDirectlyStaysOpen(): void
	{
		self::assertSame(
			<<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\OpaqueChainInnerControl{
			  ...<IComponent>,
			}
			OUTPUT,
			self::dumpAtLine(87),
		);
	}

	public function testWizardRendersStepsKeyedByNumber(): void
	{
		self::assertSame(
			<<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\MyWizard{
			  step 1: Nette\Application\UI\Form{
			    username: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			  step 2: Nette\Application\UI\Form{
			    email: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			}
			OUTPUT,
			self::dumpAtLine(14),
		);
	}

	public function testWizardUnresolvableStepRendersOpen(): void
	{
		self::assertSame(
			<<<'OUTPUT'
			Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\PartlyOpaqueWizard{
			  step 1: Nette\Application\UI\Form{
			    username: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			  },
			  step 2: Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\OpaqueStepForm{
			    blob: *mixed*<*any*, *unknown*>,
			    ...<IComponent>,
			  },
			}
			OUTPUT,
			self::dumpAtLine(19),
		);
	}

	public function testDepthZeroRendersRootOnly(): void
	{
		self::assertSame(
			'Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{ … }',
			self::dumpAtLine(146),
		);
	}

	public function testDepthOneTruncatesNestedChildren(): void
	{
		self::assertSame(
			<<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  address: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{ … },
			  items: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomReplicatorContainer<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{ … }>>+own*mixed*{ … },
			  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			}
			OUTPUT,
			self::dumpAtLine(147),
		);
	}

	public function testFormValuesOffRendersStructureOnly(): void
	{
		self::assertSame(
			<<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  address: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    city: Nette\Forms\Controls\TextInput,
			  },
			  items: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomReplicatorContainer<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    label: Nette\Forms\Controls\TextInput,
			  }>>+own*mixed*{
			    ...<IComponent>,
			  },
			  name: Nette\Forms\Controls\TextInput,
			}
			OUTPUT,
			self::dumpAtLine(148),
		);
	}

	public function testAddMethodOffsetAndAddComponentProduceEqualShapes(): void
	{
		$expected = <<<'OUTPUT'
		Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
		  addr: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
		    city: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
		  },
		  items: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomReplicatorContainer<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
		    label: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
		  }>>+own*mixed*{
		    ...<IComponent>,
		  },
		  name: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
		}
		OUTPUT;

		self::assertSame($expected, self::dumpAtLine(171), 'add* methods');
		self::assertSame($expected, self::dumpAtLine(195), 'offset assignment');
		self::assertSame($expected, self::dumpAtLine(219), 'addComponent');
	}

	private static function dumpAtLine(int $line): string
	{
		return self::dumps()[$line] ?? '';
	}

	/**
	 * @return array<int, string>
	 */
	private static function dumps(): array
	{
		if (self::$dumpsByLine !== null) {
			return self::$dumpsByLine;
		}

		$root = dirname(__DIR__, 4);
		$isolated = IsolatedPhpstanConfig::create(__DIR__ . '/../Component/component-real.neon');

		try {
			$process = new Process(
				[
					PHP_BINARY,
					$root . '/' . VendorDirectory::name() . '/bin/phpstan',
					'analyse',
					__DIR__ . '/Fixtures/Rule/ComponentShape.php',
					__DIR__ . '/Fixtures/Rule/MyNonFormComponent.php',
					__DIR__ . '/Fixtures/Rule/WizardShape.php',
					__DIR__ . '/Fixtures/Rule/OpaqueChainShape.php',
					'-c',
					$isolated->getConfigPath(),
					'--error-format=json',
					'--no-progress',
					'--memory-limit=2048M',
				],
				$root,
			);
			$process->setTimeout(600.0);
			$process->run();

			/** @var array{files?: array<string, array{messages?: list<array{identifier?: string, line: int, message: string}>}>} $decoded */
			$decoded = Json::decode($process->getOutput(), Json::FORCE_ARRAY);
			$result = [];
			foreach ($decoded['files'] ?? [] as $info) {
				foreach ($info['messages'] ?? [] as $message) {
					if (($message['identifier'] ?? null) === 'orisaiNette.forms.componentShapeDump') {
						$result[$message['line']] = $message['message'];
					}
				}
			}

			return self::$dumpsByLine = $result;
		} finally {
			$isolated->cleanup();
		}
	}

}
