<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Parity\Lifecycle;

use function array_slice;
use function count;

final class LifecycleOrderParityTest extends LifecycleParityTestCase
{

	public function testFullOrderWithPresenterSignal(): void
	{
		$presenter = $this->createPresenter();
		$response = $this->runPresenter($presenter, ['do' => 'probe']);

		self::assertSame(
			[
				'checkRequirements(class)',
				'startup',
				'checkRequirements(actionProbe)',
				'actionProbe',
				'checkRequirements(handleProbe)',
				'handleProbe',
				'beforeRender',
				'checkRequirements(renderProbe)',
				'renderProbe',
				'afterRender',
				'formatTemplateFiles(view=probe)',
				'shutdown',
			],
			$presenter->log,
			'Presenter::run() still executes checkRequirements(class-reflection) BEFORE startup(), then'
				. ' action<Action> -> signal handler -> beforeRender -> render<View> -> afterRender, and only then'
				. ' resolves the template file (formatTemplateFiles at sendTemplate time), with shutdown last;'
				. ' checkRequirements(method-reflection) additionally guards each tryCall-dispatched method',
		);
		self::assertSame(
			$this->templatePath('probe'),
			$this->resolvedFile($response),
			'sendTemplate still resolves the file from formatTemplateFiles() when no explicit setFile happened',
		);
	}

	public function testFormatTemplateFilesEvaluatesAfterRenderMethodReturns(): void
	{
		$presenter = $this->createPresenter();
		$this->runPresenter($presenter);

		self::assertSame(
			['renderProbe', 'afterRender', 'formatTemplateFiles(view=probe)', 'shutdown'],
			array_slice($presenter->log, count($presenter->log) - 4),
			'formatTemplateFiles() is still consulted LAST - after render<View>() and afterRender() have'
				. ' returned - so every view mutation up to and including afterRender() reaches file resolution',
		);
	}

	public function testFormSubmitCallbackFiresDuringSignalPhase(): void
	{
		$presenter = $this->createPresenter();
		$response = $this->runPresenter(
			$presenter,
			[],
			['_do' => 'probeForm-submit', 'val' => 'hello', 'send' => 'Save'],
		);

		self::assertSame(
			[
				'checkRequirements(class)',
				'startup',
				'checkRequirements(actionProbe)',
				'actionProbe',
				'formOnSuccess(val=hello)',
				'beforeRender',
				'checkRequirements(renderProbe)',
				'renderProbe',
				'afterRender',
				'formatTemplateFiles(view=probe)',
				'shutdown',
			],
			$presenter->log,
			'A submitted UI\Form still fires its onSuccess callbacks during processSignal() - AFTER'
				. ' action<Action>() and BEFORE beforeRender() - so form-callback code runs inside the'
				. ' same still-effective setView/changeAction window as a presenter signal handler',
		);
		self::assertSame(
			$this->templatePath('probe'),
			$this->resolvedFile($response),
			'A form submit without view mutation still resolves the action-derived template file',
		);
	}

}
