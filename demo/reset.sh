#!/bin/sh
# Nightly reset for the public demo: wipes bookings, customers, providers and services
# in the demo Easy!Appointments and seeds the fictional clinic again with fresh dates.
# Example cron line (03:00 every night):
#   0 3 * * * cd /opt/ea-mcp-server && sh demo/reset.sh >> /var/log/ea-demo-reset.log 2>&1
set -e

set -a
. ./.env.docker
[ -f .env ] && . ./.env
set +a
# Passed through the environment so the password never shows in the process list.
export MYSQL_PWD="${EA_DB_ROOT_PASSWORD:-change-me}"

docker compose exec -T -e MYSQL_PWD ea-db mysql -uroot easyappointments 2>/dev/null <<'SQL'
SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM ea_appointments;
DELETE FROM ea_services_providers;
DELETE FROM ea_secretaries_providers;
DELETE FROM ea_user_settings WHERE id_users IN (SELECT u.id FROM ea_users u JOIN ea_roles r ON r.id = u.id_roles WHERE r.slug <> 'admin');
DELETE u FROM ea_users u JOIN ea_roles r ON r.id = u.id_roles WHERE r.slug <> 'admin';
DELETE FROM ea_services;
SET FOREIGN_KEY_CHECKS = 1;
SQL

sh demo/ea-admin-password.sh
docker compose exec -T mcp php demo/seed-ea.php http://ea
docker compose exec -T mcp php artisan cache:clear >/dev/null
echo "Demo reset at $(date -u +%FT%TZ)"
