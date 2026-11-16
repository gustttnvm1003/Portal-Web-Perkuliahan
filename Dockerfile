FROM php:8.2-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev libonig-dev \
    && docker-php-ext-install pdo_sqlite mbstring \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

RUN printf "upload_max_filesize=10M\npost_max_size=12M\nmemory_limit=128M\n" > /usr/local/etc/php/conf.d/serambi.ini

EXPOSE 8080
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} router.php"]

