<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Parity\Lifecycle;

use Nette\Application\UI\Presenter;
use Tests\OriPhpstan\Nette\Integration\Latte\Parity\Lifecycle\Fixtures\LifecycleProbePresenter;
use function method_exists;

final class SetFileParityTest extends LifecycleParityTestCase
{

	public function testSetFileInStartupWinsAndSkipsFormula(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['startup'] = static function (LifecycleProbePresenter $p): void {
			$p->getTemplate()->setFile(__DIR__ . '/Fixtures/templates/custom.latte');
		};
		$response = $this->runPresenter($presenter);

		self::assertSame(
			$this->customTemplatePath(),
			$this->resolvedFile($response),
			'template->setFile() in startup() still wins - getTemplate() memoizes the instance sendTemplate'
				. ' later wraps, and sendTemplate keeps an already-set file untouched',
		);
		self::assertFalse(
			$this->formatTemplateFilesWasConsulted($presenter),
			'with an explicit template->setFile() in startup(), formatTemplateFiles() is never consulted',
		);
	}

	public function testSetFileInActionWinsAndSkipsFormula(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['action'] = static function (LifecycleProbePresenter $p): void {
			$p->getTemplate()->setFile(__DIR__ . '/Fixtures/templates/custom.latte');
		};
		$response = $this->runPresenter($presenter);

		self::assertSame(
			$this->customTemplatePath(),
			$this->resolvedFile($response),
			'template->setFile() in action<Action>() still wins - sendTemplate keeps an already-set file untouched',
		);
		self::assertFalse(
			$this->formatTemplateFilesWasConsulted($presenter),
			'with an explicit template->setFile() in action<Action>(), formatTemplateFiles() is never'
				. ' consulted - the discovery formula is fully bypassed',
		);
	}

	public function testSetFileInSignalHandlerWinsAndSkipsFormula(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['signal'] = static function (LifecycleProbePresenter $p): void {
			$p->getTemplate()->setFile(__DIR__ . '/Fixtures/templates/custom.latte');
		};
		$response = $this->runPresenter($presenter, ['do' => 'probe']);

		self::assertSame(
			$this->customTemplatePath(),
			$this->resolvedFile($response),
			'template->setFile() in a handle<Signal>() handler still wins - sendTemplate keeps an'
				. ' already-set file untouched',
		);
		self::assertFalse(
			$this->formatTemplateFilesWasConsulted($presenter),
			'with an explicit template->setFile() in a handle<Signal>() handler, formatTemplateFiles()'
				. ' is never consulted',
		);
	}

	public function testSetFileInFormOnSuccessWinsAndSkipsFormula(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['formSuccess'] = static function (LifecycleProbePresenter $p): void {
			$p->getTemplate()->setFile(__DIR__ . '/Fixtures/templates/custom.latte');
		};
		$response = $this->runPresenter(
			$presenter,
			[],
			['_do' => 'probeForm-submit', 'val' => 'x', 'send' => 'Save'],
		);

		self::assertSame(
			$this->customTemplatePath(),
			$this->resolvedFile($response),
			'template->setFile() in a form onSuccess callback (signal phase) still wins - sendTemplate'
				. ' keeps an already-set file untouched',
		);
		self::assertFalse(
			$this->formatTemplateFilesWasConsulted($presenter),
			'with an explicit template->setFile() in a form onSuccess callback, formatTemplateFiles()'
				. ' is never consulted',
		);
	}

	public function testSetFileInBeforeRenderWinsAndSkipsFormula(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['beforeRender'] = static function (LifecycleProbePresenter $p): void {
			$p->getTemplate()->setFile(__DIR__ . '/Fixtures/templates/custom.latte');
		};
		$response = $this->runPresenter($presenter);

		self::assertSame(
			$this->customTemplatePath(),
			$this->resolvedFile($response),
			'template->setFile() in beforeRender() still wins - sendTemplate keeps an already-set file untouched',
		);
		self::assertFalse(
			$this->formatTemplateFilesWasConsulted($presenter),
			'with an explicit template->setFile() in beforeRender(), formatTemplateFiles() is never consulted',
		);
	}

	public function testSetFileInRenderMethodWinsAndSkipsFormula(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['render'] = static function (LifecycleProbePresenter $p): void {
			$p->getTemplate()->setFile(__DIR__ . '/Fixtures/templates/custom.latte');
		};
		$response = $this->runPresenter($presenter);

		self::assertSame(
			$this->customTemplatePath(),
			$this->resolvedFile($response),
			'template->setFile() in render<View>() still wins - sendTemplate keeps an already-set file untouched',
		);
		self::assertFalse(
			$this->formatTemplateFilesWasConsulted($presenter),
			'with an explicit template->setFile() in render<View>(), formatTemplateFiles() is never consulted',
		);
	}

	public function testSetFileInShutdownStillMutatesTheResponseTemplate(): void
	{
		$presenter = $this->createPresenter();
		$presenter->hooks['shutdown'] = static function (LifecycleProbePresenter $p): void {
			$p->getTemplate()->setFile(__DIR__ . '/Fixtures/templates/custom.latte');
		};
		$response = $this->runPresenter($presenter);

		self::assertTrue(
			$this->formatTemplateFilesWasConsulted($presenter),
			'without an earlier setFile(), sendTemplate did consult formatTemplateFiles() and resolved'
				. ' the formula file first',
		);
		self::assertSame(
			$this->customTemplatePath(),
			$this->resolvedFile($response),
			'template->setFile() in shutdown() STILL changes the file the response will render - the'
				. ' TextResponse holds the same mutable Template instance and actual rendering happens only'
				. ' when the response is sent, after run() returns; sendTemplate resolution assigns a default'
				. ' file, it does not freeze it',
		);
	}

	public function testSetActionAliasDoesNotExistOnThisVendorVersion(): void
	{
		self::assertFalse(
			method_exists(Presenter::class, 'setAction'),
			'nette/application 3.1.15 still has NO setAction() alias - changeAction() (exercised by every'
				. ' probe above) is the only action-rewrite entry point the discovery walk needs to model',
		);
	}

}
