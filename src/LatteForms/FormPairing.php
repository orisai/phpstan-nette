<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\LatteForms;

use Nette\Forms\Form as NetteForm;
use OriPhpstan\Nette\Forms\Index\IndexShapeResolver;
use OriPhpstan\Nette\Forms\Shape\FormShape;
use OriPhpstan\Nette\Latte\Bridge\Discovery\DiscoveryStore;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use function array_keys;
use function ltrim;

// The join, and the bridge's whole cross-extension read: a template's linked renderer classes come
// from the Latte discovery store, each (renderer, form component) pair resolves through the Forms
// index, and the same index answers whether that component is mutated from outside its builder.
// Three methods on two services, nothing else; both extensions stay unaware of this package.
//
// An empty formsFor() result means nothing is resolvable - the template is linked to no renderer, no
// renderer declares the component, or every one of them resolves to something that is not a form.
// The caller stays silent on all three; they are indistinguishable by design, because none of them is
// evidence about the template. renderersProvingNoForm() and hasUnresolvedRenderer() are the two
// questions that need them told apart, and both answer only from the state of EVERY linked renderer.
final class FormPairing
{

	private const KIND_FORM = 'form';

	private const KIND_NOT_FORM = 'notForm';

	private const KIND_UNRESOLVED = 'unresolved';

	private DiscoveryStore $store;

	private IndexShapeResolver $resolver;

	/** @var array<string, array{forms: list<ResolvedForm>, notForm: list<string>, unresolved: bool}> */
	private array $memo = [];

	public function __construct(DiscoveryStore $store, IndexShapeResolver $resolver)
	{
		$this->store = $store;
		$this->resolver = $resolver;
	}

	// The registered-form walk needs a live analysis scope, borrowed the same way ShadowDivergenceRule
	// borrows one at the aggregate stage. Routed through here so the bridge's whole cross-extension
	// surface stays on this one class.
	public function bindScope(Scope $scope): void
	{
		$this->resolver->bindScope($scope);
	}

	/**
	 * @return list<ResolvedForm>
	 */
	public function formsFor(string $templateRelPath, string $formName): array
	{
		return $this->resolve($templateRelPath, $formName)['forms'];
	}

	// Whether some linked renderer left the component unresolved - it declares no such component, or
	// one whose class the analyser cannot place. That path may render this template with a form of its
	// own, so the forms that DID resolve are a subset, never the whole truth: a caller about to prove
	// a name absent from all of them must ask this first. The same invariant renderersProvingNoForm()
	// carries, one level up from lookup()'s own UNRESOLVED answer.
	public function hasUnresolvedRenderer(string $templateRelPath, string $formName): bool
	{
		return $this->resolve($templateRelPath, $formName)['unresolved'];
	}

	// The linked renderer classes on which the named component is provably NOT a form. Empty unless
	// EVERY linked renderer resolved the component: one renderer the analyser could not answer for
	// leaves the component's existence unproven, and one renderer on which it IS a form makes the
	// macro legitimate. So a non-empty answer means the name can never render, on any linked path.

	/**
	 * @return list<string>
	 */
	public function renderersProvingNoForm(string $templateRelPath, string $formName): array
	{
		$resolved = $this->resolve($templateRelPath, $formName);
		if ($resolved['unresolved'] || $resolved['forms'] !== []) {
			return [];
		}

		return $resolved['notForm'];
	}

	/**
	 * @return array{forms: list<ResolvedForm>, notForm: list<string>, unresolved: bool}
	 */
	private function resolve(string $templateRelPath, string $formName): array
	{
		// IndexShapeResolver memoises resolveMethodParam() but not classComponentShape(), and a rule
		// asks for the same (template, form) once per reference - the same reason ContainerModel
		// keeps a classComponentShape memo of its own.
		$key = $templateRelPath . "\0" . $formName;
		if (isset($this->memo[$key])) {
			return $this->memo[$key];
		}

		$classes = [];
		foreach ($this->store->recordsForTemplate($templateRelPath) as $record) {
			$classes[$record['class']] = true;
		}

		$forms = [];
		$notForm = [];
		$unresolved = false;
		foreach (array_keys($classes) as $class) {
			$shape = $this->resolver->classComponentShape($class, $formName);
			if ($shape === null) {
				$unresolved = true;

				continue;
			}

			$kind = self::classify($shape);
			if ($kind === self::KIND_UNRESOLVED) {
				$unresolved = true;

				continue;
			}

			if ($kind === self::KIND_NOT_FORM) {
				$notForm[] = $class;

				continue;
			}

			$forms[] = new ResolvedForm(
				$class,
				$formName,
				$shape,
				fn (?string $ownerClass, string $componentName): bool
					=> $this->resolver->componentMayBeMutatedExternally($ownerClass, $componentName),
			);
		}

		$resolved = ['forms' => $forms, 'notForm' => $notForm, 'unresolved' => $unresolved];
		$this->memo[$key] = $resolved;

		return $resolved;
	}

	// {form X} and {formContext X} both hand the component to vendor's renderFormBegin(Form $form),
	// so a resolvable component that is a plain container (or any other component) is not what the
	// macro addresses and contributes nothing.
	//
	// NOT_FORM needs a definite NO, never merely "not yes". IndexShapeResolver falls back to the
	// builder's DECLARED return class, which its own note calls "equal or wider than the store's
	// runtime class", so a component typed Nette\Forms\Container may hand back a real Form at runtime
	// - and PHPStan answers MAYBE for exactly that pair, which is not evidence of anything. A class
	// name the reflector cannot place answers maybe too, and lands on UNRESOLVED for the same reason.

	/**
	 * @return self::KIND_*
	 */
	private static function classify(FormShape $shape): string
	{
		$className = $shape->getClassName();
		if ($className === null) {
			return self::KIND_UNRESOLVED;
		}

		$form = new ObjectType(NetteForm::class);
		$isForm = $form->isSuperTypeOf(new ObjectType(ltrim($className, '\\')));

		if ($isForm->yes()) {
			return self::KIND_FORM;
		}

		return $isForm->no() ? self::KIND_NOT_FORM : self::KIND_UNRESOLVED;
	}

}
