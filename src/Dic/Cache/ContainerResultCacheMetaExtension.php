<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Dic\Cache;

use LogicException;
use OriPhpstan\Nette\Configuration\ConfigurationGuard;
use OriPhpstan\Nette\Configuration\InvalidConfiguration;
use OriPhpstan\Nette\Dic\Metadata\MultiContainerRegistry;
use OriPhpstan\Nette\Support\PhpstanRuntimeStubs;
use PHPStan\Analyser\ResultCache\ResultCacheMetaExtension;
use function hash;
use function hash_file;
use function implode;
use function sprintf;

final class ContainerResultCacheMetaExtension implements ResultCacheMetaExtension
{

	private ConfigurationGuard $guard;

	private MultiContainerRegistry $registry;

	public function __construct(ConfigurationGuard $guard, MultiContainerRegistry $registry)
	{
		$this->guard = $guard;
		$this->registry = $registry;
	}

	public function getKey(): string
	{
		return 'orisaiNette.dic.containers';
	}

	public function getHash(): string
	{
		PhpstanRuntimeStubs::ensureLoaded();

		try {
			$this->guard->validate();
		} catch (InvalidConfiguration $e) {
			// Hashes are read before analysis, where an exception surfaces as a raw console crash;
			// the rules validate too and report the message properly.
			return 'invalid';
		}

		if (!$this->registry->isActive()) {
			return 'inactive';
		}

		$parts = [];

		// Keyed by profile and in loader order: the profile names and their order are analysed state
		// (the rules print them), so a digest over the file set alone leaves a renamed, duplicated or
		// reordered profile serving cached messages that name profiles which no longer exist.
		foreach ($this->registry->getContainerFilesByProfile() as $profile => $path) {
			$fileHash = hash_file('sha256', $path);

			if ($fileHash === false) {
				throw new LogicException(sprintf('Container file "%s" cannot be hashed.', $path));
			}

			$parts[] = $profile . ':' . $path . ':' . $fileHash;
		}

		return hash('sha256', implode("\n", $parts));
	}

}
