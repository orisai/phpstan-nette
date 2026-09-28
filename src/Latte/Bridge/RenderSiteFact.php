<?php declare(strict_types = 1);

namespace OriPhpstan\Nette\Latte\Bridge;

final class RenderSiteFact
{

	private bool $fileArgPresent;

	private ?string $literalPath;

	/** @var array{file: string, line: int} */
	private array $site;

	/**
	 * @param array{file: string, line: int} $site
	 */
	public function __construct(bool $fileArgPresent, ?string $literalPath, array $site)
	{
		$this->fileArgPresent = $fileArgPresent;
		$this->literalPath = $literalPath;
		$this->site = $site;
	}

	/**
	 * @param array{fileArgPresent: bool, literalPath: string|null, site: array{file: string, line: int}} $data
	 */
	public static function fromArray(array $data): self
	{
		return new self($data['fileArgPresent'], $data['literalPath'], $data['site']);
	}

	public function hasFileArg(): bool
	{
		return $this->fileArgPresent;
	}

	public function getLiteralPath(): ?string
	{
		return $this->literalPath;
	}

	/**
	 * @return array{file: string, line: int}
	 */
	public function getSite(): array
	{
		return $this->site;
	}

	/**
	 * @return array{fileArgPresent: bool, literalPath: string|null, site: array{file: string, line: int}}
	 */
	public function toArray(): array
	{
		return [
			'fileArgPresent' => $this->fileArgPresent,
			'literalPath' => $this->literalPath,
			'site' => $this->site,
		];
	}

}
