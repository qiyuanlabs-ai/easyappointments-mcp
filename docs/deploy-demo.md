# Deploy the public demo

This runs the whole demo (Easy!Appointments, MySQL, a mail catcher and the MCP server) on one small Linux machine, with HTTPS for the MCP server from Let's Encrypt. These steps use AWS Lightsail and Cloudflare DNS, but any Ubuntu machine with Docker works.

The demo's Easy!Appointments never sends real email: every message lands in the mail catcher.

## 1. Create the machine

In the Lightsail console, create an instance:

- Platform **Linux/Unix**, blueprint **OS Only > Ubuntu 24.04 LTS**
- A plan with **2 GB memory** (MySQL, Easy!Appointments and the MCP server together need more than 1 GB)
- Name it `ea-mcp-demo`

Then on the instance's **Networking** tab:

- **Attach a static IP** and note it
- In the IPv4 firewall, keep SSH (22) and HTTP (80) and **add HTTPS (443)**

## 2. Point the domain at it

In Cloudflare DNS for `qiyuanlabs.ai`, add:

| Type | Name | Content | Proxy status |
| --- | --- | --- | --- |
| A | `ea-mcp-demo` | the static IP | **DNS only** (grey cloud) |

DNS only lets the server get its own certificate and keeps streamed MCP responses unbuffered.

## 3. Install and start

Open the instance's browser SSH (**Connect using SSH**) and run:

```sh
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker ubuntu
exit
```

Connect again, then:

```sh
sudo git clone https://github.com/qiyuanlabs-ai/easyappointments-mcp.git /opt/ea-mcp-server
sudo chown -R ubuntu:ubuntu /opt/ea-mcp-server
cd /opt/ea-mcp-server

cat > .env <<EOF
COMPOSE_FILE=docker-compose.yml:docker-compose.demo.yml
DEMO_HOST=ea-mcp-demo.qiyuanlabs.ai
EA_DB_ROOT_PASSWORD=$(openssl rand -hex 16)
EOF

cp .env.docker.example .env.docker
sed -i "s|^EA_API_TOKEN=.*|EA_API_TOKEN=$(openssl rand -hex 24)|" .env.docker
docker compose build mcp
sed -i "s|^APP_KEY=.*|APP_KEY=$(docker compose run --rm --no-deps --entrypoint php mcp artisan key:generate --show 2>/dev/null | tail -1)|" .env.docker

docker compose up -d
sh demo/setup.sh
```

`demo/setup.sh` installs Easy!Appointments, seeds the fictional clinic and creates the demo login. Easy!Appointments rate limits its API, so seeding takes a few minutes.

## 4. Check it

```sh
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://ea-mcp-demo.qiyuanlabs.ai/mcp \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d '{}'
```

`401` means HTTPS works and the server is asking for OAuth. Open https://ea-mcp-demo.qiyuanlabs.ai in a browser to see the home page with the demo login. From your own computer you can run the full end-to-end check:

```sh
php demo/e2e.php https://ea-mcp-demo.qiyuanlabs.ai frontdesk@demo.test demo-password-1 front_desk
```

## 5. Reset the demo every night

```sh
crontab -e
```

Add:

```
0 3 * * * cd /opt/ea-mcp-server && sh demo/reset.sh >> /home/ubuntu/ea-demo-reset.log 2>&1
```

## 6. Look at Easy!Appointments and the emails

Both stay private on the machine. From your computer, with the SSH key from the Lightsail console:

```sh
ssh -i LightsailDefaultKey.pem -L 8080:127.0.0.1:8080 -L 8025:127.0.0.1:8025 ubuntu@STATIC_IP
```

Then open http://localhost:8080 (Easy!Appointments, `administrator` / `administrator`; change this password under the admin's profile) and http://localhost:8025 (emails).

## 7. Updates

```sh
cd /opt/ea-mcp-server && git pull && docker compose up -d --build
```

## 8. List it in the MCP Registry

Only once the demo answers as in step 4.

On your own computer (OpenSSL 3; on macOS use Homebrew's `openssl@3`):

```sh
openssl genpkey -algorithm Ed25519 -out mcp-registry-key.pem
echo "v=MCPv1; k=ed25519; p=$(openssl pkey -in mcp-registry-key.pem -pubout -outform DER | tail -c 32 | base64)"
openssl pkey -in mcp-registry-key.pem -noout -text | grep -A3 "priv:" | tail -n +2 | tr -d ' :\n'; echo
```

1. Add the first printed line as a **TXT** record on the apex `qiyuanlabs.ai` (Name `@`) in Cloudflare. It sits next to the existing TXT records.
2. In GitHub, repository **Settings > Environments**, create an environment named `registry` and add a secret `MCP_PRIVATE_KEY` with the second printed line (the private key, hex).
3. Keep `mcp-registry-key.pem` somewhere safe and delete it from shared places.
4. In **Actions > Publish to MCP Registry**, click **Run workflow**.

To publish a new version later, raise `version` in `server.json` first; a published version cannot be overwritten.
