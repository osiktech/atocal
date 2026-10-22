<?php

declare(strict_types=1);

namespace OCA\AToCal\AppInfo;

use OCP\AppFramework\App;

class Application extends App
{
    public const APP_ID = 'atocal';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }
}