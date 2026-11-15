<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App\Filters;

trait DescriptionTrait
{

	public function formatDescription(string $description): string
	{
		return $description;
	}

	private function secret(int $i, string ...$rest): ?self
	{
		return null;
	}

	public function startup(): void
	{
		parent::startup();
		$template = $this->getTemplate();
		$template->addFilter('formatDescription', [$this, 'formatDescription']);
		$template->addFilter('secret', [$this, 'secret']);
	}

}
