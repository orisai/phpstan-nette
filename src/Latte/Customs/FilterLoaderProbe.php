<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Customs;

use Closure;
use OriPhpstan\Nette\Latte\Compile\VendorErrorContainment;
use Throwable;
use function array_key_exists;
use function is_callable;
use function strtolower;

// Asks the harvested engine's filter loaders for a name its static filters lack, the way the
// runtime does on the first call of that name. Latte 3 passes the name as written and keeps it
// case-sensitive; Latte 2 keys whatever a loader answers by the lowercase name, so it is asked in
// lowercase. Answers are memoised per name, a decline (or a loader that throws) included.
final class FilterLoaderProbe
{

	/** @var Closure(string): mixed */
	private Closure $ask;

	private bool $caseSensitive;

	/** @var array<string, (callable(mixed...): mixed)|false> */
	private array $answers = [];

	/**
	 * @param Closure(string): mixed $ask
	 */
	public function __construct(Closure $ask, bool $caseSensitive)
	{
		$this->ask = $ask;
		$this->caseSensitive = $caseSensitive;
	}

	public function queryName(string $writtenName): string
	{
		return $this->caseSensitive ? $writtenName : strtolower($writtenName);
	}

	/**
	 * @return (callable(mixed...): mixed)|null
	 */
	public function resolve(string $writtenName): ?callable
	{
		$name = $this->queryName($writtenName);
		if (!array_key_exists($name, $this->answers)) {
			$this->answers[$name] = $this->ask($name) ?? false;
		}

		$answer = $this->answers[$name];

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
