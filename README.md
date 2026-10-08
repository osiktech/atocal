# AToCal — Add To Calendar

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
the event and submits the form. The event lands in the user's
"Personal" calendar and the browser opens the Calendar app.

## Routes

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/apps/atocal/confirm` | Show confirmation page |
| POST | `/apps/atocal/create` | Create the event |

## Link parameters (GET `/confirm` and form POST `/create`)

| Parameter | Required | Rules |
|-----------|----------|-------|
| `summary` | yes | max 255 chars |
| `start` | yes | datetime-local, e.g. `2026-10-07T14:15` |
| `end` | yes | after `start`, same format |
| `description` | no | max 10000 chars |
| `location` | no | HTTPS URL |
| `url` | no | HTTPS URL; merged into description |
| `organizer` | no | email address |

Dates carry no timezone and are interpreted in the user's Nextcloud
timezone (server default as fallback).

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
- A writable calendar named "Personal"
  (event creation fails with a friendly error otherwise)

## Install

Copy this directory to the Nextcloud server as
`custom_apps/atocal`, then:

```
occ app:enable atocal
```

## Structure

```
appinfo/info.xml          app metadata
appinfo/routes.php        routes
lib/AppInfo/Application.php   container setup
lib/Controller/CalendarController.php   confirm/create logic
templates/confirm.php    confirmation page
templates/style.css       page styles
```
