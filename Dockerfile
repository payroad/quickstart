FROM php:8.2-cli-alpine

RUN apk add --no-cache git unzip sqlite-dev \
 && docker-php-ext-install bcmath pdo pdo_sqlite \
 && echo "opcache.enable=0" > /usr/local/etc/php/conf.d/opcache-disable.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

# Mount payroad packages from sibling directories
# (composer resolves path repositories at install time)
RUN composer install --no-interaction --no-progress --prefer-dist

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
