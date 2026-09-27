<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Inference;

use OriPhpstan\Nette\Forms\Catalog\Stub\ControlAnnotationValueTypeReader;
use OriPhpstan\Nette\Forms\Rule\UnannotatedRegistrarRule;
use PHPStan\Rules\Rule;
use Tests\OriPhpstan\Nette\Toolkit\TestGuard;

/**
 * @extends InferenceRuleTest<UnannotatedRegistrarRule>
 */
final class InferenceUnannotatedRegistrarRuleTest extends InferenceRuleTest
{

	/** @var list<string> */
	private array $analysedPaths;

	protected function setUp(): void
	{
		parent::setUp();
		$this->analysedPaths = [__DIR__ . '/Fixtures'];
	}

	/** @return UnannotatedRegistrarRule */
	protected function getRule(): Rule
	{
		return new UnannotatedRegistrarRule(
			TestGuard::of(self::getContainer()),
			true,
			$this->analysedPaths,
			self::getContainer()->getByType(ControlAnnotationValueTypeReader::class),
		);
	}

	public function testOnlyAnnotatableRegistrarsAreReported(): void
	{
		$class = 'Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\UnannotatedRegistrar';

		$this->analyse(
			[__DIR__ . '/Fixtures/Rule/UnannotatedRegistrar.php'],
			[
				[$this->expectedMessage($class . '::addThing()', 'name'), 23],
				[$this->expectedMessage($class . '::attachThing()', 'name'), 33],
				[$this->expectedMessage($class . '::addByOffset()', 'name'), 38],
				[$this->expectedMessage($class . '::addByComponent()', 'name'), 43],
				[$this->expectedMessage($class . '::addLabelled()', 'name'), 51],
			],
		);
	}

	/**
	 * A trait's body is analysed once per using class, and every one of those passes is the same
	 * declaration. The report names the trait, so an author is told to write one tag rather than one
	 * per user.
	 */
	public function testATraitRegistrarIsReportedAgainstTheTraitItself(): void
	{
		$trait = 'Tests\OriPhpstan\Nette\Unit\Forms\Inference\Fixtures\Rule\UnannotatedRegistrarTrait';

		$this->analyse(
			[
				__DIR__ . '/Fixtures/Rule/UnannotatedRegistrarTrait.php',
				__DIR__ . '/Fixtures/Rule/UnannotatedRegistrarUser.php',
			],
			[
				[$this->expectedMessage($trait . '::addShared()', 'name'), 16],
			],
		);
	}

	/**
	 * The forward-looking rule is about OUR code, so the same declaration outside the analysed paths
	 * is not asked for anything — there the tag's absence is answered by the shape opening instead.
	 */
	public function testADeclarationOutsideTheAnalysedPathsIsNotReported(): void
	{
		$this->analysedPaths = [__DIR__ . '/Support'];

		$this->analyse([__DIR__ . '/Fixtures/Rule/UnannotatedRegistrar.php'], []);
	}

	private function expectedMessage(string $origin, string $parameter): string
	{
		return $origin . ' registers one component under $' . $parameter
			. ' and does not declare it with @form-adds $' . $parameter
			. '; the registration is then visible only while this class stays inside the analysed paths.';
	}

}
