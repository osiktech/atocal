<?php

declare(strict_types=1);

namespace OCA\AToCal\Tests\Unit\AppInfo;

use OCA\AToCal\AppInfo\Application;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ApplicationTest extends TestCase
{
	public function testSupportsZeroArgumentConstruction(): void {
		/*
		 * The server container instantiates the app class without arguments,
		 * see ServerContainer::getAppContainer(). The app class must pass the
		 * app id to App::__construct() itself.
		 */
		$constructor = new ReflectionMethod(Application::class, '__construct');

		$this->assertSame(0, $constructor->getNumberOfRequiredParameters());
	}
}
