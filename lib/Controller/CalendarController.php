<?php

declare(strict_types=1);

namespace OCA\JitsiCalendar\Controller;

use OCA\JitsiCalendar\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IDateTimeZone;

class CalendarController extends Controller
{
    /*
     * Target calendar for created events.
     */
    private const CALENDAR_NAME = 'Personal';

    /*
     * datetime-local format as sent by jitsi-launch,
     * e.g. "2026-10-07T14:15".
     */
    private const DATETIME_LOCAL = 'Y-m-d\TH:i';

    private IManager $calendarManager;
    private IUserSession $userSession;
    private IURLGenerator $urlGenerator;
    private IDateTimeZone $dateTimeZone;

    public function __construct(
        string $appName,
        IRequest $request,
        IManager $calendarManager,
        IUserSession $userSession,
        IURLGenerator $urlGenerator,
        IDateTimeZone $dateTimeZone
    ) {
        parent::__construct($appName, $request);

        $this->calendarManager = $calendarManager;
        $this->userSession = $userSession;
        $this->urlGenerator = $urlGenerator;
        $this->dateTimeZone = $dateTimeZone;
    }

    /**
     * Show event confirmation.
     *
     * The route itself requires an authenticated Nextcloud user.
     * If the user is not authenticated, Nextcloud's normal
     * authentication flow takes over before the controller executes.
     */
    /*
     * NoCSRFRequired: Seite wird per Link aus dem Jitsi-Launcher
     * aufgerufen – der Browser sendet noch kein Request-Token.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function confirm(
        string $summary = '',
        string $description = '',
        string $start = '',
        string $end = '',
        string $organizer = '',
        string $location = '',
        string $url = ''
    ): TemplateResponse {
        $data = $this->validateEventData(
            $summary,
            $description,
            $start,
            $end,
            $organizer,
            $location,
            $url
        );

        return $this->renderConfirm($data);
    }

    /**
     * Create the calendar event.
     */
    /*
     * CSRF bleibt aktiv: Das Formular der Bestätigungsseite
     * sendet das Request-Token mit.
     */
    #[NoAdminRequired]
    public function create(
        string $summary = '',
        string $description = '',
        string $start = '',
        string $end = '',
        string $organizer = '',
        string $location = '',
        string $url = ''
    ): RedirectResponse|TemplateResponse {
        $data = $this->validateEventData(
            $summary,
            $description,
            $start,
            $end,
            $organizer,
            $location,
            $url
        );

        $user = $this->userSession->getUser();

        if ($user === null) {
            throw new \RuntimeException('No authenticated Nextcloud user.');
        }

        $calendar = $this->findWritableCalendar($user->getUID());

        /*
         * Friendly error instead of a hard server error page.
         */
        if ($calendar === null) {
            return $this->renderConfirm(
                $data,
                'No writable calendar named "'
                . self::CALENDAR_NAME
                . '" was found in your account. Create it in the '
                . 'Nextcloud Calendar app, then try again.'
            );
        }

        $this->createEvent($calendar, $data);

        /*
         * Open the Nextcloud Calendar application.
         */
        $calendarUrl = $this->urlGenerator->linkToRoute(
            'calendar.view.index'
        );

        return new RedirectResponse($calendarUrl);
    }

    /**
     * The user's "Personal" calendar.
     *
     * getCalendarsForPrincipal() returns ICalendar objects.
     * Only calendars implementing ICreateFromString are writable.
     */
    private function findWritableCalendar(string $userId): ?ICreateFromString
    {
        /*
         * IManager kennt keine getCalendarsForUser() –
         * Kalender werden über die Principal-URI abgefragt.
         */
        $principalUri = 'principals/users/' . $userId;

        $calendars = $this->calendarManager->getCalendarsForPrincipal($principalUri);

        foreach ($calendars as $calendar) {
            if (!$calendar instanceof ICreateFromString) {
                continue;
            }

            if ($calendar->getDisplayName() === self::CALENDAR_NAME) {
                return $calendar;
            }
        }

        return null;
    }

    /**
     * Render the confirmation page, optionally with an error message.
     */
    private function renderConfirm(array $data, string $error = ''): TemplateResponse
    {
        return new TemplateResponse(
            Application::APP_ID,
            'confirm',
            [
                'summary' => $data['summary'],
                'description' => $data['description'],
                'start' => $data['start']->format(self::DATETIME_LOCAL),
                'end' => $data['end']->format(self::DATETIME_LOCAL),
                'organizer' => $data['organizer'],
                'location' => $data['location'],
                'url' => $data['url'],
                'error' => $error,
                'createUrl' => $this->urlGenerator->linkToRoute(
                    Application::APP_ID . '.calendar.create'
                ),
            ]
        );
    }

    /**
     * Create the event through the platform event builder,
     * which handles escaping and ICS serialization.
     */
    private function createEvent(ICreateFromString $calendar, array $data): void
    {
        $builder = $this->calendarManager->createEventBuilder();

        $builder->setStartDate($data['start']);
        $builder->setEndDate($data['end']);
        $builder->setSummary($data['summary']);

        $description = $this->mergeUrlIntoDescription($data);

        if ($description !== '') {
            $builder->setDescription($description);
        }

        if ($data['location'] !== '') {
            $builder->setLocation($data['location']);
        }

        if ($data['organizer'] !== '') {
            $builder->setOrganizer($data['organizer']);
        }

        $builder->createInCalendar($calendar);
    }

    /**
     * The event builder has no URL property, so a URL that
     * differs from the location is kept in the description.
     */
    private function mergeUrlIntoDescription(array $data): string
    {
        $urlOnly = $data['url'] !== ''
            && $data['url'] !== $data['location'];

        if (!$urlOnly) {
            return $data['description'];
        }

        $prefix = $data['description'] === '' ? '' : $data['description'] . "\n\n";

        return $prefix . $data['url'];
    }

    /**
     * Validate and normalize all event input.
     */
    private function validateEventData(
        string $summary,
        string $description,
        string $start,
        string $end,
        string $organizer,
        string $location,
        string $url
    ): array {
        $summary = trim($summary);
        $description = trim($description);
        $start = trim($start);
        $end = trim($end);
        $organizer = trim($organizer);
        $location = trim($location);
        $url = trim($url);

        if ($summary === '') {
            throw new \InvalidArgumentException(
                'Meeting title is required.'
            );
        }

        if (mb_strlen($summary) > 255) {
            throw new \InvalidArgumentException(
                'Meeting title is too long.'
            );
        }

        if ($description !== '' && mb_strlen($description) > 10000) {
            throw new \InvalidArgumentException(
                'Meeting description is too long.'
            );
        }

        $startDate = $this->parseLocalDateTime($start);
        $endDate = $this->parseLocalDateTime($end);

        if ($endDate <= $startDate) {
            throw new \InvalidArgumentException(
                'End time must be after start time.'
            );
        }

        if ($location !== '') {
            $this->assertHttpsUrl($location, 'Location must be an HTTPS URL.');
        }

        if ($url !== '') {
            $this->assertHttpsUrl($url, 'Meeting URL must be an HTTPS URL.');
        }

        if ($organizer !== '' && !filter_var(
            $organizer,
            FILTER_VALIDATE_EMAIL
        )) {
            throw new \InvalidArgumentException(
                'Invalid organizer email address.'
            );
        }

        return [
            'summary' => $summary,
            'description' => $description,
            'start' => $startDate,
            'end' => $endDate,
            'organizer' => $organizer,
            'location' => $location,
            'url' => $url,
        ];
    }

    private function assertHttpsUrl(string $value, string $message): void
    {
        $parts = parse_url($value);

        $valid = $parts !== false
            && isset($parts['scheme'])
            && strtolower($parts['scheme']) === 'https';

        if (!$valid) {
            throw new \InvalidArgumentException($message);
        }
    }

    /**
     * Parse datetime-local values.
     *
     * datetime-local carries no timezone, so it is interpreted
     * in the user's Nextcloud timezone. It falls back to the
     * server default timezone if the user has none set.
     */
    private function parseLocalDateTime(string $value): \DateTimeImmutable
    {
        $timeZone = $this->dateTimeZone->getTimeZone();

        $date = \DateTimeImmutable::createFromFormat(
            self::DATETIME_LOCAL,
            $value,
            $timeZone
        );

        if ($date === false) {
            $date = \DateTimeImmutable::createFromFormat(
                'Y-m-d\TH:i:s',
                $value,
                $timeZone
            );
        }

        if ($date === false) {
            throw new \InvalidArgumentException(
                'Invalid date/time.'
            );
        }

        return $date;
    }
}
