<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Cache;

use OriPhpstan\Nette\Support\PhpstanRuntimeStubs;
use PHPStan\Analyser\ResultCache\ResultCacheMetaExtension;

/**
 * Closes the BODY-LEVEL result-cache hole: PHPStan re-queues a changed file's dependents only when
 * its EXPORTED nodes move, and every cross-file fact this extension derives — a field added inside
 * a createComponentX() body, a form subclass's constructor, a shared add*() helper, a replicator's
 * item factory — is a method-body fact. Without this salt a warm run keeps a shape its definer no
 * longer produces, which silently drops real errors (`Call to an undefined method
 * Nette\ComponentModel\IComponent::…` on a removed field) and silently keeps wrong types
 * downstream of getValues()/handler parameters.
 *
 * WHY THIS CHANNEL AND NOT THE AGGREGATE STAGE. The Latte bridge closed the same defect class by
 * moving its holed consumers to a `Rule<CollectedDataNode>`, which the finalizer runs after the
 * result cache is restored. That is not available here: the Forms consumers are
 * expressionTypeResolverExtension / dynamicMethodReturnTypeExtension / methodTypeSpecifyingExtension
 * services, and the decisive consumer of the reproduced case is PHPStan's OWN CallMethodsRule
 * reading a type these extensions supplied. A type has to exist while the file is being analysed,
 * so there is nothing to defer. Extra dependency edges do not help either: restore() gates on the
 * changed file's exported nodes BEFORE it ever looks at dependentFiles.
 *
 * The cost is stated where it is paid: FormFactSalt's digest covers only the form-fact-bearing
 * subset of the universe and ignores formatting and `//` comments, so a change outside that subset
 * — or a purely cosmetic one inside it — still restores the cache in full.
 */
final class FormsResultCacheMeta implements ResultCacheMetaExtension
{

	private bool $enabled;

	private FormFactSalt $salt;

	private ?CatalogIdentity $catalogIdentity;

	public function __construct(bool $enabled, FormFactSalt $salt, ?CatalogIdentity $catalogIdentity = null)
	{
		$this->enabled = $enabled;
		$this->salt = $salt;
		$this->catalogIdentity = $catalogIdentity;
	}

	public function getKey(): string
	{
		return 'orisai.nette.forms.shapeSources';
	}

	public function getHash(): string
	{
		PhpstanRuntimeStubs::ensureLoaded();

		if (!$this->enabled) {
			return 'disabled';
		}

		// A user catalog's tags feed every shape without being a dependency of any analysed file.
		$catalogs = $this->catalogIdentity !== null ? $this->catalogIdentity->get() : '';

		return $catalogs === '' ? $this->salt->get() : $this->salt->get() . '|' . $catalogs;
	}

}
