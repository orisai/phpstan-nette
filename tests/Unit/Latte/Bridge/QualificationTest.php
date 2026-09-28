<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge;

use OriPhpstan\Nette\Forms\Shape\Certainty;
use OriPhpstan\Nette\Latte\Bridge\PhpRenderFacts;
use OriPhpstan\Nette\Latte\Bridge\Qualification;
use OriPhpstan\Nette\Latte\Bridge\TemplateClassFact;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class QualificationTest extends BaseTestCase
{

	public function testFactsWithAResolvedTemplateClassQualify(): void
	{
		$templateClass = new TemplateClassFact(
			'App\\FooTemplate',
			TemplateClassFact::CHANNEL_TEMPLATE_FLOOR,
			Certainty::HAPPENS,
		);

		self::assertTrue(Qualification::qualifies(new PhpRenderFacts([], [], $templateClass, [], ['/a.php'])));
	}

	public function testFactsWithoutAResolvedTemplateClassDoNotQualify(): void
	{
		self::assertFalse(Qualification::qualifies(PhpRenderFacts::empty()));
		// The non-qualifying walk shape: own file pinned in the read-set, template class left null.
		self::assertFalse(Qualification::qualifies(new PhpRenderFacts([], [], null, [], ['/own.php'])));
	}

}
