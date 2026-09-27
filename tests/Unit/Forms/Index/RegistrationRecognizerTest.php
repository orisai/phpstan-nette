<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Unit\Forms\Index;

use OriPhpstan\Nette\Forms\Index\RegistrationFact;
use OriPhpstan\Nette\Forms\Index\RegistrationRecognizer;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Tests\OriPhpstan\Nette\Toolkit\BaseTestCase;
use function array_filter;
use function array_values;

final class RegistrationRecognizerTest extends BaseTestCase
{

	private const SAMPLE = <<<'PHP'
<?php
class Sample
{

	public function createComponentOrder()
	{
		$form = new Form();
		$form->onSuccess[] = [$this, 'orderSucceeded'];

		return $form;
	}

	public function createComponentValidate()
	{
		$form = new Form();
		$form->onValidate = [$this, 'validateOrder'];

		return $form;
	}

	public function createComponentOtherForm()
	{
		$form = new Form();
		$other = new Form();
		$other->onSuccess[] = [$this, 'handleOther'];

		return $form;
	}

	public function createComponentDynamic()
	{
		$form = new Form();
		$name = 'x';
		$form->onSuccess[] = [$this, $name];

		return $form;
	}

	public function createComponentClosure()
	{
		$form = new Form();
		$form->onSuccess[] = function () {};

		return $form;
	}

	public function createComponentNonEvent()
	{
		$form = new Form();
		$form->handlers[] = [$this, 'orderSucceeded'];

		return $form;
	}

	public function buildForm(): Form
	{
		$form = new Form();
		$form->onSuccess[] = [$this, 'built'];

		return $form;
	}

	public function createComponentClosureRebind()
	{
		$form = new Form();
		$form->onSuccess[] = function () use ($form) {
			$form->onError = [$this, 'closureInner'];
		};

		return $form;
	}

	public function passesFormParam(Form $form): void
	{
		$this->fillForm($form);
	}

	public function passesScalarParam(string $name): void
	{
		$this->fillForm($name);
	}

	public function dynamicCallee(Form $form): void
	{
		$method = 'fillForm';
		$this->$method($form);
	}

	public function passesLocalVar(): void
	{
		$form = new Form();
		$this->fillForm($form);
	}

	public function passesToOtherReceiver(Form $form): void
	{
		$this->helper->fillForm($form);
	}

	public function passesInClosure(Form $form): void
	{
		$fn = function () use ($form): void {
			$this->fillForm($form);
		};
	}

	public function passesClassComponent(): void
	{
		$this->fillForm($this['order']);
	}

	public function passesDynamicOffsetComponent(): void
	{
		$name = 'order';
		$this->fillForm($this[$name]);
	}

}
PHP;

	public function testEventPropertyAssignYieldsFactWithAllSixFields(): void
	{
		$facts = (new RegistrationRecognizer())->eventRegistrations(
			$this->method('createComponentOrder'),
			'App\\Sample',
			'form',
		);

		self::assertCount(1, $facts);
		$fact = $facts[0];
		self::assertSame(RegistrationFact::KIND_EVENT_HANDLER, $fact->getKind());
		self::assertSame('App\\Sample', $fact->getRegisteringClass());
		self::assertSame('createComponentOrder', $fact->getRegisteringMethod());
		self::assertSame('form', $fact->getFormVar());
		self::assertSame('App\\Sample', $fact->getHandlerClass());
		self::assertSame('orderSucceeded', $fact->getHandlerMethod());
		self::assertSame('onSuccess', $fact->getEventProperty());
	}

	public function testFactRoundTripsThroughArraySerialization(): void
	{
		$facts = (new RegistrationRecognizer())->eventRegistrations(
			$this->method('createComponentOrder'),
			'App\\Sample',
			'form',
		);
		self::assertCount(1, $facts);

		$restored = RegistrationFact::fromArray($facts[0]->toArray());

		self::assertSame(RegistrationFact::KIND_EVENT_HANDLER, $restored->getKind());
		self::assertSame('App\\Sample', $restored->getRegisteringClass());
		self::assertSame('createComponentOrder', $restored->getRegisteringMethod());
		self::assertSame('form', $restored->getFormVar());
		self::assertSame('App\\Sample', $restored->getHandlerClass());
		self::assertSame('orderSucceeded', $restored->getHandlerMethod());
		self::assertSame('onSuccess', $restored->getEventProperty());
	}

	public function testDirectEventPropertyAssignIsRecognized(): void
	{
		$facts = (new RegistrationRecognizer())->eventRegistrations(
			$this->method('createComponentValidate'),
			'App\\Sample',
			'form',
		);

		self::assertCount(1, $facts);
		self::assertSame('validateOrder', $facts[0]->getHandlerMethod());
		self::assertSame('onValidate', $facts[0]->getEventProperty());
	}

	public function testHandlerOnDifferentLocalFormDoesNotAttribute(): void
	{
		$facts = (new RegistrationRecognizer())->eventRegistrations(
			$this->method('createComponentOtherForm'),
			'App\\Sample',
			'form',
		);

		self::assertSame([], $facts);
	}

	public function testDynamicMethodNameYieldsNoFact(): void
	{
		$facts = (new RegistrationRecognizer())->eventRegistrations(
			$this->method('createComponentDynamic'),
			'App\\Sample',
			'form',
		);

		self::assertSame([], $facts);
	}

	public function testClosureHandlerIsIgnored(): void
	{
		$facts = (new RegistrationRecognizer())->eventRegistrations(
			$this->method('createComponentClosure'),
			'App\\Sample',
			'form',
		);

		self::assertSame([], $facts);
	}

	public function testNonEventPropertyAssignYieldsNoFact(): void
	{
		$facts = (new RegistrationRecognizer())->eventRegistrations(
			$this->method('createComponentNonEvent'),
			'App\\Sample',
			'form',
		);

		self::assertSame([], $facts);
	}

	public function testArrayCallableOnReturnedReceiverYieldsFact(): void
	{
		$facts = (new RegistrationRecognizer())->arrayCallableRegistrations(
			$this->method('createComponentOrder'),
			'App\\Sample',
		);

		self::assertCount(1, $facts);
		$fact = $facts[0];
		self::assertSame(RegistrationFact::KIND_EVENT_HANDLER, $fact->getKind());
		self::assertSame('App\\Sample', $fact->getRegisteringClass());
		self::assertSame('createComponentOrder', $fact->getRegisteringMethod());
		self::assertSame('form', $fact->getFormVar());
		self::assertSame('App\\Sample', $fact->getHandlerClass());
		self::assertSame('orderSucceeded', $fact->getHandlerMethod());
		self::assertSame('onSuccess', $fact->getEventProperty());
	}

	public function testArrayCallableDirectAssignIsRecognized(): void
	{
		$facts = (new RegistrationRecognizer())->arrayCallableRegistrations(
			$this->method('createComponentValidate'),
			'App\\Sample',
		);

		self::assertCount(1, $facts);
		self::assertSame('validateOrder', $facts[0]->getHandlerMethod());
		self::assertSame('onValidate', $facts[0]->getEventProperty());
	}

	public function testArrayCallableReceiverNotReturnedYieldsNoFact(): void
	{
		$facts = (new RegistrationRecognizer())->arrayCallableRegistrations(
			$this->method('createComponentOtherForm'),
			'App\\Sample',
		);

		self::assertSame([], $facts);
	}

	public function testArrayCallableDynamicMethodNameYieldsNoFact(): void
	{
		$facts = (new RegistrationRecognizer())->arrayCallableRegistrations(
			$this->method('createComponentDynamic'),
			'App\\Sample',
		);

		self::assertSame([], $facts);
	}

	public function testArrayCallableClosureHandlerYieldsNoFact(): void
	{
		$facts = (new RegistrationRecognizer())->arrayCallableRegistrations(
			$this->method('createComponentClosure'),
			'App\\Sample',
		);

		self::assertSame([], $facts);
	}

	public function testArrayCallableNonEventPropertyYieldsNoFact(): void
	{
		$facts = (new RegistrationRecognizer())->arrayCallableRegistrations(
			$this->method('createComponentNonEvent'),
			'App\\Sample',
		);

		self::assertSame([], $facts);
	}

	public function testArrayCallableNonCreateComponentMethodYieldsNoFact(): void
	{
		$facts = (new RegistrationRecognizer())->arrayCallableRegistrations(
			$this->method('buildForm'),
			'App\\Sample',
		);

		self::assertSame([], $facts);
	}

	public function testArrayCallableClosureInnerAssignYieldsNoFact(): void
	{
		$facts = (new RegistrationRecognizer())->arrayCallableRegistrations(
			$this->method('createComponentClosureRebind'),
			'App\\Sample',
		);

		self::assertSame([], $facts);
	}

	public function testEventRegistrationsClosureInnerAssignYieldsNoFact(): void
	{
		$facts = (new RegistrationRecognizer())->eventRegistrations(
			$this->method('createComponentClosureRebind'),
			'App\\Sample',
			'form',
		);

		self::assertSame([], $facts);
	}

	public function testPassThroughFromFormParamYieldsEdge(): void
	{
		$edges = (new RegistrationRecognizer())->passThroughEdges(
			$this->method('passesFormParam'),
			'App\\Sample',
		);

		self::assertCount(1, $edges);
		$edge = $edges[0];
		self::assertSame(RegistrationFact::KIND_PARAM_PASS_THROUGH, $edge->getKind());
		self::assertSame('App\\Sample', $edge->getCallerClass());
		self::assertSame('passesFormParam', $edge->getCallerMethod());
		self::assertSame(0, $edge->getCallerOrigin());
		self::assertSame('App\\Sample', $edge->getCalleeClass());
		self::assertSame('fillForm', $edge->getCalleeMethod());
		self::assertSame(0, $edge->getCalleeParamIdx());
	}

	public function testPassThroughNonFormParamYieldsNoEdge(): void
	{
		$edges = (new RegistrationRecognizer())->passThroughEdges(
			$this->method('passesScalarParam'),
			'App\\Sample',
		);

		self::assertSame([], $edges);
	}

	public function testPassThroughDynamicCalleeYieldsNoEdge(): void
	{
		$edges = (new RegistrationRecognizer())->passThroughEdges(
			$this->method('dynamicCallee'),
			'App\\Sample',
		);

		self::assertSame([], $edges);
	}

	public function testPassThroughLocalVarYieldsNoEdge(): void
	{
		$edges = (new RegistrationRecognizer())->passThroughEdges(
			$this->method('passesLocalVar'),
			'App\\Sample',
		);

		self::assertSame([], $edges);
	}

	public function testPassThroughNonThisReceiverYieldsNoEdge(): void
	{
		$edges = (new RegistrationRecognizer())->passThroughEdges(
			$this->method('passesToOtherReceiver'),
			'App\\Sample',
		);

		self::assertSame([], $edges);
	}

	public function testPassThroughClosureInnerCallYieldsNoEdge(): void
	{
		$edges = (new RegistrationRecognizer())->passThroughEdges(
			$this->method('passesInClosure'),
			'App\\Sample',
		);

		self::assertSame([], $edges);
	}

	public function testPassThroughClassComponentArgYieldsStringOriginEdge(): void
	{
		$edges = (new RegistrationRecognizer())->passThroughEdges(
			$this->method('passesClassComponent'),
			'App\\Sample',
		);

		self::assertCount(1, $edges);
		$edge = $edges[0];
		self::assertSame(RegistrationFact::KIND_PARAM_PASS_THROUGH, $edge->getKind());
		self::assertSame('App\\Sample', $edge->getCallerClass());
		self::assertSame('passesClassComponent', $edge->getCallerMethod());
		self::assertSame('order', $edge->getCallerOrigin(), 'the component name is the string origin');
		self::assertSame('App\\Sample', $edge->getCalleeClass());
		self::assertSame('fillForm', $edge->getCalleeMethod());
		self::assertSame(0, $edge->getCalleeParamIdx());
	}

	public function testPassThroughDynamicOffsetComponentYieldsNoEdge(): void
	{
		$edges = (new RegistrationRecognizer())->passThroughEdges(
			$this->method('passesDynamicOffsetComponent'),
			'App\\Sample',
		);

		self::assertSame([], $edges, 'a non-literal $this[$var] offset is a preserved blind spot');
	}

	public function testParamPassThroughRoundTripsWithIntOrigin(): void
	{
		$fact = RegistrationFact::paramPassThrough('App\\Caller', 'build', 0, 'App\\Callee', 'fill', 1);
		$restored = RegistrationFact::fromArray($fact->toArray());

		self::assertSame(RegistrationFact::KIND_PARAM_PASS_THROUGH, $restored->getKind());
		self::assertSame('App\\Caller', $restored->getCallerClass());
		self::assertSame('build', $restored->getCallerMethod());
		self::assertSame(0, $restored->getCallerOrigin());
		self::assertSame('App\\Callee', $restored->getCalleeClass());
		self::assertSame('fill', $restored->getCalleeMethod());
		self::assertSame(1, $restored->getCalleeParamIdx());
	}

	public function testParamPassThroughRoundTripsWithStringOrigin(): void
	{
		$fact = RegistrationFact::paramPassThrough('App\\Caller', 'build', 'formSite', 'App\\Callee', 'fill', 2);
		$restored = RegistrationFact::fromArray($fact->toArray());

		self::assertSame('formSite', $restored->getCallerOrigin());
		self::assertSame(2, $restored->getCalleeParamIdx());
	}

	private function method(string $name): ClassMethod
	{
		$parser = (new ParserFactory())->createForNewestSupportedVersion();
		$stmts = $parser->parse(self::SAMPLE);
		self::assertNotNull($stmts);

		$methods = array_values(array_filter(
			(new NodeFinder())->findInstanceOf($stmts, ClassMethod::class),
			static fn (ClassMethod $m): bool => $m->name->toString() === $name,
		));
		$method = $methods[0] ?? null;
		self::assertInstanceOf(ClassMethod::class, $method);

		return $method;
	}

}
