FROM php:8.3-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache \
    bash \
    ca-certificates \
    curl \
    gettext \
    libxml2-dev \
    libzip-dev \
    nginx \
    nodejs \
    npm \
    unzip \
    zip \
    && docker-php-ext-install bcmath opcache pdo_mysql soap zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# El trabajador necesita señales para cumplir sus límites y terminar el trabajo antes de salir.
RUN docker-php-ext-install pcntl

# Git solo pertenece a la imagen de ensayos; la publicación normal no administra repositorios.
ARG INSTALL_DEV_DEPENDENCIES=false
RUN if [ "$INSTALL_DEV_DEPENDENCIES" = "true" ]; then apk add --no-cache git; fi

RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.enable_cli=0"; \
    echo "opcache.memory_consumption=128"; \
    echo "opcache.interned_strings_buffer=16"; \
    echo "opcache.max_accelerated_files=20000"; \
    echo "opcache.validate_timestamps=0"; \
    echo "opcache.save_comments=1"; \
    echo "opcache.jit=0"; \
    echo "opcache.jit_buffer_size=0"; \
    } > /usr/local/etc/php/conf.d/opcache-production.ini

COPY composer.json composer.lock package.json package-lock.json ./

# Las dependencias solo se reinstalan cuando cambian sus archivos de bloqueo.
RUN if [ "$INSTALL_DEV_DEPENDENCIES" = "true" ]; then composer install --no-scripts --no-autoloader --no-interaction --prefer-dist; else composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist; fi \
    && npm ci --include=dev

COPY . .

# Un umask privado al extraer fuentes no debe impedir su lectura por PHP o Nginx.
RUN find . -path './vendor' -prune -o -path './node_modules' -prune -o -type d -exec chmod 755 {} + \
    && if [ "$INSTALL_DEV_DEPENDENCIES" = "true" ]; then printf '# Entorno de ensayos sin secretos; las variables se inyectan externamente.\n' > .env; composer dump-autoload --optimize --no-interaction; else composer dump-autoload --no-dev --optimize --no-interaction; fi \
    && npm run build \
    && npm cache clean --force \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY docker/start.sh /usr/local/bin/start.sh

RUN chmod +x /usr/local/bin/start.sh

ARG APP_VERSION=sin-identificar
ARG SOURCE_COMMIT=sin-identificar
ARG BUILD_TIMESTAMP=sin-identificar
ENV APP_VERSION=${APP_VERSION} SOURCE_COMMIT=${SOURCE_COMMIT} BUILD_TIMESTAMP=${BUILD_TIMESTAMP} PORT=10000
LABEL org.opencontainers.image.version=${APP_VERSION} org.opencontainers.image.revision=${SOURCE_COMMIT} org.opencontainers.image.created=${BUILD_TIMESTAMP}

EXPOSE 10000

CMD ["/usr/local/bin/start.sh"]
