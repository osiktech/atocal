<?php

declare(strict_types=1);

namespace OCA\JitsiCalendar\Controller;

use OCA\JitsiCalendar\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class CalendarController extends Controller
{
    /*
     * datetime-local format as sent by jitsi-launch,
     * e.g. "2026-10-07T14:15".
     */
    private const DATETIME_LOCAL = 'Y-m-d\TH:i';

    private IManager $calendarManager;
    private IUserSession $userSession;
    private IURLGenerator $urlGenerator;

    public function __construct(
        string $appName,
        IRequest $request,
        IManager $calendarManager,
        IUserSession $userSession,
        IURLGenerator $urlGenerator
    ) {
        parent::__construct($appName, $request);

        $this->calendarManager = $calendarManager;
        $this->userSession = $userSession;
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * Show event confirmation.
     *
     * The route itself requires an authenticated Nextcloud user.
     * If the user is not authenticated, Nextcloud's normal
     * authentication flow takes over before the controller executes.
     */
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
                'createUrl' => $this->urlGenerator->linkToRoute(
                    Application::APP_ID . '.calendar.create'
                ),
            ]
        );
    }

    /**
     * Create the calendar event.
     */
    public function create(
        string $summary = '',
        string $description = '',
        string $start = '',
        string $end = '',
        string $organizer = '',
        string $location = '',
        string $url = ''
    ): RedirectResponse {
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

        if ($calendar === null) {
            throw new \RuntimeException(
                'No writable calendar is available for this user.'
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
     * First writable calendar of the user.
     *
     * getCalendarsForUser() returns ICalendar objects.
     * Only calendars implementing ICreateFromString are writable.
     */
    private function findWritableCalendar(string $userId): ?ICreateFromString
    {
        $calendars = $this->calendarManager->getCalendarsForUser($userId);

        foreach ($calendars as $calendar) {
            if ($calendar instanceof ICreateFromString) {
                return $calendar;
            }
        }

        return null;
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

        if ($data['description'] !== '') {
            $builder->setDescription($data['description']);
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
     * datetime-local has no timezone information, so we intentionally
     * interpret the value in the Nextcloud server's configured timezone.
     */
    private function parseLocalDateTime(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(
            self::DATETIME_LOCAL,
            $value
        );

        if ($date === false) {
            $date = \DateTimeImmutable::createFromFormat(
                'Y-m-d\TH:i:s',
                $value
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
