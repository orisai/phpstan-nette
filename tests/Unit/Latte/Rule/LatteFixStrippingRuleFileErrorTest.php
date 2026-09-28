<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Rule;

use OriPhpstan\Nette\Latte\Rule\LatteFixStrippingRule;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PHPStan\Rules\FileRuleError;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use ReflectionMethod;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;

final class LatteFixStrippingRuleFileErrorTest extends BaseTestCase
{

	public function testFileRuleErrorIsPreservedInStrippedError(): void
	{
		$node = new ConstFetch(new Name('true'));
		$originalError = RuleErrorBuilder::message('Test message')
			->identifier('test.fileError')
			->file(__FILE__, 'Test file description')
			->fixNode($node, static fn (): ConstFetch => new ConstFetch(new Name('false')))
			->build();

		$reflectionMethod = new ReflectionMethod(LatteFixStrippingRule::class, 'stripFix');
		$reflectionMethod->setAccessible(true);

		/** @var IdentifierRuleError $strippedError */
		$strippedError = $reflectionMethod->invoke(null, $originalError);

		self::assertInstanceOf(IdentifierRuleError::class, $strippedError);
		self::assertSame('Test message', $strippedError->getMessage());
		self::assertSame('test.fileError', $strippedError->getIdentifier());

		self::assertInstanceOf(FileRuleError::class, $strippedError);
		self::assertSame(__FILE__, $strippedError->getFile());
		self::assertSame('Test file description', $strippedError->getFileDescription());
	}

}
