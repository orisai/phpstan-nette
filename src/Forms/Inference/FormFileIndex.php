<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Inference;

use OriPhpstan\Nette\Forms\Shape\FormShape;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PHPStan\Parser\Parser;
use function in_array;
use function spl_object_id;
use function strlen;
use function strncmp;

final class FormFileIndex
{

	private Parser $parser;

	private EnclosingFunctionLikeLocator $locator;

	private IntraProceduralFormGate $gate;

	/** @var array<string, array{fns: list<FunctionLike>, tracked: array<int, list<string>>, hasForm: bool}> */
	private array $files = [];

	/** @var array<string, FormShape> */
	private array $shapes = [];

	public function __construct(Parser $parser, EnclosingFunctionLikeLocator $locator, IntraProceduralFormGate $gate)
	{
		$this->parser = $parser;
		$this->locator = $locator;
		$this->gate = $gate;
	}

	public function hasAnyTrackedForm(string $file): bool
	{
		return $this->entry($file)['hasForm'];
	}

	public function enclosingFunctionLike(string $file, Expr $expr): ?FunctionLike
	{
		return $this->locator->selectEnclosing($this->entry($file)['fns'], $expr);
	}

	public function trackedVariable(string $file, FunctionLike $fn, string $varName): ?string
	{
		$tracked = $this->entry($file)['tracked'][spl_object_id($fn)] ?? [];

		return in_array($varName, $tracked, true) ? $varName : null;
	}

	/**
	 * @param callable(): FormShape $compute
	 */
	public function formShape(string $file, FunctionLike $fn, string $varName, callable $compute): FormShape
	{
		$key = $file . '|' . spl_object_id($fn) . '|' . $varName;
		if (!isset($this->shapes[$key])) {
			$this->shapes[$key] = $compute();
		}

		return $this->shapes[$key];
	}

	/**
	 * @return array{fns: list<FunctionLike>, tracked: array<int, list<string>>, hasForm: bool}
	 */
	private function entry(string $file): array
	{
		if (!isset($this->files[$file])) {
			// $shapes keys embed spl_object_id($fn) of nodes owned by $files; evict together or id reuse serves a wrong shape.
			$this->files = [];
			$prefix = $file . '|';
			foreach ($this->shapes as $key => $shape) {
				if (strncmp($key, $prefix, strlen($prefix)) !== 0) {
					unset($this->shapes[$key]);
				}
			}

			$fns = $this->locator->findFunctionLikes($this->parser->parseFile($file));
			$tracked = [];
			$hasForm = false;
			foreach ($fns as $fn) {
				$vars = $this->gate->trackedVariables($fn);
				$tracked[spl_object_id($fn)] = $vars;
				if ($vars !== []) {
					$hasForm = true;
				}
			}

			$this->files[$file] = ['fns' => $fns, 'tracked' => $tracked, 'hasForm' => $hasForm];
		}

		return $this->files[$file];
	}

}
