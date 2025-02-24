<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Bridges\ApplicationDI;

use Composer\Autoload\ClassLoader;
use Nette;
use Nette\Application\UI;
use Nette\DI\Definitions;
use Nette\Schema\Expect;
use Tracy;


/**
 * Application extension for Nette DI.
 */
final class ApplicationExtension extends Nette\DI\CompilerExtension
{
	/** @var bool */
	private $debugMode;

	/** @var array */
	private $scanDirs;

	/** @var Nette\Loaders\RobotLoader|null */
	private $robotLoader;

	/** @var int */
	private $invalidLinkMode;

	/** @var string|null */
	private $tempDir;


	public function __construct(bool $debugMode = false, array $scanDirs = null, string $tempDir = null, Nette\Loaders\RobotLoader $robotLoader = null)
	{
		$this->debugMode = $debugMode;
		$this->scanDirs = (array) $scanDirs;
		$this->tempDir = $tempDir;
		$this->robotLoader = $robotLoader;
	}


	public function getConfigSchema(): Nette\Schema\Schema
	{
		return Expect::structure([
			'debugger' => Expect::bool(interface_exists(Tracy\IBarPanel::class)),
			'errorPresenter' => Expect::string('Nette:Error')->dynamic(),
			'catchExceptions' => Expect::bool()->dynamic(),
			'mapping' => Expect::arrayOf('string|array'),
			'scanDirs' => Expect::anyOf(Expect::arrayOf('string'), false)->default($this->scanDirs),
			'scanComposer' => Expect::bool(class_exists(ClassLoader::class)),
			'scanFilter' => Expect::string('Presenter'),
			'silentLinks' => Expect::bool(),
		]);
	}


	public function loadConfiguration()
	{
		$config = $this->config;
		$builder = $this->getContainerBuilder();
		$builder->addExcludedClasses([UI\Presenter::class]);

		$this->invalidLinkMode = $this->debugMode
			? UI\Presenter::INVALID_LINK_TEXTUAL | ($config->silentLinks ? 0 : UI\Presenter::INVALID_LINK_WARNING)
			: UI\Presenter::INVALID_LINK_WARNING;

		$application = $builder->addDefinition($this->prefix('application'))
			->setFactory(Nette\Application\Application::class)
			->addSetup('$catchExceptions', [$this->debugMode ? $config->catchExceptions : true])
			->addSetup('$errorPresenter', [$config->errorPresenter]);

		if ($config->debugger) {
			$application->addSetup([Nette\Bridges\ApplicationTracy\RoutingPanel::class, 'initializePanel']);
		}
		$this->compiler->addExportedType(Nette\Application\Application::class);

		if ($this->debugMode && ($config->scanDirs || $this->robotLoader) && $this->tempDir) {
			$touch = $this->tempDir . '/touch';
			Nette\Utils\FileSystem::createDir($this->tempDir);
			$this->getContainerBuilder()->addDependency($touch);
		}
		$presenterFactory = $builder->addDefinition($this->prefix('presenterFactory'))
			->setType(Nette\Application\IPresenterFactory::class)
			->setFactory(Nette\Application\PresenterFactory::class, [new Definitions\Statement(
				Nette\Bridges\ApplicationDI\PresenterFactoryCallback::class, [1 => $this->invalidLinkMode, $touch ?? null]
			)]);

		if ($config->mapping) {
			$presenterFactory->addSetup('setMapping', [$config->mapping]);
		}

		$builder->addDefinition($this->prefix('linkGenerator'))
			->setFactory(Nette\Application\LinkGenerator::class, [
				1 => new Definitions\Statement([new Definitions\Statement('@Nette\Http\IRequest::getUrl'), 'withoutUserInfo']),
			]);

		if ($this->name === 'application') {
			$builder->addAlias('application', $this->prefix('application'));
			$builder->addAlias('nette.presenterFactory', $this->prefix('presenterFactory'));
		}
	}


	public function beforeCompile()
	{
		$builder = $this->getContainerBuilder();
		$all = [];

		foreach ($builder->findByType(Nette\Application\IPresenter::class) as $def) {
			$all[$def->getType()] = $def;
		}

		$counter = 0;
		foreach ($this->findPresenters() as $class) {
			if (empty($all[$class])) {
				$all[$class] = $builder->addDefinition($this->prefix((string) ++$counter))
					->setType($class);
			}
		}

		foreach ($all as $def) {
			$def->addTag(Nette\DI\Extensions\InjectExtension::TAG_INJECT)
				->setAutowired(false);

			if (is_subclass_of($def->getType(), UI\Presenter::class) && $def instanceof Definitions\ServiceDefinition) {
				$def->addSetup('$invalidLinkMode', [$this->invalidLinkMode]);
			}
			$this->compiler->addExportedType($def->getType());
		}
	}


	private function findPresenters(): array
	{
		$config = $this->getConfig();

		if ($config->scanDirs) {
			if (!class_exists(Nette\Loaders\RobotLoader::class)) {
				throw new Nette\NotSupportedException("RobotLoader is required to find presenters, install package `nette/robot-loader` or disable option {$this->prefix('scanDirs')}: false");
			}
			$robot = new Nette\Loaders\RobotLoader;
			$robot->addDirectory(...$config->scanDirs);
			$robot->acceptFiles = ['*' . $config->scanFilter . '*.php'];
			if ($this->tempDir) {
				$robot->setTempDirectory($this->tempDir);
				$robot->refresh();
			} else {
				$robot->rebuild();
			}

		} elseif ($this->robotLoader && $config->scanDirs !== false) {
			$robot = $this->robotLoader;
			$robot->refresh();
		}

		$classes = [];
		if (isset($robot)) {
			$classes = array_keys($robot->getIndexedClasses());
		}

		if ($config->scanComposer) {
			$rc = new \ReflectionClass(ClassLoader::class);
			$classFile = dirname($rc->getFileName()) . '/autoload_classmap.php';
			if (is_file($classFile)) {
				$this->getContainerBuilder()->addDependency($classFile);
				$classes = array_merge($classes, array_keys((function ($path) {
					return require $path;
				})($classFile)));
			}
		}

		$presenters = [];
		foreach (array_unique($classes) as $class) {
			if (
				strpos($class, $config->scanFilter) !== false
				&& class_exists($class)
				&& ($rc = new \ReflectionClass($class))
				&& $rc->implementsInterface(Nette\Application\IPresenter::class)
				&& !$rc->isAbstract()
			) {
				$presenters[] = $rc->getName();
			}
		}
		return $presenters;
	}
}
