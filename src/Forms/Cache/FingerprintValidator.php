<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Forms\Cache;

/**
 * Resolves the current contributor fingerprint for an interprocedural key so DependencyRecorder can
 * validate an embedding entry by refolding the registration universe. RegistrationIndex implements
 * this; it is a setter-injected dependency of the recorder to break the recorder → cache → index
 * construction cycle.
 */
interface FingerprintValidator
{

	/**
	 * The contributor fingerprint for $ipKey computed from the current universe, or null when the
	 * universe cannot be enumerated/folded — the sound failure mode: an unverifiable fingerprint
	 * invalidates the embedding entry rather than serving it against an unknowable contributor set.
	 */
	public function currentFingerprint(string $ipKey): ?string;

}
