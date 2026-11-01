# Easy!Appointments MCP server

A remote [MCP](https://modelcontextprotocol.io) server that lets Claude, ChatGPT and other AI assistants work the front desk of an [Easy!Appointments](https://easyappointments.org) booking system: find open slots, look up customers, and book, move or cancel appointments.

It is built for the people who run the booking system, not only for developers:

- **Sign in with OAuth.** Staff connect their AI assistant with their own login. The Easy!Appointments API token never leaves the server.
- **Two access levels per connection.** *Read only* sees services, open times, customers and bookings. *Front desk* can also book, reschedule and cancel. Each person has a ceiling set by an administrator.
- **Nothing changes without a preview.** Every change is a two-step call: a preview the assistant shows to the person, then a confirmed call with an idempotency key, so a retried request never books twice.
- **A call log.** Every tool call is recorded with masked personal details, the Easy!Appointments requests it made and how long it took. Staff can see it, export it as CSV and disconnect any assistant.
- **Gentle on Easy!Appointments.** The API allows 100 requests per IP every two minutes. The server caches the catalog and open times and caps how many days one search covers.

Not affiliated with or endorsed by Easy!Appointments. Easy!Appointments is a trademark of its owner.

## Tools

| Tool | Access | What it does |
| --- | --- | --- |
| `list_services` | Read only | Services with duration, price and who offers them, plus the business time zone |
| `find_open_slots` | Read only | Open start times for a service over up to 14 days, for one provider or all, filtered by morning, afternoon or evening |
| `find_customer` | Read only | Customers by name, email or phone |
| `list_appointments` | Read only | Bookings for a customer, a provider or a date range |
| `book_appointment` | Front desk | Books for an existing or new customer |
| `reschedule_appointment` | Front desk | Moves a booking to a new time or provider |
| `cancel_appointment` | Front desk | Cancels a future booking |

Easy!Appointments sends its usual confirmation, change and cancellation emails for every change made through the API.

## Requirements

- Easy!Appointments 1.5 or newer with the API enabled (Settings > Integrations > API: set an API token). Tested with 1.6.1.
- PHP 8.3 or newer, or Docker.
- HTTPS in production. AI clients will not connect to a plain HTTP server on the internet.

## Run the demo locally

The compose file starts Easy!Appointments 1.6.1, a mail catcher and this server, then seeds a fictional physio clinic with services, providers, customers and two weeks of bookings.

```sh
cp .env.docker.example .env.docker
# Set APP_KEY (see the comment in the file) and EA_API_TOKEN (any long random string)
docker compose up -d --build
sh demo/setup.sh
```

Then:

- MCP server: http://localhost:8000/mcp (sign in as `frontdesk@demo.test` / `demo-password-1`)
- Easy!Appointments: http://localhost:8080 (`administrator` / `administrator`)
- Emails Easy!Appointments sent: http://localhost:8025

To check the whole flow without an AI client, run the end-to-end script. It registers an OAuth client, signs in, picks an access level and calls every tool, including a real booking that it then moves and cancels:

```sh
php demo/e2e.php http://localhost:8000 frontdesk@demo.test demo-password-1 front_desk
php demo/e2e.php http://localhost:8000 frontdesk@demo.test demo-password-1 read
```

## Install for a real booking system

With Docker:

```sh
docker run -d --name ea-mcp \
  -e APP_KEY=base64:... \
  -e APP_URL=https://mcp.example.com \
  -e TRUSTED_PROXIES='*' \
  -v ea-mcp-data:/data/app \
  -p 8080:8080 \
  ea-mcp-server
```

Put it behind your HTTPS load balancer or reverse proxy, or set `SERVER_NAME=mcp.example.com` and publish ports 80 and 443 to let the built-in Caddy get a certificate. Run `php artisan schedule:work` in a second container from the same image to prune old logs and expired tokens daily.

Without Docker it is a standard Laravel 13 app: `composer install --no-dev`, copy `.env.example` to `.env`, `php artisan key:generate`, `php artisan passport:keys`, `php artisan migrate`, and point your web server at `public/`.

Then link the booking system and add staff:

```sh
php artisan ea:connect "Maple Street Physio" https://booking.example.com   # asks for the API token
php artisan ea:user anna@example.com --name="Anna" --level=front_desk       # asks for a password
```

`--level` is the most that person may grant an assistant. They still choose per assistant on the consent screen.

If `ea:connect` gets a 401 from a working token, your web server is not passing the `Authorization` header to PHP. On Apache with PHP-FPM add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` to the Easy!Appointments vhost.

## Connect an AI assistant

Add `https://your-server/mcp` as a custom connector (Claude: Settings > Connectors; ChatGPT: a workspace connector in developer mode). The client registers itself, opens the sign-in page, and the person picks *Read only* or *Front desk*. They can change or remove that at any time on `/dashboard`.

## Security model

- The Easy!Appointments API token is admin-level, so it is stored encrypted on this server and never sent to clients. Each person signs in with their own account and gets a short-lived token (1 hour, refreshable for 30 days) that only works here.
- Tools a connection may not use are not listed to the assistant, and are refused if called anyway.
- Writes need a confirmed second call with an idempotency key. The slot is checked again, uncached, right before booking.
- Names, emails, phone numbers, addresses, notes and search text are masked in the call log. Easy!Appointments error messages that echo customer records are stripped before they reach the assistant or the log.
- Logs are kept 90 days by default (`MCP_LOG_RETENTION_DAYS`).

## Known limits

- The Easy!Appointments API cannot check availability while ignoring the booking being moved, so a reschedule that overlaps its own current time is refused with an explanation. Cancel and rebook in that case.
- Cancelling deletes the booking in Easy!Appointments; there is no soft cancel in the API.
- One server can link several Easy!Appointments instances, one per account. There is no self-service sign-up; an administrator adds people with `ea:user`.

## Development

```sh
composer install
cp .env.example .env && php artisan key:generate && php artisan passport:keys && php artisan migrate
php artisan test
vendor/bin/pint
```

Tests run against an in-memory fake of the Easy!Appointments API (`tests/Support/FakeEasyAppointments.php`). `demo/e2e.php` runs against a real instance.

## License

MIT
