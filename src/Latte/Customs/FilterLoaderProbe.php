<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Customs;

use Closure;
use OriPhpstan\Nette\Latte\Compile\VendorErrorContainment;
use Throwable;
use function array_key_exists;
use function is_callable;
use function strtolower;

// Asks the harvested engine's filter loaders for a name its static filters lack, the way the
// runtime does on the first call of that name: with the name as written in the template. Latte 2
// files a loader's answer under the lowercase name, after which every spelling reaches it, so a
// spelling its loaders decline is asked once more in lowercase ($lowercaseFallback) - at runtime
// that spelling works only once another one has loaded the filter. Answers are memoised per
// written name, a decline (or a loader that throws) included.
final class FilterLoaderProbe
{

	/** @var Closure(string): mixed */
	private Closure $ask;

	private bool $lowercaseFallback;

	/** @var array<string, (callable(mixed...): mixed)|false> */
	private array $answers = [];

	/**
	 * @param Closure(string): mixed $ask
	 */
	public function __construct(Closure $ask, bool $lowercaseFallback)
	{
		$this->ask = $ask;
		$this->lowercaseFallback = $lowercaseFallback;
	}

	/**
	 * @return (callable(mixed...): mixed)|null
	 */
	public function resolve(string $writtenName): ?callable
	{
		if (!array_key_exists($writtenName, $this->answers)) {
			$answer = $this->ask($writtenName);
			$lowerName = strtolower($writtenName);
			if ($answer === null && $this->lowercaseFallback && $lowerName !== $writtenName) {
				$answer = $this->resolve($lowerName);
			}

			$this->answers[$writtenName] = $answer ?? false;
		}

		$answer = $this->answers[$writtenName];

		return $answer === false ? null : $answer;
	}

	/**
	 * @return (callable(mixed...): mixed)|null
	 */
	private function ask(string $name): ?callable
	{
		$ask = $this->ask;

		try {
			$answer = VendorErrorContainment::run(
				static fn () => $ask($name),
				static function (int $severity, string $message): void {
				},
			);
		} catch (Throwable $e) {
			return null;
		}

		return is_callable($answer) ? $answer : null;
	}

}
