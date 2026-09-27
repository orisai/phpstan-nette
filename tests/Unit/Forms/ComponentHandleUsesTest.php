<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms;

use OriPhpstan\Nette\Forms\Graph\ComponentHandleUses;
use PhpParser\ParserFactory;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

/**
 * The use half of "a child pulled into a local does not open the form", asserted on its own.
 *
 * It used to be sound only in company: the caller first proved the handle's class had no registration
 * surface at all, because the registering-name authority calls `getComponent()` inert — correctly for
 * a name that describes a READ — while the container method of that name creates and attaches what it
 * does not find. So the predicate answered "inert" about a use that registers, and only the caller's
 * class test kept that answer from reaching a shape. The class test is gone; what replaced it is the
 * lazy read being refused here, which is why every case below is a statement about the USE alone.
 */
final class ComponentHandleUsesTest extends BaseTestCase
{

	public function testASetterChainOnTheHandleIsInert(): void
	{
		self::assertTrue($this->everyUseIsInert(<<<'PHP'
			$probe = $form['a'];
			$probe->setDefaultValue('x');
			PHP));
	}

	public function testAnInstanceofProbeAndAReReadAreInert(): void
	{
		self::assertTrue($this->everyUseIsInert(<<<'PHP'
			$probe = $form['a'];
			assert($probe instanceof BaseControl);
			$probe = $form['b'];
			$probe->setDisabled();
			PHP));
	}

	public function testARegisteringHopAnywhereInTheChainIsNotInert(): void
	{
		self::assertFalse($this->everyUseIsInert(<<<'PHP'
			$probe = $form['a'];
			$probe->getForm()->addText('late');
			PHP));
	}

	/**
	 * The hole the caller's class test used to cover. `getComponent` is not an add* name, so the
	 * registering-name authority passes it; on a container it creates and attaches the name it does
	 * not find, and the walk would then never see the component.
	 */
	public function testALazyReadHopIsNotInert(): void
	{
		self::assertFalse($this->everyUseIsInert(<<<'PHP'
			$probe = $form['a'];
			$probe->getComponent('inner');
			PHP));
	}

	public function testALazyReadDeeperInTheChainIsNotInertEither(): void
	{
		self::assertFalse($this->everyUseIsInert(<<<'PHP'
			$probe = $form['a'];
			$probe->getParent()->getComponent('inner');
			PHP));
	}

	/**
	 * The near miss that keeps the refusal from being a prefix match: `getComponents()` enumerates
	 * what is already attached and creates nothing.
	 */
	public function testEnumeratingTheChildrenIsStillInert(): void
	{
		self::assertTrue($this->everyUseIsInert(<<<'PHP'
			$probe = $form['a'];
			$probe->getComponents();
			PHP));
	}

	public function testAHandleHandedToACalleeIsNotInert(): void
	{
		self::assertFalse($this->everyUseIsInert(<<<'PHP'
			$probe = $form['a'];
			$this->tweak($probe);
			PHP));
	}

	public function testAnOffsetWriteThroughTheHandleIsNotInert(): void
	{
		self::assertFalse($this->everyUseIsInert(<<<'PHP'
			$probe = $form['a'];
			$probe['inner'] = $control;
			PHP));
	}

	/**
	 * A name with no occurrence at all proves nothing: the caller's own read is one, so an empty
	 * answer means the two are not looking at the same body.
	 */
	public function testAHandleTheBodyNeverMentionsIsNotInert(): void
	{
		self::assertFalse($this->everyUseIsInert(<<<'PHP'
			$other = $form['a'];
			$other->setDisabled();
			PHP));
	}

	private function everyUseIsInert(string $body, string $handle = 'probe', string $tracked = 'form'): bool
	{
		$stmts = (new ParserFactory())->createForNewestSupportedVersion()->parse("<?php\n" . $body);
		self::assertNotNull($stmts);

		return ComponentHandleUses::everyUseIsInert($stmts, $handle, $tracked);
	}

}
