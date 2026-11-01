# Easy!Appointments MCP server: one image that serves HTTPS with FrankenPHP (Caddy + PHP).
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

FROM dunglas/frankenphp:1-php8.4-bookworm

LABEL org.opencontainers.image.title="Easy!Appointments MCP server" \
      org.opencontainers.image.description="Remote MCP server that lets AI assistants read and change bookings in Easy!Appointments, with OAuth, access levels and a call log." \
      org.opencontainers.image.licenses="MIT" \
      io.modelcontextprotocol.server.name="ai.qiyuanlabs/easyappointments"

RUN install-php-extensions pdo_mysql pdo_pgsql pdo_sqlite intl opcache pcntl \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /app
COPY --from=vendor /app /app
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/ea-mcp-entrypoint

RUN chmod +x /usr/local/bin/ea-mcp-entrypoint \
 && mkdir -p /data/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data /data /app/storage /app/bootstrap/cache /config

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/app/database.sqlite \
    SERVER_NAME=":8080" \
    EA_MCP_DATA_DIR=/data/app

USER www-data
EXPOSE 8080
VOLUME ["/data/app"]
ENTRYPOINT ["ea-mcp-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
