<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Version;

use Latte\Engine;
use LogicException;
use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use OriPhpstan\Nette\Latte\Version\Latte2\Latte2Adapter;
use OriPhpstan\Nette\LatteForms\FormMacroCollector;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;
use ReflectionMethod;
use function class_exists;

final class LatteVersionAdapterFactory
{

	// Resolved at runtime only: the Latte 3 adapter is analysed under its own profile and stays
	// out of the default one's type-checked graph.
	private const LATTE3_ADAPTER_CLASS = 'OriPhpstan\Nette\Latte\Version\Latte3\Latte3Adapter';

	private ProjectInstalledVersions $installedVersions;

	private LatteCompiler $compiler;

	private DeclarationScanner $scanner;

	private TemplateFactExtractor $factExtractor;

	private FormMacroCollector $formMacroCollector;

	public function __construct(
		ProjectInstalledVersions $installedVersions,
		LatteCompiler $compiler,
		DeclarationScanner $scanner,
		TemplateFactExtractor $factExtractor,
		FormMacroCollector $formMacroCollector
	)
	{
		$this->installedVersions = $installedVersions;
		$this->compiler = $compiler;
		$this->scanner = $scanner;
		$this->factExtractor = $factExtractor;
		$this->formMacroCollector = $formMacroCollector;
	}

	public function create(): LatteVersionAdapter
	{
		$family = ShapeFamily::detect(
			$this->installedVersions->getVersion('latte/latte') ?? Engine::VERSION,
			$this->installedVersions->getVersion('nette/forms'),
		);

		if ($family->latteLine === ShapeFamily::LATTE_2) {
			return new Latte2Adapter($this->compiler, $this->scanner, $this->factExtractor, $this->formMacroCollector);
		}

		return self::createLatte3($family);
	}

	private static function createLatte3(ShapeFamily $family): LatteVersionAdapter
	{
		if (!class_exists(self::LATTE3_ADAPTER_CLASS)) {
			throw new LogicException('Latte 3 adapter not available yet');
		}

		$adapter = (new ReflectionMethod(self::LATTE3_ADAPTER_CLASS, 'create'))->invoke(null, $family);
		if (!$adapter instanceof LatteVersionAdapter) {
			throw new LogicException('Latte 3 adapter factory must return a LatteVersionAdapter.');
		}

		return $adapter;
	}

}
