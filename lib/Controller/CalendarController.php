<?php

declare(strict_types=1);

namespace OCA\AToCal\Controller;

use OCA\AToCal\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\Constants;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IDateTimeZone;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

class CalendarController extends Controller
{
    /*
     * Preferred calendar, preselected in the dropdown if present.
     */
    private const CALENDAR_NAME = 'Personal';

    /*
     * Nextcloud Calendar app id, target of the redirect
     * after the event was created.
     */
    private const CALENDAR_APP_ID = 'calendar';

    /*
     * Form value separator between calendar key and URI.
     */
    private const KEY_SEPARATOR = '::';

    /*
     * Default reminder: 15 minutes before the event starts.
     */
    private const REMINDER_TRIGGER = '-PT15M';

    /*
     * datetime-local format as sent by external application,
     * e.g. "2026-10-07T14:15".
     */
    private const DATETIME_LOCAL = 'Y-m-d\TH:i';

    /*
     * Maximum length of the URL-like fields "location" and "url".
     */
    private const MAX_URL_LENGTH = 2000;

    /*
     * Maximum length of a valid email address (RFC 5321).
     */
    private const MAX_EMAIL_LENGTH = 254;

    private IManager $calendarManager;
    private IAppManager $appManager;
    private IUserSession $userSession;
    private IURLGenerator $urlGenerator;
    private IDateTimeZone $dateTimeZone;
    private IL10N $l10n;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        IManager $calendarManager,
        IAppManager $appManager,
        IUserSession $userSession,
        IURLGenerator $urlGenerator,
        IDateTimeZone $dateTimeZone,
        IL10N $l10n,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);

        $this->calendarManager = $calendarManager;
        $this->appManager = $appManager;
        $this->userSession = $userSession;
        $this->urlGenerator = $urlGenerator;
        $this->dateTimeZone = $dateTimeZone;
        $this->l10n = $l10n;
        $this->logger = $logger;
    }

    /**
     * Show event confirmation.
     *
     * The route itself requires an authenticated Nextcloud user.
     * If the user is not authenticated, Nextcloud's normal
     * authentication flow takes over before the controller executes.
     *
     * NoCSRFRequired: Page will be called by link from external application
     * browser does not send request token, yet.
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
        try {
            $data = $this->validateEventData(
                $summary,
                $description,
                $start,
                $end,
                $organizer,
                $location,
                $url
            );
        } catch (\InvalidArgumentException $e) {
            return $this->renderError($e);
        }

        $user = $this->userSession->getUser();

        $calendars = $user === null
            ? []
            : $this->findWritableCalendars($user->getUID());

        return $this->renderConfirm(
            $data,
            $calendars,
            $this->defaultCalendarId($calendars)
        );
    }

    /**
     * Create the calendar event.
     *
     * CSRF remains active: the confirmation page form
     * sends the request token along.
     */
    #[NoAdminRequired]
    public function create(
        string $summary = '',
        string $description = '',
        string $start = '',
        string $end = '',
        string $organizer = '',
        string $location = '',
        string $url = '',
        string $calendarId = ''
    ): RedirectResponse|TemplateResponse {
        try {
            $data = $this->validateEventData(
                $summary,
                $description,
                $start,
                $end,
                $organizer,
                $location,
                $url
            );
        } catch (\InvalidArgumentException $e) {
            return $this->renderError($e);
        }

        $user = $this->userSession->getUser();

        if ($user === null) {
            throw new \RuntimeException('No authenticated Nextcloud user.');
        }

        $calendars = $this->findWritableCalendars($user->getUID());

        if ($calendarId === '') {
            $calendarId = $this->defaultCalendarId($calendars);
        }

        $calendar = $this->selectCalendar($calendars, $calendarId);

        /*
         * Friendly error instead of a hard server error page.
         */
        if ($calendar === null) {
            return $this->renderConfirm(
                $data,
                $calendars,
                $calendarId,
                $calendars === []
                    ? $this->l10n->t(
                        'No writable calendar was found in your account. '
                        . 'Create one in the Nextcloud Calendar app, '
                        . 'then try again.'
                    )
                    : $this->l10n->t(
                        'The selected calendar is no longer writable. '
                        . 'Pick another one and try again.'
                    )
            );
        }

        /*
         * Backend failures, e.g. CalendarException, must not end
         * in a generic 500 page. Log the cause, re-render the form.
         */
        try {
            $this->createEvent($calendar, $data);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to create calendar event: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $this->renderConfirm(
                $data,
                $calendars,
                $calendarId,
                $this->l10n->t(
                    'The event could not be created. Please try again.'
                )
            );
        }

        /*
         * Open the Nextcloud Calendar application if enabled,
         * otherwise the Nextcloud start page. linkToRoute()
         * would throw for a disabled Calendar app.
         */
        $calendarUrl = $this->appManager->isEnabledForUser(self::CALENDAR_APP_ID, $user)
            ? $this->urlGenerator->linkToRoute('calendar.view.index')
            : $this->urlGenerator->getAbsoluteURL('/');

        return new RedirectResponse($calendarUrl);
    }

    /**
     * All writable calendars of the given principal.
     *
     * getCalendarsForPrincipal() returns ICalendar objects.
     * Only calendars implementing ICreateFromString are writable.
     */
    private function findWritableCalendars(string $userId): array
    {
        /*
         * IManager calendars are queried via the principal URI.
         */
        $principalUri = 'principals/users/' . $userId;

        $calendars = $this->calendarManager->getCalendarsForPrincipal($principalUri);

        $writable = [];

        foreach ($calendars as $calendar) {
            if ($calendar instanceof ICreateFromString
                && ($calendar->getPermissions() & Constants::PERMISSION_CREATE) !== 0) {
                $writable[] = $calendar;
            }
        }

        return $writable;
    }

    /**
     * The calendar with the given form value ("key::uri"),
     * if it is writable. Keys alone are not unique across
     * calendar providers, so the URI is matched too.
     */
    private function selectCalendar(array $calendars, string $value): ?ICreateFromString
    {
        $parts = explode(self::KEY_SEPARATOR, $value, 2);

        if (!isset($parts[1])) {
            return null;
        }

        [$key, $uri] = $parts;

        foreach ($calendars as $calendar) {
            if ($calendar->getKey() === $key
                && $calendar->getUri() === $uri) {
                return $calendar;
            }
        }

        return null;
    }

    /**
     * Form value identifying a calendar: "key::uri".
     */
    private function calendarValue(ICreateFromString $calendar): string
    {
        return $calendar->getKey() . self::KEY_SEPARATOR . $calendar->getUri();
    }

    /**
     * Key of the calendar to preselect: the preferred
     * CALENDAR_NAME if present, otherwise the first one.
     */
    private function defaultCalendarId(array $calendars): string
    {
        foreach ($calendars as $calendar) {
            if ($calendar->getDisplayName() === self::CALENDAR_NAME) {
                return $this->calendarValue($calendar);
            }
        }

        if (isset($calendars[0])) {
            return $this->calendarValue($calendars[0]);
        }

        return '';
    }

    /**
     * Friendly error page (HTTP 400) for a malformed link,
     * instead of an uncaught exception and a 500 error page.
     */
    private function renderError(\InvalidArgumentException $e): TemplateResponse
    {
        return new TemplateResponse(
            'core',
            'error',
            [
                'errors' => [
                    ['error' => $e->getMessage(), 'hint' => ''],
                ],
            ],
            TemplateResponse::RENDER_AS_ERROR,
            Http::STATUS_BAD_REQUEST
        );
    }

    /**
     * Render the confirmation page, optionally with an error message.
     */
    private function renderConfirm(
        array $data,
        array $calendars,
        string $calendarId = '',
        string $error = ''
    ): TemplateResponse {
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
                'l10n' => $this->l10n,
                'calendars' => array_map(
                    function (ICreateFromString $calendar): array {
                        return [
                            'value' => $this->calendarValue($calendar),
                            'displayName' => $calendar->getDisplayName() ?? '',
                        ];
                    },
                    $calendars
                ),
                'calendarId' => $calendarId,
                'cancelUrl' => $this->urlGenerator->getAbsoluteURL('/'),
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

        /*
         * ICalendarEventBuilder supports no alarms, so the VALARM
         * is injected into the serialized ICS before writing it.
         */
        $vobject = Reader::read($builder->toIcs());

        $vobject->VEVENT->add('VALARM', [
            'ACTION' => 'DISPLAY',
            'DESCRIPTION' => 'Reminder',
            'TRIGGER' => self::REMINDER_TRIGGER,
        ]);

        $fileName = (string) $vobject->VEVENT->UID . '.ics';

        $calendar->createFromString($fileName, $vobject->serialize());
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
                $this->l10n->t('Meeting title is required.')
            );
        }

        if (mb_strlen($summary) > 255) {
            throw new \InvalidArgumentException(
                $this->l10n->t('Meeting title is too long.')
            );
        }

        $this->assertClean(
            $summary,
            $this->l10n->t('Meeting title contains invalid characters.')
        );

        if ($description !== '' && mb_strlen($description) > 10000) {
            throw new \InvalidArgumentException(
                $this->l10n->t('Meeting description is too long.')
            );
        }

        if ($description !== '') {
            $this->assertClean(
                $description,
                $this->l10n->t('Meeting description contains invalid characters.'),
                true
            );
        }

        $startDate = $this->parseLocalDateTime($start);
        $endDate = $this->parseLocalDateTime($end);

        if ($endDate <= $startDate) {
            throw new \InvalidArgumentException(
                $this->l10n->t('End time must be after start time.')
            );
        }

        if ($location !== '') {
            $this->assertBoundedLength(
                $location,
                self::MAX_URL_LENGTH,
                $this->l10n->t('Location is too long.')
            );
            $this->assertClean(
                $location,
                $this->l10n->t('Location contains invalid characters.')
            );
            $this->assertHttpsUrl($location, $this->l10n->t('Location must be an HTTPS URL.'));
        }

        if ($url !== '') {
            $this->assertBoundedLength(
                $url,
                self::MAX_URL_LENGTH,
                $this->l10n->t('Meeting URL is too long.')
            );
            $this->assertClean(
                $url,
                $this->l10n->t('Meeting URL contains invalid characters.')
            );
            $this->assertHttpsUrl($url, $this->l10n->t('Meeting URL must be an HTTPS URL.'));
        }

        if ($organizer !== '') {
            $this->assertBoundedLength(
                $organizer,
                self::MAX_EMAIL_LENGTH,
                $this->l10n->t('Organizer email address is too long.')
            );
            $this->assertClean(
                $organizer,
                $this->l10n->t('Organizer contains invalid characters.')
            );

            if (!filter_var(
                $organizer,
                FILTER_VALIDATE_EMAIL
            )) {
                throw new \InvalidArgumentException(
                    $this->l10n->t('Invalid organizer email address.')
                );
            }
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
        /*
         * filter_var() plus the regex reject whitespace, backslashes
         * and non-ASCII characters: browsers may display those
         * differently than PHP parses them, e.g. IDN homographs.
         */
        if (filter_var($value, FILTER_VALIDATE_URL) === false
            || preg_match('/[^\x21-\x7E]|\\\\/', $value) === 1) {
            throw new \InvalidArgumentException($message);
        }

        $parts = parse_url($value);

        $valid = $parts !== false
            && isset($parts['scheme'], $parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && strtolower($parts['scheme']) === 'https'
            && $parts['host'] !== '';

        if (!$valid) {
            throw new \InvalidArgumentException($message);
        }
    }

    /**
     * Enforce a maximum length, counted in characters.
     */
    private function assertBoundedLength(string $value, int $maxLength, string $message): void
    {
        if (mb_strlen($value) > $maxLength) {
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
                $this->l10n->t('Invalid date/time.')
            );
        }

        return $date;
    }

    /**
     * Reject invalid UTF-8 and control characters. Newlines
     * (\n, \r) are only allowed where multi-line text is
     * expected, e.g. the description. Also rejects Unicode
     * spoofing characters: bidi overrides and line separators
     * can make text display misleadingly. Format characters
     * like the zero-width joiner (U+200D) stay allowed, emoji
     * sequences need them.
     */
    private function assertClean(string $v, string $msg, bool $allowNewlines = false): void
    {
        $re = $allowNewlines
            ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'
            : '/[\x00-\x1F\x7F]/';

        if (!mb_check_encoding($v, 'UTF-8')
            || preg_match($re, $v) === 1
            || preg_match(
                '/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{2028}\x{2029}\x{0085}]/u',
                $v
            ) === 1
        ) {
            throw new \InvalidArgumentException($msg);
        }
    }
}
