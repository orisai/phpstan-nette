<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Latte\Bridge\Fixtures\App;

// Degrade path: a @property-read tag whose type is a union of two classes, never a single
// resolvable class-string.
/**
 * @property-read TemplateClassChannelTargetOne|TemplateClassChannelTargetTwo $template
 */
final class TemplateClassPhpdocUnionTypePresenter
{

}
