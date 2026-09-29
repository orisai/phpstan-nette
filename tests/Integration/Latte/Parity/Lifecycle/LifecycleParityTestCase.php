<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Integration\Latte\Parity\Lifecycle;

use Latte\Engine;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Template;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Bridges\ApplicationLatte\TemplateFactory;
use Nette\Http;
use Tests\OriPhpstan\Nette\Integration\Latte\Parity\Lifecycle\Fixtures\LifecycleProbePresenter;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use Tests\OriPhpstan\Nette\Toolkit\PresenterFactory;
use function in_array;
use function strncmp;

abstract class LifecycleParityTestCase extends BaseTestCase
{

	protected function createPresenter(): LifecycleProbePresenter
	{
		$presenter = new LifecycleProbePresenter();
		$presenter->autoCanonicalize = false;

		return PresenterFactory::inject(
			$presenter,
			new Http\Request(
				new Http\UrlScript('http://localhost/'),
				[],
				[],
				['_nss' => '1'],
			),
			new TemplateFactory(new class implements LatteFactory {

				public function create(): Engine
				{
					return new Engine();
				}

			}),
		);
	}

	/**
	 * @param array<string, mixed> $params
	 * @param array<string, mixed> $post
	 */
	protected function runPresenter(LifecycleProbePresenter $presenter, array $params = [], array $post = []): Response
	{
		$params['action'] ??= 'probe';

		return $presenter->run(new Request('Lifecycle', $post === [] ? 'GET' : 'POST', $params, $post));
	}

	protected function resolvedFile(Response $response): string
	{
		self::assertInstanceOf(TextResponse::class, $response);
		$template = $response->getSource();
		self::assertInstanceOf(Template::class, $template);

		return (string) $template->getFile();
	}

	protected function templatePath(string $view): string
	{
		return __DIR__ . '/Fixtures/templates/Lifecycle/' . $view . '.latte';
	}

	protected function customTemplatePath(): string
	{
		return __DIR__ . '/Fixtures/templates/custom.latte';
	}

	protected function formatTemplateFilesWasConsulted(LifecycleProbePresenter $presenter): bool
	{
		foreach ($presenter->log as $entry) {
			if (strncmp($entry, 'formatTemplateFiles(', 20) === 0) {
				return true;
			}
		}

		return false;
	}

	protected function renderMethodDispatched(LifecycleProbePresenter $presenter, string $renderMethod): bool
	{
		return in_array($renderMethod, $presenter->log, true);
	}

}
