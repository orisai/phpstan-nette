<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Analyzer;

use Nette\Forms\Container as NetteContainer;
use Nette\Utils\FileSystem;
use OriPhpstan\Nette\Forms\Cache\FormShapeCache;
use OriPhpstan\Nette\Forms\Graph\ClosureScope;
use OriPhpstan\Nette\Forms\Support\BoundedMap;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ExtendedParameterReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\TrinaryLogic;
use PHPStan\Type\ObjectType;
use ReflectionMethod;
use function array_key_first;
use function count;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function ltrim;
use function spl_object_id;
use function strncmp;
use function strtolower;
use function substr;
use function token_get_all;
use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_WHITESPACE;

/**
 * Resolves the callee behind a call that receives the tracked form as an argument, so the walk can
 * fold in what that callee adds instead of opening the shape.
 *
 * Everything it answers is a function of the analysed file's own AST plus reflection — never of the
 * live Scope. That matters twice over: the walk's result is persisted under a key made of the file
 * hash and the function-like, so a scope-dependent answer would let one consumer poison another's
 * entry; and the receiver forms it declines to resolve (a variable, a property fetch) are exactly
 * the ones a foreign scope would type wrongly.
 *
 * The parser MUST be the rich one. PathRoutingParser strips method bodies outside PHPStan's
 * CLI-narrowed analysed files, and a stripped body is an EMPTY body — which is also what a callee
 * that adds nothing looks like, and an empty contribution is trusted as proof that the callee adds
 * nothing. bodyIsFullyVisible() is what keeps those two apart, from the source rather than from the
 * wiring, so a wrong parser here declines rather than manufactures a shape. The rich parser is
 * additionally what makes a non-empty answer possible at all (component-affecting nodes are tagged
 * by rich-parser visitors).
 */
final class CalleeShapeResolver
{

	private const CLASS_INDEX_CACHE_LIMIT = 64;

	private const BLANK_TOKENS = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

	private Parser $richParser;

	private ReflectionProvider $reflectionProvider;

	private FormShapeCache $cache;

	private BoundedMap $classesByFile;

	private ?LocalVariableClassTracker $classTracker = null;

	public function __construct(Parser $richParser, ReflectionProvider $reflectionProvider, FormShapeCache $cache)
	{
		$this->richParser = $richParser;
		$this->reflectionProvider = $reflectionProvider;
		$this->cache = $cache;
		$this->classesByFile = new BoundedMap(self::CLASS_INDEX_CACHE_LIMIT);
	}

	/**
	 * The rich parser this resolver was built with, so a collaborator assembled beside it inherits the
	 * same one rather than being handed a second opinion — the funnel's poisoning rule (one parser per
	 * FormShapeCache) is a property of the whole stack, not of this class alone.
	 */
	public function getRichParser(): Parser
	{
		return $this->richParser;
	}

	/**
	 * The class that lexically encloses the walked function in its own file. Derived from the AST
	 * rather than from the caller-supplied owner FQCN, which may be a subclass that inherits the
	 * method: the walk's cache key names the file and the function-like, so the owner it resolves
	 * calls against has to be the one that key identifies.
	 */
	public function enclosingClassName(?string $file, FunctionLike $functionLike): ?string
	{
		if ($file === null || !is_file($file)) {
			return null;
		}

		$position = $functionLike->getStartFilePos();
		if ($position < 0) {
			return null;
		}

		foreach ($this->fileClasses($file) as $class) {
			if ($position < $class->getStartFilePos() || $position > $class->getEndFilePos()) {
				continue;
			}

			// A trait body names no owner: which class's method table `$this->…` resolves against
			// depends on the using class, which this file does not know.
			if (!$class instanceof Class_ || !isset($class->namespacedName)) {
				return null;
			}

			return $class->namespacedName->toString();
		}

		return null;
	}

	/**
	 * @return array{method: ClassMethod, file: string, paramName: string, paramClass: string}|null
	 */
	public function resolveContainerParam(CallLike $call, int $argIndex, ?string $ownerClass): ?array
	{
		$method = $this->resolveMethod($call, $ownerClass);
		if ($method === null) {
			return null;
		}

		$visible = $this->visibleMethodNode($method);
		if ($visible === null) {
			return null;
		}

		[$node, $file] = $visible;

		$param = $node->params[$argIndex] ?? null;
		if ($param === null
			|| $param->variadic
			|| $param->byRef
			|| !$param->var instanceof Variable
			|| !is_string($param->var->name)
			|| !$param->type instanceof Name
		) {
			return null;
		}

		$paramClass = ltrim($param->type->toString(), '\\');
		if (!$this->reflectionProvider->hasClass($paramClass)
			|| !(new ObjectType(NetteContainer::class))->isSuperTypeOf(new ObjectType($paramClass))->yes()
		) {
			return null;
		}

		$this->cache->recorder()->record($file);

		return ['method' => $node, 'file' => $file, 'paramName' => $param->var->name, 'paramClass' => $paramClass];
	}

	/**
	 * The body of a method a callable NAMES without calling it — `[$this, 'handler']` and its
	 * first-class-callable spelling. No CallLike-shaped resolution reaches those, since there is no
	 * call node to read a receiver off; the class comes from the callable's own first element
	 * instead.
	 */
	public function methodNodeOf(string $className, string $methodName): ?ClassMethod
	{
		$visible = $this->visibleMethodNodeOf($className, $methodName);

		return $visible === null ? null : $visible[0];
	}

	/**
	 * methodNodeOf() with the method's OWN file alongside it — what a caller drawing the
	 * project/vendor line needs, since the line is a property of where the declaration lives and not
	 * of the class name it was reached through.
	 *
	 * @return array{ClassMethod, string}|null
	 */
	public function visibleMethodNodeOf(string $className, string $methodName): ?array
	{
		if (!$this->reflectionProvider->hasClass($className)) {
			return null;
		}

		$class = $this->reflectionProvider->getClass($className);
		if (!$class->hasNativeMethod($methodName)) {
			return null;
		}

		return $this->visibleMethodNode($class->getNativeMethod($methodName));
	}

	/**
	 * The class a method with NO declared return type actually returns, read off its own body.
	 *
	 * PHPStan reflects the DECLARED return type and infers nothing from a body, so an undeclared
	 * return answers `mixed` — and an `add*` helper written that way records a slot that is present
	 * but has no class, though the class is sitting in the body waiting to be read. The reading is
	 * the same scope-free resolution the walk already applies to a form variable's defining site
	 * (LocalVariableClassTracker), so a `new X()`, a local bound to one, a typed parameter and
	 * another method's declared return all resolve.
	 *
	 * Every arm has to agree. A method whose returns disagree, whose last statement is not a return
	 * (so some path falls through to null), or one of whose returns cannot be read at all, answers
	 * null — the caller then leaves the slot exactly as opaque as it found it, which is the right
	 * answer for a body that proves nothing.
	 */
	public function returnedObjectClass(string $className, string $methodName): ?string
	{
		$node = $this->methodNodeOf($className, $methodName);
		if ($node === null) {
			return null;
		}

		$stmts = $node->stmts ?? [];
		$last = $stmts === [] ? null : $stmts[count($stmts) - 1];
		if (!$last instanceof Return_ || $last->expr === null) {
			return null;
		}

		$owner = $this->declaringClassName($className, $methodName);
		$innerIds = ClosureScope::innerNodeIds($stmts);

		$classes = [];
		foreach ((new NodeFinder())->findInstanceOf($stmts, Return_::class) as $return) {
			if (isset($innerIds[spl_object_id($return)])) {
				continue;
			}

			$class = $return->expr === null
				? null
				: $this->classTracker()->resolveExpressionClass($return->expr, $node, $owner);
			if ($class === null) {
				return null;
			}

			$classes[$class] = true;
		}

		return count($classes) === 1 ? array_key_first($classes) : null;
	}

	private function declaringClassName(string $className, string $methodName): ?string
	{
		if (!$this->reflectionProvider->hasClass($className)) {
			return null;
		}

		$class = $this->reflectionProvider->getClass($className);

		return $class->hasNativeMethod($methodName)
			? $class->getNativeMethod($methodName)->getDeclaringClass()->getName()
			: null;
	}

	private function classTracker(): LocalVariableClassTracker
	{
		return $this->classTracker ??= new LocalVariableClassTracker(
			$this->reflectionProvider,
			$this->cache->recorder(),
		);
	}

	/**
	 * The method's own statements, or null when they are not all there. The visibility guard is the
	 * point: a body PathRoutingParser stripped parses as EMPTY, which is also what a method that does
	 * nothing looks like, and every caller here draws a conclusion from an empty body.
	 *
	 * @return array{ClassMethod, string}|null
	 */
	private function visibleMethodNode(ExtendedMethodReflection $method): ?array
	{
		$declaring = $method->getDeclaringClass()->getNativeReflection();
		if (!$declaring->hasMethod($method->getName())) {
			return null;
		}

		$native = $declaring->getMethod($method->getName());
		$file = $native->getFileName();
		if ($file === false || strncmp($file, 'phar://', 7) === 0 || !is_file($file)) {
			return null;
		}

		$node = $this->methodNode($file, $native);
		if ($node === null || $node->stmts === null || !$this->bodyIsFullyVisible($node, $file)) {
			return null;
		}

		$this->cache->recorder()->record($file);

		return [$node, $file];
	}

	/**
	 * PHPStan's own invocation-timing answer for the parameter a callable argument lands on, read
	 * from @param-immediately-invoked-callable / @param-later-invoked-callable exactly as
	 * NodeScopeResolver reads it. MAYBE for every receiver shape this resolver declines to type,
	 * which is the safe side: the walk opens rather than guessing.
	 */
	public function parameterImmediacy(CallLike $call, int $argIndex, ?string $ownerClass): TrinaryLogic
	{
		$parameters = $this->parametersOf($call, $ownerClass);
		if ($parameters === null) {
			return TrinaryLogic::createMaybe();
		}

		$parameter = $parameters[$argIndex] ?? null;
		if ($parameter === null) {
			return TrinaryLogic::createMaybe();
		}

		return $parameter->isImmediatelyInvokedCallable();
	}

	/**
	 * @return list<ExtendedParameterReflection>|null
	 */
	private function parametersOf(CallLike $call, ?string $ownerClass): ?array
	{
		if ($call instanceof FuncCall) {
			if (!$call->name instanceof Name || !$this->reflectionProvider->hasFunction($call->name, null)) {
				return null;
			}

			$variants = $this->reflectionProvider->getFunction($call->name, null)->getVariants();

			return count($variants) === 1 ? $variants[0]->getParameters() : null;
		}

		$method = $this->resolveMethod($call, $ownerClass);
		if ($method === null) {
			return null;
		}

		$variants = $method->getVariants();

		return count($variants) === 1 ? $variants[0]->getParameters() : null;
	}

	private function resolveMethod(CallLike $call, ?string $ownerClass): ?ExtendedMethodReflection
	{
		if (!$call instanceof MethodCall && !$call instanceof StaticCall) {
			return null;
		}

		if (!$call->name instanceof Identifier) {
			return null;
		}

		$class = $this->receiverClass($call, $ownerClass);
		if ($class === null || !$class->hasNativeMethod($call->name->toString())) {
			return null;
		}

		return $class->getNativeMethod($call->name->toString());
	}

	private function receiverClass(CallLike $call, ?string $ownerClass): ?ClassReflection
	{
		if ($call instanceof MethodCall) {
			// Only `$this->…`: any other receiver needs a type, and a type needs either the live
			// Scope (which would make the cached shape scope-dependent) or a class tracker whose
			// answer is a guess when the property is reassigned.
			if (!$call->var instanceof Variable || $call->var->name !== 'this' || $ownerClass === null) {
				return null;
			}

			return $this->reflectionProvider->hasClass($ownerClass)
				? $this->reflectionProvider->getClass($ownerClass)
				: null;
		}

		if (!$call instanceof StaticCall || !$call->class instanceof Name) {
			return null;
		}

		$name = $call->class->toString();
		$lower = strtolower($name);
		if ($lower === 'self' || $lower === 'static' || $lower === 'parent') {
			// `parent::` needs the owner's parent and `static::` needs the runtime class; neither is
			// this walk's to decide, and `self::` gains nothing the instance form does not.
			return null;
		}

		return $this->reflectionProvider->hasClass($name) ? $this->reflectionProvider->getClass($name) : null;
	}

	/**
	 * Whether the statements held for this method are the ones its source actually contains.
	 *
	 * The proof a caller draws from an EMPTY contribution — "the callee adds nothing" — is also
	 * exactly what a body-stripped parse produces, so the two have to be told apart here rather
	 * than trusted to the wiring. PHPStan's CleaningParser, which PathRoutingParser hands every
	 * file outside the CLI-narrowed analysed set, rewrites a method body to a filtered list of
	 * SYNTHESISED Expression statements (closures and yields survive; everything around them does
	 * not), and a synthesised node carries no source position. So a top-level statement without a
	 * position means the body was rewritten, and an empty statement list is believable only when
	 * the source between the body's own braces really is empty.
	 */
	private function bodyIsFullyVisible(ClassMethod $node, string $file): bool
	{
		foreach ($node->stmts ?? [] as $stmt) {
			if ($stmt->getStartFilePos() < 0) {
				return false;
			}
		}

		return $node->stmts !== [] || $this->sourceBodyIsEmpty($node, $file);
	}

	private function sourceBodyIsEmpty(ClassMethod $node, string $file): bool
	{
		$start = $node->getStartFilePos();
		$end = $node->getEndFilePos();
		if ($start < 0 || $end < $start) {
			return false;
		}

		$source = FileSystem::read($file);
		$depth = 0;
		foreach (token_get_all('<?php ' . substr($source, $start, $end - $start + 1)) as $token) {
			if ($token === '{') {
				$depth++;
			} elseif ($token === '}') {
				$depth--;
			} elseif ($depth > 0 && (!is_array($token) || !in_array($token[0], self::BLANK_TOKENS, true))) {
				return false;
			}
		}

		return true;
	}

	private function methodNode(string $file, ReflectionMethod $native): ?ClassMethod
	{
		$startLine = $native->getStartLine();
		if ($startLine === false) {
			return null;
		}

		foreach ($this->fileClasses($file) as $class) {
			foreach ($class->getMethods() as $method) {
				if ($method->getStartLine() === $startLine) {
					return $method;
				}
			}
		}

		return null;
	}

	/**
	 * @return list<ClassLike>
	 */
	private function fileClasses(string $file): array
	{
		if ($this->classesByFile->has($file)) {
			/** @var list<ClassLike> $cached */
			$cached = $this->classesByFile->get($file);

			return $cached;
		}

		$this->cache->recorder()->record($file);

		/** @var list<ClassLike> $classes */
		$classes = (new NodeFinder())->find(
			$this->richParser->parseFile($file),
			static fn (Node $n): bool => $n instanceof ClassLike,
		);
		$this->classesByFile->set($file, $classes);

		return $classes;
	}

}
