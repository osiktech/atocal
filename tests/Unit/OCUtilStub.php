<?php

declare(strict_types=1);

/*
 * Minimal stand-in for the server class OC_Util, which is not part
 * of the OCP vendor package. Records addStyle() calls for assertions.
 */
if (!class_exists(\OC_Util::class, false)) {
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
