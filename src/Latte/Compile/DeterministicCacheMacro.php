<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Compile;

use Latte;

final class DeterministicCacheMacro implements Latte\Macro
{

	private bool $used;

	private int $keyCounter = 0;

	public function initialize()
	{
		$this->used = false;
	}

	/**
	 * @return array{0: string, 1?: string}|null
	 */
	public function finalize()
	{
		if ($this->used) {
			return ['Nette\Bridges\CacheLatte\CacheMacro::initRuntime($this);'];
		}

		return null;
	}

	public function nodeOpened(Latte\MacroNode $node)
	{
		if ($node->modifiers !== '') {
			throw new Latte\CompileException('Modifiers are not allowed in ' . $node->getNotation());
		}

		$this->used = true;
		$node->empty = false;
		$node->openingCode = Latte\PhpWriter::using($node)
			->write(
				'<?php if (Nette\Bridges\CacheLatte\CacheMacro::createCache($this->global->cacheStorage, %var, $this->global->cacheStack, %node.array?)) /* line %var */ try { ?>',
				'latte-analysis-cache-' . $this->keyCounter++,
				$node->startLine,
			);

		return null;
	}

	public function nodeClosed(Latte\MacroNode $node)
	{
		$node->closingCode = Latte\PhpWriter::using($node)
			->write(
				'<?php
				Nette\Bridges\CacheLatte\CacheMacro::endCache($this->global->cacheStack, %node.array?) /* line %var */;
				} catch (\Throwable $ʟ_e) {
					Nette\Bridges\CacheLatte\CacheMacro::rollback($this->global->cacheStack); throw $ʟ_e;
				} ?>',
				$node->startLine,
			);
	}

}
