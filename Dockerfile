FROM php:8.2-cli

# pdo_pgsql's build needs libpq's headers (libpq-fe.h), which the base
# image doesn't ship — pdo_mysql has no equivalent external dependency.
# libpq-dev is kept (not purged after build): it pulls in libpq5, whose
# shared library pdo_pgsql still needs at runtime, not just to compile.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . .

# Render assigns the actual port via $PORT at runtime — the shell form of
# CMD is required so that variable actually expands (the exec form would
# pass the literal string "$PORT" to php). Same php -S ... -t public
# public/index.php command used and tested throughout local development —
# no behavior change, just binding to 0.0.0.0 and Render's assigned port
# instead of 127.0.0.1:8000.
CMD php -S 0.0.0.0:$PORT -t public public/index.php
