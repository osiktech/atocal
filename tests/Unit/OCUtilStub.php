<?php

declare(strict_types=1);

/*
 * Minimal stand-ins for server classes that are not part of
 * the OCP vendor package. OC_Util records addStyle() calls,
 * the OC classes satisfy the lookups that OCP\Util::addScript()
 * triggers for app scripts.
 */
namespace OC {
	class AppScriptDependency
	{
		public function __construct(string $app, array $deps = []) {}

		public function addDep(string $dep): void {}
	}
}

namespace {
	class OC
	{
		public static ?object $server = null;
	}

	class AToCalFakeL10NFactory
	{
		public function findLanguage(string $app): string
		{
			return 'en';
		}
	}

	class AToCalFakeServer
	{
		public function get(string $serviceName): AToCalFakeL10NFactory
		{
			return new AToCalFakeL10NFactory();
		}
	}

	if (!class_exists('OC_Util', false)) {
		class OC_Util
		{
			/** @var list<array{string, ?string, bool}> */
			public static array $styles = [];

			public static function addStyle(
				string $application,
				?string $file = null,
				bool $prepend = false
			): void {
				self::$styles[] = [$application, $file, $prepend];
			}
		}
	}

	OC::$server ??= new AToCalFakeServer();
}
