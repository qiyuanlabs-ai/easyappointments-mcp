#!/bin/sh
# Installs or updates the public demo on an Ubuntu machine, in one go:
#   curl -fsSL https://raw.githubusercontent.com/qiyuanlabs-ai/easyappointments-mcp/main/demo/install-server.sh | sudo sh
# Safe to run again: it keeps existing secrets and data, pulls the latest code and restarts.
set -e

DEMO_HOST="${DEMO_HOST:-ea-mcp-demo.qiyuanlabs.ai}"
REPO="${REPO:-https://github.com/qiyuanlabs-ai/easyappointments-mcp.git}"
DIR="${DIR:-/opt/ea-mcp-server}"

command -v docker >/dev/null || curl -fsSL https://get.docker.com | sh
command -v git >/dev/null || { apt-get update -q && apt-get install -yq git; }

[ -d "$DIR/.git" ] || git clone "$REPO" "$DIR"
cd "$DIR"
git pull --ff-only

if [ ! -f .env ]; then
  cat > .env <<ENV
COMPOSE_FILE=docker-compose.yml:docker-compose.demo.yml
DEMO_HOST=$DEMO_HOST
EA_DB_ROOT_PASSWORD=$(openssl rand -hex 16)
ENV
fi

if [ ! -f .env.docker ]; then
  cp .env.docker.example .env.docker
  sed -i "s|^EA_API_TOKEN=.*|EA_API_TOKEN=$(openssl rand -hex 24)|" .env.docker
fi
grep -q '^EA_ADMIN_PASSWORD=' .env || echo "EA_ADMIN_PASSWORD=$(openssl rand -hex 12)" >> .env
chmod 600 .env .env.docker

docker compose build mcp
if grep -q '^APP_KEY=$' .env.docker; then
  key="$(docker compose run --rm --no-deps --entrypoint php mcp artisan key:generate --show 2>/dev/null | tail -1)"
  sed -i "s|^APP_KEY=.*|APP_KEY=$key|" .env.docker
fi

docker compose up -d
sh demo/setup.sh

cat > /etc/cron.d/ea-mcp-demo-reset <<CRON
0 3 * * * root cd $DIR && sh demo/reset.sh >> /var/log/ea-mcp-demo-reset.log 2>&1
CRON

echo
echo "Demo is up. Once DNS for $DEMO_HOST points here, open https://$DEMO_HOST"
echo "Easy!Appointments admin (SSH tunnel only): administrator, password in $DIR/.env (EA_ADMIN_PASSWORD)"
