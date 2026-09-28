<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\DeadCode;

use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;
use function strtolower;

final class DicUsageProvider implements MemberUsageProvider
{

	private const Note = 'Called by compiled Nette DI container';

	private MultiContainerRegistry $registry;

	private ContainerUsageExtractor $extractor;

	/** @var array<string, array<string, true>>|null */
	private ?array $usages = null;

	public function __construct(MultiContainerRegistry $registry, ContainerUsageExtractor $extractor)
	{
		$this->registry = $registry;
		$this->extractor = $extractor;
	}

	/**
	 * The usage is emitted against the class the container CONSTRUCTS, never against the class that
	 * declares the called method: `new Helper` reaches `HelperBase::__construct` through a hierarchy
	 * edge that lives in a third file, and nothing salts it - the compiled $wiring carries the
	 * ancestors of registered SERVICES only, so a class the container merely constructs can change its
	 * `extends` clause without moving one container byte. Resolving the hop here would mean caching
	 * HelperBase.php's verdict against Helper.php's contents, which PHPStan requeues in the opposite
	 * direction. Emitted this way, the hop is resolved by the dead-code detector's own aggregate stage
	 * from collected class definitions, and the only cross-file read left - the analysed class's own
	 * inherited members - runs along the descendant-to-ancestor edge PHPStan already tracks.
	 *
	 * @return list<ClassMethodUsage>
	 */
	public function getUsages(Node $node, Scope $scope): array
	{
		if (!$node instanceof InClassNode) { // @phpstan-ignore phpstanApi.instanceofAssumption
			return [];
		}

		if (!$this->registry->isActive()) {
			return [];
		}

		$classReflection = $node->getClassReflection();
		$className = $classReflection->getName();
		$calledMethodNames = $this->resolveUsages()[$className] ?? [];

		if ($calledMethodNames === []) {
			return [];
		}

		$usages = [];

		foreach ($classReflection->getNativeReflection()->getMethods() as $method) {
			if (!isset($calledMethodNames[strtolower($method->getName())])) {
				continue;
			}

			$usages[] = new ClassMethodUsage(
				UsageOrigin::createVirtual($this, VirtualUsageData::withNote(self::Note)),
				new ClassMethodRef($className, $method->getName(), false),
			);
		}

		return $usages;
	}

	/**
	 * @return array<string, array<string, true>>
	 */
	private function resolveUsages(): array
	{
		if ($this->usages !== null) {
			return $this->usages;
		}

		$usages = [];

		foreach ($this->registry->getContainerFilePaths() as $file) {
			foreach ($this->extractor->extractFromFile($file) as $class => $methods) {
				$usages[$class] = ($usages[$class] ?? []) + $methods;
			}
		}

		return $this->usages = $usages;
	}

}
