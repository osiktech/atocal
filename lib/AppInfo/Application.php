<?php

declare(strict_types=1);

namespace OCA\JitsiCalendar\AppInfo;

use OCA\JitsiCalendar\Controller\CalendarController;
use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;

class Application extends App
{
    public const APP_ID = 'jitsi_calendar';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);

        $container = $this->getContainer();

        $container->registerService(
            CalendarController::class,
            function (IAppContainer $c): CalendarController {
                return new CalendarController(
                    self::APP_ID,
                    $c->get('Request'),
                    $c->get(\OCP\Calendar\IManager::class),
                    $c->get(\OCP\IUserSession::class),
                    $c->get(\OCP\IURLGenerator::class)
                );
            }
        );
    }
}