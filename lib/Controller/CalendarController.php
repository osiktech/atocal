<?php

declare(strict_types=1);

namespace OCA\JitsiCalendar\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Calendar\IManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IURLGenerator;
use OCP\Util;
use OCP\IConfig;

class CalendarController extends Controller
{
    private IManager $calendarManager;
    private IUserSession $userSession;
    private IURLGenerator $urlGenerator;
    private IConfig $config;

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
            self::APP_ID,
            'confirm',
            [
                'summary' => $data['summary'],
                'description' => $data['description'],
                'start' => $data['start'],
                'end' => $data['end'],
                'organizer' => $data['organizer'],
                'location' => $data['location'],
                'url' => $data['url'],
                'createUrl' => $this->urlGenerator->linkToRoute(
                    self::APP_ID . '.calendar.create'
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

        $calendars = $this->calendarManager->getCalendarsForUser(
            $user->getUID()
        );

        $writableCalendar = null;

        foreach ($calendars as $calendar) {
            /*
             * getCalendarsForUser() returns ICalendar objects.
             * Only calendars implementing ICreateFromString are writable.
             */
            if ($calendar instanceof \OCP\Calendar\ICreateFromString) {
                $writableCalendar = $calendar;
                break;
            }
        }

        if ($writableCalendar === null) {
            throw new \RuntimeException(
                'No writable calendar is available for this user.'
            );
        }

        $uid = 'jitsi-' . bin2hex(random_bytes(16))
            . '@meet.bnvb.eu';

        $ics = $this->buildIcs(
            $uid,
            $data['summary'],
            $data['description'],
            $data['start'],
            $data['end'],
            $data['organizer'],
            $data['location'],
            $data['url']
        );

        $filename = $uid . '.ics';

        $writableCalendar->createFromString(
            $filename,
            $ics
        );

        /*
         * Open the Nextcloud Calendar application.
         */
        $calendarUrl = $this->urlGenerator->linkToRoute(
            'calendar.view.index'
        );

        return new RedirectResponse($calendarUrl);
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
            $locationParts = parse_url($location);

            if (
                $locationParts === false ||
                !isset($locationParts['scheme']) ||
                !in_array(
                    strtolower($locationParts['scheme']),
                    ['https'],
                    true
                )
            ) {
                throw new \InvalidArgumentException(
                    'Location must be an HTTPS URL.'
                );
            }
        }

        if ($url !== '') {
            $urlParts = parse_url($url);

            if (
                $urlParts === false ||
                !isset($urlParts['scheme']) ||
                !in_array(
                    strtolower($urlParts['scheme']),
                    ['https'],
                    true
                )
            ) {
                throw new \InvalidArgumentException(
                    'Meeting URL must be an HTTPS URL.'
                );
            }
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

    /**
     * Parse datetime-local values.
     *
     * datetime-local has no timezone information, so we intentionally
     * interpret the value in the Nextcloud server's configured timezone.
     */
    private function parseLocalDateTime(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
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

    /**
     * Build an RFC 5545 iCalendar event.
     */
    private function buildIcs(
        string $uid,
        string $summary,
        string $description,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $organizer,
        string $location,
        string $url
    ): string {
        $now = new \DateTimeImmutable('now');

        $dtStamp = $now->setTimezone(
            new \DateTimeZone('UTC')
        )->format('Ymd\THis\Z');

        $dtStart = $start->format('Ymd\THis');
        $dtEnd = $end->format('Ymd\THis');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//BNVB//Jitsi Calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $this->escapeIcsText($uid),
            'DTSTAMP:' . $dtStamp,
            'DTSTART:' . $dtStart,
            'DTEND:' . $dtEnd,
            'SUMMARY:' . $this->escapeIcsText($summary),
        ];

        if ($description !== '') {
            $lines[] =
                'DESCRIPTION:' .
                $this->escapeIcsText($description);
        }

        if ($location !== '') {
            $lines[] =
                'LOCATION:' .
                $this->escapeIcsText($location);
        }

        if ($organizer !== '') {
            $lines[] =
                'ORGANIZER:MAILTO:' .
                $this->escapeIcsText($organizer);
        }

        if ($url !== '') {
            $lines[] =
                'URL:' .
                $this->escapeIcsText($url);
        }

        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'SEQUENCE:0';
        $lines[] = 'TRANSP:OPAQUE';

        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = 'DESCRIPTION:Reminder';
        $lines[] = 'TRIGGER:-PT15M';
        $lines[] = 'END:VALARM';

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    private function escapeIcsText(string $value): string
    {
        return str_replace(
            [
                '\\',
                ';',
                ',',
                "\r\n",
                "\r",
                "\n",
            ],
            [
                '\\\\',
                '\;',
                '\,',
                '\n',
                '\n',
                '\n',
            ],
            $value
        );
    }
}