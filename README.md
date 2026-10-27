# AToCal — Add To Calendar

> **Vibe-coded:** AToCal is written by Mistral AI's coding agent, not by hand.
> The maintainer sets the goals, makes the product decisions and tries the app
> out in the browser; there has been no independent human code review and
> no security audit.

A Nextcloud app that lets remote applications create events in the
authenticated user's Nextcloud Calendar via a simple link.

## How it works

```
remote app                  Nextcloud (atocal)
    |                              |
    |  GET /apps/atocal/confirm    |
    |  summary, start, end, ...    |
    |----------------------------->|
    |                              |  confirm page
    |<-----------------------------|
    |                              |
    |  POST /apps/atocal/create    |  (CSRF token from form)
    |----------------------------->|
    |                              |  event in "Personal" calendar
    |<-----------------------------|  redirect to Calendar app
```

The remote app links the user to the confirm page. The user reviews
the event and submits the form. The event lands in a calendar the user
chooses. The user adds invitees/attendees to the event, who receive
an invitation, as long as the user owns the calendar.
After the event is created the browser opens the Calendar app.

## Routes

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/apps/atocal/confirm` | Show confirmation page |
| POST | `/apps/atocal/create` | Create the event |
| GET | `/apps/atocal/contacts/search` | Contact search for the invitee input |

## Link parameters (GET `/confirm` and form POST `/create`)

| Parameter | Required | Rules |
|-----------|----------|-------|
| `summary` | yes | max 255 chars |
| `start` | yes | datetime-local, e.g. `2026-10-07T14:15` |
| `end` | yes | after `start`, same format |
| `description` | no | max 10000 chars |
| `location` | no | HTTPS URL |
| `url` | no | HTTPS URL; merged into description |
| `organizer` | no | email address; display info only |

The create form adds `calendarId` (selected calendar) and
`attendees` (emails, max 20).

Dates carry no timezone and are interpreted in the user's Nextcloud
timezone (server default as fallback).

## Organizer

The event's ICS `ORGANIZER` is the Nextcloud user's own email
address. Invitations to attendees are scheduled by the CalDAV
backend in the calendar owner's name, which only works when the
organizer matches the owner.

The `organizer` link parameter is shown on the confirm page as
meeting information and is used as `ORGANIZER` fallback only when
the user has no profile email — in that case no working
invitations may be sent, so profile emails should be set.

## Reminder

Every created event gets a display alarm 15 minutes before it starts
(`TRIGGER:-PT15M` VALARM). The reminder is not configurable.

Example:

```
https://cloud.example.com/apps/atocal/confirm?summary=Kickoff&start=2026-10-12T10:00&end=2026-10-12T11:00&location=https%3A%2F%2Fmeet.example.com%2Fkickoff
```

## Requirements

- Nextcloud 34 or 35
- Calendar app enabled
- A writable calendar ("Personal" is preselected if present)

## Install

Copy this directory to the Nextcloud server as
`apps/atocal`, then:

```
occ app:enable atocal
```

## Structure

```
appinfo/info.xml                        app metadata
appinfo/routes.php                      routes
css/style.css                           page styles
js/invitees.js                          invitee input
l10n/*.json                             translations
lib/AppInfo/Application.php             container setup
lib/Controller/CalendarController.php   confirm/create logic
templates/confirm.php                   confirmation page
```

## Licence

AGPL-3.0-or-later, see LICENSE. AToCal comes without any warranty.

AToCal is an independent project and is not affiliated with any other product or company of a similar name.
