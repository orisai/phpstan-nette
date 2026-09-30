<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Includes;

use LogicException;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryRecordSource;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\Qualification;
use OriPhpstan\Nette\Latte\Compile\Diagnostic;
use PHPStan\DependencyInjection\Container;
use function array_keys;
use function sprintf;

// Nette\Bridges\ApplicationLatte\TemplateFactory::setupLatte2() (TemplateFactory.php, ~lines
// 158-169) adds uiControl/uiPresenter/snippetBridge inside a single `if ($control) { ... }`:
//   $latte->addProvider('uiControl', $control);
//   $latte->addProvider('uiPresenter', $presenter);
//   $latte->addProvider('snippetBridge', new SnippetBridge($control));
// and Latte\Runtime\Template::doRender() derives snippetDriver from snippetBridge the same way -
// `if (isset($this->global->snippetBridge) ...) { $this->global->snippetDriver = new
// SnippetDriver($this->global->snippetBridge); }` - so all three exist iff createTemplate() got a
// control, which is exactly PhpRenderFacts::getCreateTemplateControl()'s own recorded axis (see its
// class doc, and FactoryProvidedVars' - the established consumer of the very same fact). A template
// rendered only through a standalone $factory->createTemplate() has none of the three, and a macro
// compiling to $this->global->uiControl/uiPresenter->... (or a {snippet} built on snippetDriver)
// dereferences null at runtime.
//
// OPEN, never false-close - the SAME all-renderers discipline FactoryProvidedVars::resolve() applies
// to this axis' other consumer: a template is rendered through EVERY renderer the discovery store
// links it to, in turn, so this claims a site only when EVERY ONE of them proves CONTROL_NONE. No
// store records, any CONTROL_SELF, any CONTROL_OTHER, a non-qualifying renderer, or the flag being
// off, all mean silence - at least one path through this template may really own a control, and this
// source has no business claiming otherwise.
//
// {plink}/{ifCurrent} are reported on that SAME condition and no finer one. uiPresenter is added
// INSIDE the same `if ($control)` block but from a POSSIBLY-NULL $presenter
// ($presenter = $control ? $control->getPresenterIfExists() : null) - so a detached control (CONTROL_SELF
// on a plain, non-Presenter Control) has the provider PRESENT but null, which would ALSO fatal on
// ->link()/->isLinkCurrent(). That is the presenter-availability question FactoryProvidedVars' own
// class doc already declines to answer for controls ("nothing claimed" - see its `presenter` row),
// and this checker declines it again rather than inventing a finer, unproven condition: only the
// no-provider-at-all case (CONTROL_NONE, everywhere) is ever claimed.
final class ProviderAvailabilityChecker
{

	public const IDENTIFIER = 'orisai.nette.latte.providerUnavailable';

	public const RECORD_SOURCE_SERVICE_NAME = 'latteDiscoveryRecordSource';

	private Container $container;

	private DiscoveryStore $store;

	private bool $enabled;

	private ?DiscoveryRecordSource $recordSource = null;

	public function __construct(Container $container, DiscoveryStore $store, bool $enabled)
	{
		$this->container = $container;
		$this->store = $store;
		$this->enabled = $enabled;
	}

	/**
	 * @param list<array{macro: string, provider: string, line: int}> $sites
	 * @return list<Diagnostic>
	 */
	public function check(string $projectRelativePath, array $sites): array
	{
		if ($sites === [] || !$this->enabled || !$this->everyLinkedRendererProvesControlNone($projectRelativePath)) {
			return [];
		}

		$diagnostics = [];
		foreach ($sites as $site) {
			$diagnostics[] = new Diagnostic(
				self::IDENTIFIER,
				sprintf(
					'%s needs the %s provider, only available when createTemplate() receives a control, but '
					. 'every renderer linked to this template creates it standalone (no control argument) - '
					. '%s is undefined here, so this macro fatals at runtime.',
					$site['macro'],
					$site['provider'],
					$site['provider'],
				),
				$site['line'],
			);
		}

		return $diagnostics;
	}

	private function everyLinkedRendererProvesControlNone(string $projectRelativePath): bool
	{
		$classNames = [];
		foreach ($this->store->recordsForTemplate($projectRelativePath) as $record) {
			$classNames[$record['class']] = true;
		}

		// OPEN, never false-close: no store records at all means no renderer proved anything.
		if ($classNames === []) {
			return false;
		}

		foreach (array_keys($classNames) as $className) {
			$facts = $this->recordSource()->factsFor($className);

			// Shared qualification gate: a non-qualifying renderer's facts carry no reliable
			// createTemplateControl answer either - see Qualification's own doc.
			if (!Qualification::qualifies($facts)) {
				return false;
			}

			if ($facts->getCreateTemplateControl() !== PhpRenderFacts::CONTROL_NONE) {
				return false;
			}
		}

		return true;
	}

	private function recordSource(): DiscoveryRecordSource
	{
		if ($this->recordSource === null) {
			$recordSource = $this->container->getService(self::RECORD_SOURCE_SERVICE_NAME);
			if (!$recordSource instanceof DiscoveryRecordSource) {
				throw new LogicException(
					self::RECORD_SOURCE_SERVICE_NAME . ' must be a DiscoveryRecordSource service.',
				);
			}

			$this->recordSource = $recordSource;
		}

		return $this->recordSource;
	}

}
