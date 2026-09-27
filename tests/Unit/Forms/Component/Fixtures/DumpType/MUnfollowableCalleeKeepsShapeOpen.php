<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType;

use Nette\Application\UI\Control;
use Tests\OriPhpstan\Nette\Unit\Forms\Component\Fixtures\DumpType\Support\ExternalFormTweaker;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use function PHPStan\dumpType;

/**
 * The ways following a call can fail all land on the same, safe answer: the shape stays OPEN, exactly
 * as before this mechanism existed.
 *
 * - $this->tweaker: a receiver that is not $this cannot be typed without the live scope, and a
 *   scope-dependent answer would poison a cache shared between consumers.
 * - openHelper(): read in full, but incomplete itself — its reasons come across to the caller.
 * - removes()/unsets()/disables(): read in full, contribution empty, and yet NOT "adds nothing".
 *   Each takes a component away from the form it was handed, which the additive contribution the
 *   caller folds in cannot express, so closing over one would keep claiming a control that is gone.
 *   These are the cases that make the empty contribution accepted elsewhere a proof rather than a
 *   guess: emptiness alone is not the licence, an unsubtracted readable body is.
 */
final class MUnfollowableCalleeKeepsShapeOpen extends Control
{

	private ExternalFormTweaker $tweaker;

	public function __construct(ExternalFormTweaker $tweaker)
	{
		$this->tweaker = $tweaker;
	}

	protected function createComponentForeignReceiver(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('known');
		$this->tweaker->tweak($form);

		return $form;
	}

	protected function createComponentOpenCallee(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('known');
		$this->openHelper($form);

		return $form;
	}

	protected function createComponentRemovingCallee(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('known');
		$this->removes($form);

		return $form;
	}

	protected function createComponentUnsettingCallee(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('known');
		$this->unsets($form);

		return $form;
	}

	protected function createComponentDisablingCallee(): ApplicationForm
	{
		$form = new ApplicationForm();
		$form->addText('known');
		$this->disables($form);

		return $form;
	}

	private function openHelper(ApplicationForm $form): void
	{
		$form->addText('fromHelper');
		$form->magicExtensionThing(); // @phpstan-ignore method.notFound (a call the walk cannot follow)
	}

	private function removes(ApplicationForm $form): void
	{
		$form->removeComponent($form['known']);
	}

	private function unsets(ApplicationForm $form): void
	{
		unset($form['known']);
	}

	private function disables(ApplicationForm $form): void
	{
		$form->setDisabled();
	}

	public function go(): void
	{
		dumpType($this['foreignReceiver']->getValues(true)); // => array{known: string, ...<string, mixed>}
		dumpType($this['openCallee']->getValues(true)); // => array{known: string, fromHelper: string, ...<string, mixed>}
		dumpType($this['removingCallee']->getValues(true)); // => array{known: string, ...<string, mixed>}
		dumpType($this['unsettingCallee']->getValues(true)); // => array{known: string, ...<string, mixed>}
		dumpType($this['disablingCallee']->getValues(true)); // => array{known: string, ...<string, mixed>}
	}

}
