<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use Nette\Application\UI\Presenter;
use Nette\Application\UI\TemplateFactory;
use Nette\Http;
use ReflectionMethod;
use ReflectionNamedType;

final class PresenterFactory
{

	/**
	 * @template T of Presenter
	 * @param T $presenter
	 * @return T
	 */
	public static function inject(
		Presenter $presenter,
		?Http\IRequest $httpRequest = null,
		?TemplateFactory $templateFactory = null
	): Presenter
	{
		$httpRequest ??= new Http\Request(new Http\UrlScript('http://localhost/'));

		$byType = [
			Http\IRequest::class => $httpRequest,
			Http\IResponse::class => new Http\Response(),
			TemplateFactory::class => $templateFactory,
		];

		$arguments = [];
		foreach ((new ReflectionMethod($presenter, 'injectPrimary'))->getParameters() as $parameter) {
			$type = $parameter->getType();
			$arguments[] = $type instanceof ReflectionNamedType ? $byType[$type->getName()] ?? null : null;
		}

		// @phpstan-ignore argument.type (arity and order follow the installed signature)
		$presenter->injectPrimary(...$arguments);

		return $presenter;
	}

}
