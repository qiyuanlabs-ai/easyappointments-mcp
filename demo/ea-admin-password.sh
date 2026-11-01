#!/bin/sh
# Sets the Easy!Appointments "administrator" password to EA_ADMIN_PASSWORD (from .env), so the
# demo never keeps the installer's default login. Does nothing when EA_ADMIN_PASSWORD is unset,
# as in the local demo. Run from the repository root after the API token is in place.
set -e

set -a
. ./.env.docker
[ -f .env ] && . ./.env
set +a
[ -n "${EA_ADMIN_PASSWORD:-}" ] || exit 0

docker compose exec -T -e EA_ADMIN_PASSWORD -e EA_API_TOKEN mcp php -r '
$ctx = fn ($method, $body = null) => stream_context_create(["http" => [
    "method" => $method, "ignore_errors" => true,
    "header" => "Authorization: Bearer ".getenv("EA_API_TOKEN")."\r\nContent-Type: application/json\r\n",
    "content" => $body,
]]);
$admins = json_decode(file_get_contents("http://ea/index.php/api/v1/admins", false, $ctx("GET")), true);
foreach ($admins ?: [] as $admin) {
    if (($admin["settings"]["username"] ?? null) !== "administrator") continue;
    $body = json_encode(["settings" => ["username" => "administrator", "password" => getenv("EA_ADMIN_PASSWORD")]]);
    file_get_contents("http://ea/index.php/api/v1/admins/".$admin["id"], false, $ctx("PUT", $body));
    if (!str_contains($http_response_header[0] ?? "", " 200")) { fwrite(STDERR, "Could not set the admin password: ".($http_response_header[0] ?? "no response")."\n"); exit(1); }
    echo "Easy!Appointments admin password set from EA_ADMIN_PASSWORD.\n";
    exit(0);
}
fwrite(STDERR, "No Easy!Appointments user named administrator found.\n");
exit(1);
'
