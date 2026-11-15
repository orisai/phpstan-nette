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
use function count;
use function sort;
use function sprintf;
use const SORT_STRING;

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
// The SAME all-renderers discipline FactoryProvidedVars::resolve() applies to this axis' other
// consumer: a template is rendered through EVERY renderer the discovery store links it to, in turn, so
// a site is certainly broken only when EVERY ONE of them proves CONTROL_NONE. When only some do, the
// paths through those renderers still lack the providers: that is reported as possibly unavailable
// (providerPossiblyUnavailable), naming one of them. No store records, or no renderer proving
// CONTROL_NONE (CONTROL_SELF, CONTROL_OTHER, unobserved, non-qualifying), or the flag being off, mean
// silence - nothing proves a path without a control.
//
// {plink}/{ifCurrent} are reported on those SAME conditions and no finer one. uiPresenter is added
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

	public const POSSIBLY_IDENTIFIER = 'orisai.nette.latte.providerPossiblyUnavailable';

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
		if ($sites === [] || !$this->enabled) {
			return [];
		}

		[$standalone, $all] = $this->standaloneRenderers($projectRelativePath);
		if ($standalone === []) {
			return [];
		}

		$diagnostics = [];
		foreach ($sites as $site) {
			$diagnostics[] = $all
				? new Diagnostic(
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
				)
				: new Diagnostic(
					self::POSSIBLY_IDENTIFIER,
					sprintf(
						'%s needs the %s provider, only available when createTemplate() receives a control, but '
						. 'renderer %s creates this template standalone (no control argument) - %s may be undefined '
						. 'here, so this macro may fatal at runtime.',
						$site['macro'],
						$site['provider'],
						$standalone[0],
						$site['provider'],
					),
					$site['line'],
				);
		}

		return $diagnostics;
	}

	// The linked renderers proving CONTROL_NONE, sorted, and whether every linked renderer does. A
	// renderer proving anything else, or nothing, may own a control; one proving NONE never gives the
	// template the providers, on the paths it renders.

	/**
	 * @return array{list<string>, bool}
	 */
	private function standaloneRenderers(string $projectRelativePath): array
	{
		$classNames = [];
		foreach ($this->store->recordsForTemplate($projectRelativePath) as $record) {
			$classNames[$record['class']] = true;
		}

		$standalone = [];
		foreach (array_keys($classNames) as $className) {
			$facts = $this->recordSource()->factsFor($className);

			// Shared qualification gate: a non-qualifying renderer's facts carry no reliable
			// createTemplateControl answer either - see Qualification's own doc.
			if (
				Qualification::qualifies($facts)
				&& $facts->getCreateTemplateControl() === PhpRenderFacts::CONTROL_NONE
			) {
				$standalone[] = $className;
			}
		}

		sort($standalone, SORT_STRING);

		return [$standalone, $standalone !== [] && count($standalone) === count($classNames)];
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
