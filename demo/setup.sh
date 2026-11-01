#!/bin/sh
# Sets up the docker-compose demo: installs Easy!Appointments, gives it an API token,
# seeds the fictional clinic and links the MCP server to it. Run from the repository root.
set -e

set -a
. ./.env.docker
[ -f .env ] && . ./.env
set +a
: "${EA_API_TOKEN:?Set EA_API_TOKEN in .env.docker}"
DB_PASSWORD="${EA_DB_ROOT_PASSWORD:-change-me}"

echo "Waiting for Easy!Appointments..."
until docker compose exec -T ea php -r 'exit(@fsockopen("ea-db", 3306) ? 0 : 1);' 2>/dev/null; do sleep 2; done

# Secrets go through the environment and stdin, never a command line that shows up in ps.
export MYSQL_PWD="$DB_PASSWORD"
if ! docker compose exec -T -e MYSQL_PWD ea-db mysql -uroot easyappointments -e 'SELECT 1 FROM ea_settings LIMIT 1' >/dev/null 2>&1; then
  echo "Installing Easy!Appointments..."
  docker compose exec -T -w /var/www/html ea php index.php console install
fi

printf "UPDATE ea_settings SET value='%s' WHERE name='api_token';\n" "$EA_API_TOKEN" \
  | docker compose exec -T -e MYSQL_PWD ea-db mysql -uroot easyappointments 2>/dev/null

sh demo/ea-admin-password.sh

echo "Seeding the fictional clinic (Easy!Appointments rate limits its API, so this can take a few minutes)..."
docker compose exec -T mcp php demo/seed-ea.php http://ea

# The token comes from EA_API_TOKEN in .env.docker, which the container already has, so it never appears in a command line.
docker compose exec -T mcp php artisan ea:connect "Maple Street Physio (demo)" http://ea
docker compose exec -T mcp php artisan ea:user "${DEMO_EMAIL:-frontdesk@demo.test}" --name="Front desk (demo)" \
  --password="${DEMO_PASSWORD:-demo-password-1}" --level=front_desk
docker compose exec -T mcp php artisan cache:clear >/dev/null

echo "Done. MCP server: http://localhost:8000/mcp  Sign in as ${DEMO_EMAIL:-frontdesk@demo.test}"
