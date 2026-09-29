<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

use Nette\Bridges\ApplicationLatte\Template;
use Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\Outside\LegacyBaseControlReplica;
use function assert;

// Mirrors the app's legacy base controls' own createTemplate() override:
// parent::createTemplate() into a local, then setFile()/dynamic-property writes on that
// local - the real source of the app's KIND_CONVENTION setFile sites, and (via the
// $template->currency shape) the real ReservationsControl assignment pattern.
final class ParentCreateTemplateConventionFixture extends LegacyBaseControlReplica
{

	public function createTemplate(?string $class = null): Template
	{
		$template = parent::createTemplate();
		assert($template instanceof Template);
		$template->setFile($this->getTemplateFilePath());
		$template->currency = 'CZK';

		return $template;
	}

	private function getTemplateFilePath(): string
	{
		return __DIR__ . '/parent-convention.latte';
	}

}
