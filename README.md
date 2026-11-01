# Easy!Appointments MCP server

A remote [MCP](https://modelcontextprotocol.io) server that lets Claude, ChatGPT and other AI assistants work the front desk of an [Easy!Appointments](https://easyappointments.org) booking system: find open slots, look up customers, and book, move or cancel appointments.

It is built for the people who run the booking system, not only for developers:

- **Sign in with OAuth.** Staff connect their AI assistant with their own login. The Easy!Appointments API token never leaves the server.
- **Two access levels per connection.** *Read only* sees services, open times, customers and bookings. *Front desk* can also book, reschedule and cancel. Each person has a ceiling set by an administrator.
- **Nothing changes without a preview.** Every change is a two-step call: a preview the assistant shows to the person, then a confirmed call that carries the preview's signed `confirmation_token` and an idempotency key. A confirm that does not match a preview is refused, and a retried request never books twice.
- **A call log.** Every tool call is recorded with masked personal details, the Easy!Appointments requests it made and how long it took. Staff can see it, export it as CSV and disconnect any assistant.
- **Gentle on Easy!Appointments.** The API allows 100 requests per IP every two minutes. The server caches the catalog and open times, caps how many days one search covers, and keeps one shared request budget per booking system (`EA_REQUEST_BUDGET`, 90 per two minutes by default) so many assistants together cannot lock it out.

Not affiliated with or endorsed by Easy!Appointments. Easy!Appointments is a trademark of its owner.

## Tools

| Tool | Access | What it does |
| --- | --- | --- |
| `list_services` | Read only | Services with duration, price and who offers them, plus each provider's time zone |
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

To put the demo on a public server with HTTPS, follow [docs/deploy-demo.md](docs/deploy-demo.md).

## Install for a real booking system

With Docker:

```sh
docker run -d --name ea-mcp \
  -e APP_KEY=base64:... \
  -e APP_URL=https://mcp.example.com \
  -e TRUSTED_PROXIES=10.0.0.5 \
  -v ea-mcp-data:/data/app \
  -p 127.0.0.1:8080:8080 \
  ea-mcp-server
```

Put it behind your HTTPS load balancer or reverse proxy, or set `SERVER_NAME=mcp.example.com` and publish ports 80 and 443 to let the built-in Caddy get a certificate.

`TRUSTED_PROXIES` decides whose `X-Forwarded-For` header is believed, and rate limits are per client IP. Set it to your proxy's address only, and publish port 8080 only to that proxy (or to `127.0.0.1` when the proxy runs on the same host). Never use `*` while the port can be reached directly, because anyone could then pick their own IP and slip past the rate limits. Leave it unset when the built-in Caddy handles HTTPS. Run `php artisan schedule:work` in a second container from the same image to prune old logs and expired tokens daily.

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
- Before sign-in, `initialize` and `tools/list` answer without a token, so a client can show what the server offers. Any `tools/call` (and everything else) without a valid token gets `401` with a `WWW-Authenticate` header pointing at the OAuth metadata, which is what starts sign-in in Claude and other clients. After sign-in, tools a connection may not use are not listed, and are refused if called anyway.
- Writes need a confirmed second call. The confirm must carry the `confirmation_token` from a preview of exactly the same arguments, by the same person and assistant, within 10 minutes (`MCP_CONFIRMATION_MINUTES`), plus an idempotency key. The key is claimed before anything is written, so parallel retries with one key book once.
- Easy!Appointments does not check availability when the API creates or moves a booking. So each confirm takes a lock for that provider and day, checks the slot again uncached inside the lock, and only then writes. Two confirms for the same slot with different keys book it once; the other is refused with the nearest open times.
- If the answer from Easy!Appointments is lost, or it answers with a server error, the key stays claimed and the assistant is told to check with `list_appointments` instead of retrying blindly. Once Easy!Appointments confirms a write, nothing that happens afterwards (such as reading names back) can turn it into an error or undo it.
- Times are read and written in each provider's own time zone, as Easy!Appointments stores them, and every result says which zone it uses.
- Client registration is open, as MCP expects, but limited: redirect URIs must be `https` (or `http` on a loopback address) with no fragment or user info, registration is rate limited per IP (`MCP_REGISTRATIONS_PER_HOUR`, 10), and clients that are never used are removed after a day and unused ones after 90 days.
- The consent screen marks the client's name as unverified (any app can pick its own name), shows the host the person will be sent to, and warns when that host is not a known AI assistant (`MCP_KNOWN_CLIENT_HOSTS`).
- Names, emails, phone numbers, addresses, notes and search text are masked in the call log. Easy!Appointments error messages that echo customer records are stripped, and any email left in an error is masked, before it reaches the assistant, the call log or the application log.
- Sign-in attempts are limited per IP and, separately, per account, so guesses spread over many IPs still hit a limit (`MCP_LOGIN_ATTEMPTS_PER_ACCOUNT`, 10 per 10 minutes). The public demo account set in `DEMO_EMAIL` is exempt from the per-account limit, because its password is published and the limit would only let strangers lock it. Session cookies are HTTPS-only whenever `APP_URL` is https.
- A booking for a new customer takes budget for all its requests before it starts, and removes the customer it created if Easy!Appointments then clearly refuses the booking (a 4xx). After no answer or a server error the booking may exist, so the customer stays and the assistant is told to check with `list_appointments`.
- `APP_ENV=production` and `APP_DEBUG=false` in `.env.example`. `ea:connect` reads the API token from a prompt or `EA_API_TOKEN`, so it does not end up in shell history or the process list.
- Logs are kept 90 days by default (`MCP_LOG_RETENTION_DAYS`).

## Known limits

- The Easy!Appointments API cannot check availability while ignoring the booking being moved, so a reschedule that overlaps its own current time is refused with an explanation. Cancel and rebook in that case.
- Cancelling deletes the booking in Easy!Appointments; there is no soft cancel in the API.
- The slot lock covers changes made through this server. A person booking the same slot in Easy!Appointments itself at the same moment can still cause a double booking, because its API cannot reserve a slot.
- Easy!Appointments does not store which time zone a request meant, so a provider whose time zone changes later keeps their existing bookings at the same wall-clock time.
- One server can link several Easy!Appointments instances, one per account. There is no self-service sign-up; an administrator adds people with `ea:user`.

## Development

```sh
composer install
cp .env.example .env && sed -i 's/^APP_ENV=production/APP_ENV=local/' .env
php artisan key:generate && php artisan passport:keys && php artisan migrate
php artisan test
vendor/bin/pint
```

Tests run against an in-memory fake of the Easy!Appointments API (`tests/Support/FakeEasyAppointments.php`). `demo/e2e.php` runs against a real instance.

## License

MIT
