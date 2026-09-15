FROM php:8.2-cli

RUN docker-php-ext-install pdo_mysql pdo_pgsql

WORKDIR /app
COPY . .

# Render assigns the actual port via $PORT at runtime — the shell form of
# CMD is required so that variable actually expands (the exec form would
# pass the literal string "$PORT" to php). Same php -S ... -t public
# public/index.php command used and tested throughout local development —
# no behavior change, just binding to 0.0.0.0 and Render's assigned port
# instead of 127.0.0.1:8000.
CMD php -S 0.0.0.0:$PORT -t public public/index.php
