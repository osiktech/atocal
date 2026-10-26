<?php

declare(strict_types=1);

namespace OCA\AToCal\Tests\Unit\Controller;

use OCA\AToCal\Controller\CalendarController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarEventBuilder;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\Constants;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/OCUtilStub.php';

class CalendarControllerTest extends TestCase
{
    private IManager&MockObject $calendarManager;
    private IContactsManager&MockObject $contactsManager;
    private IAppManager&MockObject $appManager;
    private IUserSession&MockObject $userSession;
    private IURLGenerator&MockObject $urlGenerator;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->calendarManager = $this->createMock(IManager::class);
        $this->contactsManager = $this->createMock(IContactsManager::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function newController(): CalendarController
    {
        $dateTimeZone = $this->createMock(IDateTimeZone::class);
        $dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('UTC'));

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static fn (string $text, array $params = []): string => $params === []
                ? $text
                : vsprintf($text, $params)
        );

        return new CalendarController(
            'atocal',
            $this->createMock(IRequest::class),
            $this->calendarManager,
            $this->contactsManager,
            $this->appManager,
            $this->userSession,
            $this->urlGenerator,
            $dateTimeZone,
            $l10n,
            $this->logger
        );
    }

    private function invoke(CalendarController $c, string $method, array $args = []): mixed
    {
        $m = new \ReflectionMethod(CalendarController::class, $method);

        return $m->invokeArgs($c, $args);
    }

    private function mockUser(string $uid): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return $user;
    }

    private function mockCalendar(
        string $key,
        string $uri,
        string $displayName,
        int $permissions
    ): ICreateFromString&MockObject {
        $calendar = $this->createMock(ICreateFromString::class);
        $calendar->method('getKey')->willReturn($key);
        $calendar->method('getUri')->willReturn($uri);
        $calendar->method('getDisplayName')->willReturn($displayName);
        $calendar->method('getPermissions')->willReturn($permissions);

        return $calendar;
    }

    /*
     * assertClean
     */

    public static function provideCleanCases(): iterable
    {
        return [
            'plain text' => ['Weekly sync', false, true],
            'description with newline' => ["line one\nline two", true, true],
            'newline without allowance' => ["line one\nline two", false, false],
            'NUL byte' => ["bad\x00title", false, false],
            'backspace' => ["bad\x08title", false, false],
            'delete char' => ["bad\x7Ftitle", false, false],
            'bidi override LRE' => ["\u{202A}evil", false, false],
            'bidi override RLO' => ["\u{202E}evil", false, false],
            'bidi isolate LRI' => ["\u{2066}evil", false, false],
            'bidi isolate PDI' => ["\u{2069}evil", false, false],
            'line separator' => ["a\u{2028}b", false, false],
            'paragraph separator' => ["a\u{2029}b", false, false],
            'next line' => ["a\u{0085}b", false, false],
            'zero-width joiner allowed' => ["\u{1F469}\u{200D}\u{1F4BB} dev", false, true],
            'combining accent allowed' => ["caf\u{00E9}", false, true],
            'invalid utf-8' => ["bad\xC3\x28utf", false, false],
        ];
    }

    #[DataProvider('provideCleanCases')]
    public function testAssertClean(string $value, bool $allowNewlines, bool $expectedClean): void
    {
        $c = $this->newController();

        if ($expectedClean) {
            $this->invoke($c, 'assertClean', [$value, 'bad', $allowNewlines]);
            $this->addToAssertionCount(1);

            return;
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->invoke($c, 'assertClean', [$value, 'bad', $allowNewlines]);
    }

    /*
     * assertHttpsUrl
     */

    public static function provideUrlCases(): iterable
    {
        return [
            'valid https' => ['https://example.com/meeting', true],
            'valid with path query' => ['https://example.com/p?a=1#frag', true],
            'space inside' => ['https://exa mple.com/', false],
            'backslash user confusion' => ['https://example.com\\@evil.com/', false],
            'backslash in path' => ['https://example.com/path\\', false],
            'non-ascii host' => ['https://еxample.com/', false],
            'userinfo user' => ['https://user@example.com/', false],
            'userinfo pass only' => ['https://:pass@example.com/', false],
            'userinfo user and pass' => ['https://u:p@example.com/', false],
            'http scheme' => ['http://example.com/', false],
            'ftp scheme' => ['ftp://example.com/', false],
            'no host' => ['https://', false],
            'garbage' => ['not a url', false],
        ];
    }

    #[DataProvider('provideUrlCases')]
    public function testAssertHttpsUrl(string $value, bool $expectedValid): void
    {
        $c = $this->newController();

        if ($expectedValid) {
            $this->invoke($c, 'assertHttpsUrl', [$value, 'bad url']);
            $this->addToAssertionCount(1);

            return;
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->invoke($c, 'assertHttpsUrl', [$value, 'bad url']);
    }

    /*
     * assertBoundedLength
     */

    public function testAssertBoundedLength(): void
    {
        $c = $this->newController();

        $this->invoke($c, 'assertBoundedLength', ['12345', 5, 'too long']);
        $this->addToAssertionCount(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->invoke($c, 'assertBoundedLength', ['123456', 5, 'too long']);
    }

    /*
     * parseLocalDateTime
     */

    public function testParseLocalDateTime(): void
    {
        $c = $this->newController();

        $date = $this->invoke($c, 'parseLocalDateTime', ['2026-10-07T14:15']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame('2026-10-07 14:15:00 UTC', $date->format('Y-m-d H:i:s T'));

        $date = $this->invoke($c, 'parseLocalDateTime', ['2026-10-07T14:15:30']);
        $this->assertSame('2026-10-07 14:15:30 UTC', $date->format('Y-m-d H:i:s T'));

        $this->expectException(\InvalidArgumentException::class);
        $this->invoke($c, 'parseLocalDateTime', ['tomorrow']);
    }

    public function testParseLocalDateTimeAcceptsLeapDay(): void
    {
        $c = $this->newController();

        $date = $this->invoke($c, 'parseLocalDateTime', ['2028-02-29T10:00']);
        $this->assertSame('2028-02-29 10:00:00 UTC', $date->format('Y-m-d H:i:s T'));
    }

    public static function provideInvalidLocalDateTimes(): iterable
    {
        return [
            'impossible day rolls into next month' => ['2026-02-31T10:00'],
            'leap day in a non-leap year' => ['2027-02-29T10:00'],
            'month out of range' => ['2026-13-01T10:00'],
            'day out of range' => ['2026-00-15T10:00'],
            'hour out of range' => ['2026-02-03T25:00'],
            'non-padded components' => ['2026-2-3T10:00'],
        ];
    }

    #[DataProvider('provideInvalidLocalDateTimes')]
    public function testParseLocalDateTimeRejectsInvalidValues(string $value): void
    {
        $c = $this->newController();

        $this->expectException(\InvalidArgumentException::class);
        $this->invoke($c, 'parseLocalDateTime', [$value]);
    }

    /*
     * validateEventData
     */

    private function validInput(): array
    {
        return [
            'summary' => '  Weekly sync ',
            'description' => ' Agenda attached ',
            'start' => '2026-10-07T14:15',
            'end' => '2026-10-07T15:15',
            'organizer' => 'boss@example.com',
            'location' => 'https://meet.example.com/room',
            'url' => 'https://meet.example.com/room',
        ];
    }

    public function testValidateEventDataNormalizes(): void
    {
        $c = $this->newController();

        $data = $this->invoke($c, 'validateEventData', array_values($this->validInput()));

        $this->assertSame('Weekly sync', $data['summary']);
        $this->assertSame('Agenda attached', $data['description']);
        $this->assertSame('boss@example.com', $data['organizer']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $data['start']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $data['end']);
        $this->assertSame('https://meet.example.com/room', $data['location']);
        $this->assertSame('https://meet.example.com/room', $data['url']);
    }

    public static function provideValidationFailures(): iterable
    {
        return [
            'missing title' => [
                ['summary' => ''],
                'Meeting title is required.',
            ],
            'title too long' => [
                ['summary' => str_repeat('a', 256)],
                'Meeting title is too long.',
            ],
            'title control char' => [
                ['summary' => "bad\x00title"],
                'Meeting title contains invalid characters.',
            ],
            'title bidi override' => [
                ['summary' => "\u{202E}evil title"],
                'Meeting title contains invalid characters.',
            ],
            'description too long' => [
                ['description' => str_repeat('a', 10001)],
                'Meeting description is too long.',
            ],
            'description control char' => [
                ['description' => "bad\x01desc"],
                'Meeting description contains invalid characters.',
            ],
            'end before start' => [
                ['start' => '2026-10-07T15:15', 'end' => '2026-10-07T14:15'],
                'End time must be after start time.',
            ],
            'bad date format' => [
                ['start' => 'tomorrow', 'end' => '2026-10-07T14:15'],
                'Invalid date/time.',
            ],
            'location not https' => [
                ['location' => 'http://meet.example.com/'],
                'Location must be an HTTPS URL.',
            ],
            'location with userinfo' => [
                ['location' => 'https://user@meet.example.com/'],
                'Location must be an HTTPS URL.',
            ],
            'location too long' => [
                ['location' => 'https://example.com/' . str_repeat('a', 2000)],
                'Location is too long.',
            ],
            'url not https' => [
                ['url' => 'http://meet.example.com/'],
                'Meeting URL must be an HTTPS URL.',
            ],
            'url control char' => [
                ['url' => "https://example.com/\x00/room"],
                'Meeting URL contains invalid characters.',
            ],
            'organizer no email' => [
                ['organizer' => 'not-an-email'],
                'Invalid organizer email address.',
            ],
            'organizer too long' => [
                ['organizer' => str_repeat('a', 250) . '@example.com'],
                'Organizer email address is too long.',
            ],
            'organizer control char' => [
                ['organizer' => "boss\x00@example.com"],
                'Organizer contains invalid characters.',
            ],
        ];
    }

    #[DataProvider('provideValidationFailures')]
    public function testValidateEventDataRejects(array $overrides, string $message): void
    {
        $c = $this->newController();

        $input = array_merge($this->validInput(), $overrides);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->invoke($c, 'validateEventData', array_values($input));
    }

    /*
     * mergeUrlIntoDescription
     */

    public function testMergeUrlIntoDescription(): void
    {
        $c = $this->newController();

        $urlEqualsLocation = [
            'url' => 'https://example.com/room',
            'location' => 'https://example.com/room',
            'description' => 'Agenda',
        ];
        $this->assertSame(
            'Agenda',
            $this->invoke($c, 'mergeUrlIntoDescription', [$urlEqualsLocation])
        );

        $emptyDescription = [
            'url' => 'https://example.com/room',
            'location' => '',
            'description' => '',
        ];
        $this->assertSame(
            'https://example.com/room',
            $this->invoke($c, 'mergeUrlIntoDescription', [$emptyDescription])
        );

        $both = [
            'url' => 'https://example.com/room',
            'location' => 'https://meet.example.com/other',
            'description' => 'Agenda',
        ];
        $this->assertSame(
            "Agenda\n\nhttps://example.com/room",
            $this->invoke($c, 'mergeUrlIntoDescription', [$both])
        );
    }

    /*
     * calendar form value, selection and default
     */

    public function testCalendarValue(): void
    {
        $c = $this->newController();

        $calendar = $this->mockCalendar('5', 'personal', 'Personal', Constants::PERMISSION_CREATE);

        $this->assertSame('5::personal', $this->invoke($c, 'calendarValue', [$calendar]));
    }

    public function testSelectCalendarResolvesKeyCollision(): void
    {
        $c = $this->newController();

        $personal = $this->mockCalendar('5', 'personal', 'Personal', Constants::PERMISSION_CREATE);
        $shared = $this->mockCalendar('5', 'shared-by-bob', 'Shared', Constants::PERMISSION_CREATE);

        $calendars = [$personal, $shared];

        $this->assertSame($personal, $this->invoke($c, 'selectCalendar', [$calendars, '5::personal']));
        $this->assertSame($shared, $this->invoke($c, 'selectCalendar', [$calendars, '5::shared-by-bob']));
    }

    public static function provideNoMatchValues(): iterable
    {
        return [
            'no separator' => ['5'],
            'wrong uri' => ['5::nope'],
            'wrong key' => ['9::personal'],
            'empty' => [''],
        ];
    }

    #[DataProvider('provideNoMatchValues')]
    public function testSelectCalendarNoMatch(string $value): void
    {
        $c = $this->newController();

        $calendar = $this->mockCalendar('5', 'personal', 'Personal', Constants::PERMISSION_CREATE);

        $this->assertNull($this->invoke($c, 'selectCalendar', [[$calendar], $value]));
    }

    public function testDefaultCalendarId(): void
    {
        $c = $this->newController();

        $work = $this->mockCalendar('1', 'work', 'Work', Constants::PERMISSION_CREATE);
        $personal = $this->mockCalendar('2', 'personal', 'Personal', Constants::PERMISSION_CREATE);

        $this->assertSame('2::personal', $this->invoke($c, 'defaultCalendarId', [[$work, $personal]]));
        $this->assertSame('1::work', $this->invoke($c, 'defaultCalendarId', [[$work]]));
        $this->assertSame('', $this->invoke($c, 'defaultCalendarId', [[]]));
    }

    /*
     * findWritableCalendars
     */

    public function testFindWritableCalendars(): void
    {
        $this->calendarManager->method('getCalendarsForPrincipal')
            ->with('principals/users/alice')
            ->willReturn([
                $this->mockCalendar('1', 'work', 'Work', Constants::PERMISSION_CREATE),
                $this->mockCalendar('2', 'shared', 'Shared', Constants::PERMISSION_READ),
                $this->createMock(ICalendar::class),
            ]);

        $c = $this->newController();

        $writable = $this->invoke($c, 'findWritableCalendars', ['alice']);

        $this->assertCount(1, $writable);
        $this->assertSame('1', $writable[0]->getKey());
    }

    /*
     * createEvent: VALARM injection
     */

    public function testCreateEventInjectsValarm(): void
    {
        $builder = $this->createMock(ICalendarEventBuilder::class);
        $builder->method('toIcs')->willReturn(
            "BEGIN:VCALENDAR\r\n" .
            "VERSION:2.0\r\n" .
            "PRODID:-//Nextcloud//AToCal//EN\r\n" .
            "BEGIN:VEVENT\r\n" .
            "UID:test-uid-123\r\n" .
            "SUMMARY:Weekly sync\r\n" .
            "END:VEVENT\r\n" .
            "END:VCALENDAR\r\n"
        );
        $this->calendarManager->method('createEventBuilder')->willReturn($builder);

        $calendar = $this->mockCalendar('5', 'personal', 'Personal', Constants::PERMISSION_CREATE);
        $calendar->expects($this->once())->method('createFromString')
            ->willReturnCallback(function (string $name, string $data): bool {
                $this->assertSame('test-uid-123.ics', $name);
                $this->assertStringContainsString('BEGIN:VALARM', $data);
                $this->assertStringContainsString('TRIGGER:-PT15M', $data);

                return true;
            });

        $c = $this->newController();

        $data = [
            'summary' => 'Weekly sync',
            'description' => '',
            'start' => new \DateTimeImmutable('2026-10-07T14:15:00 UTC'),
            'end' => new \DateTimeImmutable('2026-10-07T15:15:00 UTC'),
            'organizer' => '',
            'organizerEmail' => '',
            'location' => '',
            'url' => '',
            'attendees' => [],
        ];

        $this->invoke($c, 'createEvent', [$calendar, $data]);
    }

    /*
     * createEvent: ATTENDEE injection
     */

    public function testCreateEventInvitesAttendees(): void
    {
        $builder = $this->createMock(ICalendarEventBuilder::class);
        $builder->method('toIcs')->willReturn(
            "BEGIN:VCALENDAR\r\n" .
            "VERSION:2.0\r\n" .
            "PRODID:-//Nextcloud//AToCal//EN\r\n" .
            "BEGIN:VEVENT\r\n" .
            "UID:test-uid-123\r\n" .
            "SUMMARY:Weekly sync\r\n" .
            "END:VEVENT\r\n" .
            "END:VCALENDAR\r\n"
        );
        $this->calendarManager->method('createEventBuilder')->willReturn($builder);

        $calendar = $this->mockCalendar('5', 'personal', 'Personal', Constants::PERMISSION_CREATE);
        $calendar->expects($this->once())->method('createFromString')
            ->willReturnCallback(function (string $name, string $data): bool {
                $this->assertStringContainsString(
                    'ATTENDEE;CN=Alice Example:mailto:alice@example.com',
                    $data
                );
                $this->assertStringContainsString(
                    'ATTENDEE:mailto:bob@example.com',
                    $data
                );

                return true;
            });

        $c = $this->newController();

        $data = [
            'summary' => 'Weekly sync',
            'description' => '',
            'start' => new \DateTimeImmutable('2026-10-07T14:15:00 UTC'),
            'end' => new \DateTimeImmutable('2026-10-07T15:15:00 UTC'),
            'organizer' => '',
            'organizerEmail' => '',
            'location' => '',
            'url' => '',
            'attendees' => [
                ['email' => 'alice@example.com', 'name' => 'Alice Example'],
                ['email' => 'bob@example.com', 'name' => ''],
            ],
        ];

        $this->invoke($c, 'createEvent', [$calendar, $data]);
    }

    /*
     * createEvent: the calendar owner is the ICS organizer
     */

    private function skeletonIcs(): string
    {
        return
            "BEGIN:VCALENDAR\r\n" .
            "VERSION:2.0\r\n" .
            "PRODID:-//Nextcloud//AToCal//EN\r\n" .
            "BEGIN:VEVENT\r\n" .
            "UID:test-uid-123\r\n" .
            "SUMMARY:Weekly sync\r\n" .
            "END:VEVENT\r\n" .
            "END:VCALENDAR\r\n";
    }

    private function eventBuilderWith(\PHPUnit\Framework\MockObject\MockObject|ICalendarEventBuilder $builder): void
    {
        $builder->method('toIcs')->willReturn($this->skeletonIcs());
        $this->calendarManager->method('createEventBuilder')->willReturn($builder);
    }

    public function testCreateEventPrefersUserEmailAsOrganizer(): void
    {
        $builder = $this->createMock(ICalendarEventBuilder::class);
        $builder->expects($this->once())->method('setOrganizer')
            ->with('alice@nextcloud.example');
        $this->eventBuilderWith($builder);

        $calendar = $this->mockCalendar('5', 'personal', 'Personal', Constants::PERMISSION_CREATE);

        $c = $this->newController();

        $data = [
            'summary' => 'Weekly sync',
            'description' => '',
            'start' => new \DateTimeImmutable('2026-10-07T14:15:00 UTC'),
            'end' => new \DateTimeImmutable('2026-10-07T15:15:00 UTC'),
            'organizer' => 'boss@example.com',
            'organizerEmail' => 'alice@nextcloud.example',
            'location' => '',
            'url' => '',
            'attendees' => [],
        ];

        $this->invoke($c, 'createEvent', [$calendar, $data]);
    }

    public function testCreateEventFallsBackToLinkOrganizer(): void
    {
        $builder = $this->createMock(ICalendarEventBuilder::class);
        $builder->expects($this->once())->method('setOrganizer')
            ->with('boss@example.com');
        $this->eventBuilderWith($builder);

        $calendar = $this->mockCalendar('5', 'personal', 'Personal', Constants::PERMISSION_CREATE);

        $c = $this->newController();

        $data = [
            'summary' => 'Weekly sync',
            'description' => '',
            'start' => new \DateTimeImmutable('2026-10-07T14:15:00 UTC'),
            'end' => new \DateTimeImmutable('2026-10-07T15:15:00 UTC'),
            'organizer' => 'boss@example.com',
            'organizerEmail' => '',
            'location' => '',
            'url' => '',
            'attendees' => [],
        ];

        $this->invoke($c, 'createEvent', [$calendar, $data]);
    }

    /*
     * create(): the user's email address reaches the event builder
     */

    public function testCreateUsesUserEmailAsOrganizer(): void
    {
        $user = $this->mockUser('alice');
        $user->method('getEMailAddress')->willReturn('alice@nextcloud.example');
        $this->userSession->method('getUser')->willReturn($user);
        $this->setUpWritableCalendars();

        $builder = $this->createMock(ICalendarEventBuilder::class);
        $builder->expects($this->once())->method('setOrganizer')
            ->with('alice@nextcloud.example');
        $this->eventBuilderWith($builder);
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->urlGenerator->method('linkToRoute')->willReturn('/apps/calendar/');

        $c = $this->newController();

        $args = $this->validCreateArgs();
        $args[] = '';

        $response = $c->create(...$args);

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    /*
     * create(): happy path redirects to the Calendar app
     */

    private function setUpWritableCalendars(): void
    {
        $this->calendarManager->method('getCalendarsForPrincipal')
            ->willReturn([
                $this->mockCalendar('5', 'personal', 'Personal', Constants::PERMISSION_CREATE),
            ]);
    }

    /*
     * The event builder ICS is parsed by VObject before it
     * reaches createFromString(), so the create() tests need
     * a valid skeleton ICS.
     */
    private function setUpEventBuilder(): void
    {
        $builder = $this->createMock(ICalendarEventBuilder::class);
        $builder->method('toIcs')->willReturn(
            "BEGIN:VCALENDAR\r\n" .
            "VERSION:2.0\r\n" .
            "PRODID:-//Nextcloud//AToCal//EN\r\n" .
            "BEGIN:VEVENT\r\n" .
            "UID:test-uid-123\r\n" .
            "SUMMARY:Weekly sync\r\n" .
            "END:VEVENT\r\n" .
            "END:VCALENDAR\r\n"
        );
        $this->calendarManager->method('createEventBuilder')->willReturn($builder);
    }

    private function validCreateArgs(): array
    {
        return [
            'Weekly sync',
            'Agenda',
            '2026-10-07T14:15',
            '2026-10-07T15:15',
            'boss@example.com',
            '',
            '',
            '5::personal',
        ];
    }

    public function testCreateRedirectsToCalendarApp(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->setUpWritableCalendars();
        $this->setUpEventBuilder();
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->urlGenerator->method('linkToRoute')->willReturn('/apps/calendar/');

        $c = $this->newController();

        $response = $c->create(...$this->validCreateArgs());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/apps/calendar/', $response->getRedirectURL());
    }

    public function testCreateFallsBackWithoutCalendarApp(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->setUpWritableCalendars();
        $this->setUpEventBuilder();
        $this->appManager->method('isEnabledForUser')->willReturn(false);
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example.com/');

        $c = $this->newController();

        $response = $c->create(...$this->validCreateArgs());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://cloud.example.com/', $response->getRedirectURL());
    }

    public function testCreateUnwritableSelectionShowsError(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->setUpWritableCalendars();

        $args = $this->validCreateArgs();
        $args[7] = '9::nope';

        $c = $this->newController();

        $response = $c->create(...$args);

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('confirm', $response->getTemplateName());
        $this->assertSame(
            'The selected calendar is no longer writable. Pick another one and try again.',
            $response->getParams()['error']
        );
    }

    public function testCreateNoWritableCalendars(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->calendarManager->method('getCalendarsForPrincipal')->willReturn([]);

        $c = $this->newController();

        $response = $c->create(...$this->validCreateArgs());

        $this->assertSame(
            'No writable calendar was found in your account. Create one in the Nextcloud Calendar app, then try again.',
            $response->getParams()['error']
        );
    }

    public function testCreateLogsBackendFailure(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->setUpEventBuilder();

        $calendar = $this->mockCalendar('5', 'personal', 'Personal', Constants::PERMISSION_CREATE);
        $calendar->method('createFromString')
            ->willThrowException(new \RuntimeException('backend down'));
        $this->calendarManager->method('getCalendarsForPrincipal')
            ->willReturn([$calendar]);

        $this->logger->expects($this->once())->method('error')
            ->with(
                'Failed to create calendar event: backend down',
                ['exception' => new \RuntimeException('backend down')]
            );

        $c = $this->newController();

        $response = $c->create(...$this->validCreateArgs());

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame(
            'The event could not be created. Please try again.',
            $response->getParams()['error']
        );
    }

    public function testCreateWithoutUserThrows(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $c = $this->newController();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No authenticated Nextcloud user.');

        $c->create(...$this->validCreateArgs());
    }

    public function testCreateInvalidInputRendersErrorPage(): void
    {
        $c = $this->newController();

        $args = $this->validCreateArgs();
        $args[0] = '';

        $response = $c->create(...$args);

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('error', $response->getTemplateName());
        $this->assertSame('core', $response->getApp());
        $this->assertSame(
            'Meeting title is required.',
            $response->getParams()['errors'][0]['error']
        );
    }

    /*
     * confirm()
     */

    public function testConfirmRendersTemplate(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->setUpWritableCalendars();
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example.com/');

        $c = $this->newController();

        $response = $c->confirm(
            'Weekly sync',
            'Agenda',
            '2026-10-07T14:15',
            '2026-10-07T15:15',
            'boss@example.com',
            'https://meet.example.com/room',
            ''
        );

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('confirm', $response->getTemplateName());

        $params = $response->getParams();
        $this->assertSame('Weekly sync', $params['summary']);
        $this->assertSame('2026-10-07T14:15', $params['start']);
        $this->assertSame('', $params['error']);
        $this->assertSame('5::personal', $params['calendars'][0]['value']);
        $this->assertSame('5::personal', $params['calendarId']);
    }

    public function testConfirmInvalidInputRendersErrorPage(): void
    {
        $c = $this->newController();

        $response = $c->confirm(
            'Weekly sync',
            '',
            '2026-10-07T14:15',
            '2026-10-07T13:15'
        );

        $this->assertSame('error', $response->getTemplateName());
        $this->assertSame(
            'End time must be after start time.',
            $response->getParams()['errors'][0]['error']
        );
    }

    public function testConfirmRegistersStylesheet(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->setUpWritableCalendars();
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example.com/');

        $c = $this->newController();

        $c->confirm('Weekly sync', '', '2026-10-07T14:15', '2026-10-07T15:15');

        $this->assertContains(
            ['atocal', 'style', false],
            \OC_Util::$styles
        );
    }

    /*
     * parseAttendees()
     */

    public function testParseAttendeesNormalizesInput(): void
    {
        $c = $this->newController();

        $attendees = $this->invoke($c, 'parseAttendees', [
            'alice@example.com, BOB@Example.com ;  bob@example.com carl@example.com',
        ]);

        $this->assertSame([
            ['email' => 'alice@example.com', 'name' => ''],
            ['email' => 'bob@example.com', 'name' => ''],
            ['email' => 'carl@example.com', 'name' => ''],
        ], $attendees);
    }

    public function testParseAttendeesEmptyInput(): void
    {
        $c = $this->newController();

        $this->assertSame(
            [],
            $this->invoke($c, 'parseAttendees', ['  '])
        );
    }

    public function testParseAttendeesRejectsInvalidEmail(): void
    {
        $c = $this->newController();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a valid email address.');

        $this->invoke($c, 'parseAttendees', ['alice@example.com,not-an-email']);
    }

    public function testParseAttendeesRejectsOversizedInput(): void
    {
        $c = $this->newController();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invitee list is too long.');

        $this->invoke($c, 'parseAttendees', [str_repeat('x', 6000)]);
    }

    public function testParseAttendeesEnforcesLimit(): void
    {
        $c = $this->newController();

        $emails = implode(',', array_map(
            static fn (int $i): string => "person$i@example.com",
            range(1, 21)
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A meeting can invite at most 20 people.');

        $this->invoke($c, 'parseAttendees', [$emails]);
    }

    /*
     * confirm(): contact suggestions for the datalist
     */

    public function testConfirmOffersContactSuggestions(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->setUpWritableCalendars();
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example.com/');
        $this->contactsManager->method('search')->willReturn([
            ['FN' => 'Alice Example', 'EMAIL' => 'alice@example.com'],
            ['FN' => 'Bob', 'EMAIL' => ['bob@example.com', 'bob@work.example.com']],
        ]);

        $c = $this->newController();

        $response = $c->confirm(
            'Weekly sync',
            '',
            '2026-10-07T14:15',
            '2026-10-07T15:15'
        );

        $this->assertSame('confirm', $response->getTemplateName());
        $this->assertSame([
            ['email' => 'alice@example.com', 'name' => 'Alice Example'],
            ['email' => 'bob@example.com', 'name' => 'Bob'],
            ['email' => 'bob@work.example.com', 'name' => 'Bob'],
        ], $response->getParams()['contacts']);
    }

    /*
     * create(): invalid invitee input keeps the form usable
     */

    public function testCreateInvalidAttendeeRendersConfirm(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->setUpWritableCalendars();

        $args = $this->validCreateArgs();
        $args[] = 'alice@example.com, not-an-email';

        $c = $this->newController();

        $response = $c->create(...$args);

        $this->assertSame('confirm', $response->getTemplateName());
        $params = $response->getParams();
        $this->assertStringContainsString(
            'is not a valid email address.',
            $params['error']
        );
        $this->assertSame('alice@example.com, not-an-email', $params['attendees']);
        $this->assertSame('5::personal', $params['calendarId']);
    }

    /*
     * searchContacts()
     */

    public function testSearchContactsReturnsMatches(): void
    {
        $this->contactsManager->expects($this->once())->method('search')
            ->with('ali', ['FN', 'EMAIL'])
            ->willReturn([
                ['FN' => 'Alice Example', 'EMAIL' => 'alice@example.com'],
            ]);

        $c = $this->newController();

        $response = $c->searchContacts('ali');

        $this->assertInstanceOf(DataResponse::class, $response);
        $this->assertSame(
            [['email' => 'alice@example.com', 'name' => 'Alice Example']],
            $response->getData()
        );
    }

    public function testSearchContactsCapsResults(): void
    {
        $contacts = array_map(
            static fn (int $i): array => [
                'FN' => "Person $i",
                'EMAIL' => "person$i@example.com",
            ],
            range(1, 60)
        );
        $this->contactsManager->method('search')->willReturn($contacts);

        $c = $this->newController();

        $response = $c->searchContacts('person');

        $this->assertCount(50, $response->getData());
    }

    /*
     * searchContacts(): trivial queries must not reach the
     * address book
     */

    public function testSearchContactsSkipsShortQueries(): void
    {
        $this->contactsManager->expects($this->never())->method('search');

        $c = $this->newController();

        $this->assertSame([], $c->searchContacts('')->getData());
        $this->assertSame([], $c->searchContacts('a')->getData());
        $this->assertSame([], $c->searchContacts(' b ')->getData());
    }

    public function testSearchContactsIsRateLimited(): void
    {
        $method = new \ReflectionMethod(CalendarController::class, 'searchContacts');
        $attributes = $method->getAttributes(UserRateLimit::class);

        $this->assertCount(1, $attributes);

        $limit = $attributes[0]->newInstance();
        $this->assertSame(120, $limit->getLimit());
        $this->assertSame(60, $limit->getPeriod());
    }

    public function testSearchContactsTruncatesQuery(): void
    {
        $this->contactsManager->expects($this->once())->method('search')
            ->willReturnCallback(
                static function (string $query): array {
                    return strlen($query) <= 254
                        ? [['FN' => 'Alice', 'EMAIL' => 'alice@example.com']]
                        : [];
                }
            );

        $c = $this->newController();

        $response = $c->searchContacts(str_repeat('a', 300));

        $this->assertSame(
            [['email' => 'alice@example.com', 'name' => 'Alice']],
            $response->getData()
        );
    }

    public function testContactSuggestionsDropControlCharNames(): void
    {
        $this->contactsManager->method('search')->willReturn([
            ['FN' => "Evil\rName", 'EMAIL' => 'evil@example.com'],
            ['FN' => 'Alice', 'EMAIL' => 'alice@example.com'],
        ]);

        $c = $this->newController();

        $suggestions = $this->invoke($c, 'contactSuggestions', ['']);

        $this->assertSame([
            ['email' => 'evil@example.com', 'name' => ''],
            ['email' => 'alice@example.com', 'name' => 'Alice'],
        ], $suggestions);
    }
}
