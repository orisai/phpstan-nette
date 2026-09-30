<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Index;

use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Forms\Catalog\ControlAcceptedTypeResolver;
use OriPhpstan\Nette\Forms\Component\InterproceduralShapeKey;
use OriPhpstan\Nette\Forms\Shape\ComponentShapeRenderer;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * End-of-run index exposure behind the orisai.nette.forms.internals.indexShadowCompare flag (off, this rule and its collector do
 * nothing).
 *
 * Every interprocedural kind is now flipped — readers consult the RegistrationIndex / the on-demand twins
 * directly, there is no collector store left to shadow-compare against. So the rule exposes the index's own
 * answer as `index = <render>` for every method-param key ShadowCandidateCollector emitted (a miss emits
 * nothing); IndexCacheTest, DeterminismTortureTest, UniverseFoldTest and ShadowCompareTest pin those
 * renderings. The former forFactoryMethod store-vs-twin comparison retired with the C9 factory flip: the
 * collector write is gone and ContainerModel::factoryMethodShape computes on demand as the sole source, so
 * there is no store left to shadow.
 *
 * @implements Rule<CollectedDataNode>
 */
final class ShadowDivergenceRule implements Rule
{

	private bool $enabled;

	private IndexShapeResolver $resolver;

	private ComponentShapeRenderer $renderer;

	public function __construct(
		ConfigurationGuard $guard,
		bool $enabled,
		IndexShapeResolver $resolver,
		TypeStringResolver $typeStringResolver
	)
	{
		$guard->validate();
		$this->enabled = $guard->isFormsEnabled() && $enabled;
		$this->resolver = $resolver;
		$this->renderer = new ComponentShapeRenderer(new ControlAcceptedTypeResolver($typeStringResolver));
	}

	public function getNodeType(): string
	{
		return CollectedDataNode::class;
	}

	/**
	 * @param CollectedDataNode $node
	 * @return list<IdentifierRuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if (!$this->enabled) {
			return [];
		}

		$this->resolver->bindScope($scope);

		$errors = [];
		$seen = [];
		foreach ($node->get(ShadowCandidateCollector::class) as $file => $perNode) {
			foreach ($perNode as $candidates) {
				foreach ($candidates as $candidate) {
					$key = InterproceduralShapeKey::forMethodParam(
						$candidate['class'],
						$candidate['method'],
						$candidate['paramIdx'],
					);
					if (isset($seen[$key])) {
						continue;
					}

					$seen[$key] = true;

					$shape = $this->resolver->resolveMethodParam(
						$candidate['class'],
						$candidate['method'],
						$candidate['paramIdx'],
					);
					if ($shape === null) {
						continue;
					}

					$errors[] = RuleErrorBuilder::message(
						"Index rendering for {$key}:\nindex = " . $this->renderer->render($shape),
					)
						->file($file)
						->line($candidate['line'])
						->identifier('orisaiNette.forms.shadowDivergence')
						->nonIgnorable()
						->build();
				}
			}
		}

		return $errors;
	}

}
