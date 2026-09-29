<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Component\Attachment\GenericLookup;

use Nette\Forms\Container as FormsContainer;
use Nette\Forms\Controls\TextInput;
use function PHPStan\Testing\assertType;

/**
 * lookup() is left to phpstan-nette's own extension, which narrows only the spelling that
 * writes $throw out - so the default one, which throws just the same, keeps its declared null.
 */
final class ParentAccessorLookup
{

	public function lookupIsLeftToTheVendorExtension(TextInput $input): void
	{
		assertType('Nette\Forms\Container', $input->lookup(FormsContainer::class));
		assertType('Nette\Forms\Container', $input->lookup(FormsContainer::class, true));
		assertType('Nette\Forms\Container|null', $input->lookup(FormsContainer::class, false));
	}

}
