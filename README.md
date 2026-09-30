<h1 align="center">
	<img src="https://github.com/orisai/.github/blob/main/images/repo_title.png?raw=true" alt="Orisai"/>
	<br/>
	PHPStan Nette
</h1>

<p align="center">
    PHPStan understands Nette DI containers, Nette Forms, Latte templates and how they connect
</p>

<p align="center">
	📄 Check out our <a href="docs/README.md">documentation</a>.
</p>

<p align="center">
	💸 If you like Orisai, please <a href="https://orisai.dev/sponsor">make a donation</a>. Thank you!
</p>

<p align="center">
	<a href="https://github.com/orisai/phpstan-nette/actions?query=workflow:CI+branch:v1.x"><img src="https://github.com/orisai/phpstan-nette/actions/workflows/ci.yaml/badge.svg?branch=v1.x"></a>
	<a href="https://coveralls.io/github/orisai/phpstan-nette?branch=v1.x"><img src="https://badgen.net/coveralls/c/github/orisai/phpstan-nette/v1.x?cache=300"></a>
	<a href="https://packagist.org/packages/orisai/phpstan-nette"><img src="https://badgen.net/packagist/dt/orisai/phpstan-nette?cache=3600"></a>
	<a href="https://packagist.org/packages/orisai/phpstan-nette"><img src="https://badgen.net/packagist/v/orisai/phpstan-nette?cache=3600"></a>
	<a href="https://choosealicense.com/licenses/mpl-2.0/"><img src="https://badgen.net/badge/license/MPL-2.0/blue?cache=3600"></a>
</p>

##

```neon
parameters:
	# with phpstan/phpstan-nette: patch it and switch its extensions off (see docs); without it, remove these three lines
	netteComponentModelDynamicReturnType: false
	netteFormContainerValuesDynamicReturnType: false
	netteServiceLocatorDynamicReturnType: false
	fileExtensions: [php, latte]
	orisai:
		nette:
			latte:
				enabled: true
			dic:
				containerLoader: %currentWorkingDirectory%/tests/phpstan/container-loader.php
```

```php
// tests/phpstan/container-loader.php
return App\Bootstrap::boot()->createContainer();
```

... and [more](docs/README.md).
