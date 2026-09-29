<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Toolkit;

use OriPhpstan\Nette\Latte\Compile\LatteCompiler;
use OriPhpstan\Nette\Latte\Declarations\DeclarationScanner;
use OriPhpstan\Nette\Latte\Includes\LatteUniverse;
use OriPhpstan\Nette\Latte\Includes\TemplateFactExtractor;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapter;
use OriPhpstan\Nette\Latte\Version\LatteVersionAdapterFactory;
use OriPhpstan\Nette\LatteForms\FormMacroCollector;
use OriPhpstan\Nette\Support\ProjectInstalledVersions;

// The adapter the installed Latte gets in production, over throwaway collaborators: an
// empty-paths universe, so form-site extraction answers [] for every path.
final class TestAdapter
{

	public static function create(?LatteCompiler $compiler = null): LatteVersionAdapter
	{
		return (new LatteVersionAdapterFactory(
			ProjectInstalledVersions::get(),
			$compiler ?? new LatteCompiler(),
			new DeclarationScanner(),
			new TemplateFactExtractor(),
			new FormMacroCollector(new LatteUniverse([], '')),
		))->create();
	}

}
