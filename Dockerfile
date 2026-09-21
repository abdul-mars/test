# QRoute — single-container image.
#
# No Composer stage: the application has no PHP dependencies, which keeps
# the image small and the supply chain to exactly the PHP runtime.

FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache nginx supervisor freetype libpng libjpeg-turbo icu-libs \
 && apk add --no-cache --virtual .build-deps \
      freetype-dev libpng-dev libjpeg-turbo-dev icu-dev $PHPIZE_DEPS \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd pdo_mysql intl opcache \
 && apk del .build-deps

# Production PHP settings. OPcache matters: the redirect path is the hot
# path, and recompiling it on every scan is pure waste.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.max_accelerated_files=10000'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.interned_strings_buffer=16'; \
      echo 'realpath_cache_size=4096K'; \
      echo 'realpath_cache_ttl=600'; \
      echo 'expose_php=Off'; \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'error_log=/dev/stderr'; \
      echo 'memory_limit=128M'; \
      echo 'post_max_size=4M'; \
      echo 'upload_max_filesize=2M'; \
    } > /usr/local/etc/php/conf.d/zz-qroute.ini

WORKDIR /var/www/qroute
COPY . .

# storage/ must be writable for the SQLite database and its WAL files.
RUN mkdir -p storage \
 && chown -R www-data:www-data /var/www/qroute/storage \
 && rm -f .env

COPY deploy/docker/nginx.conf /etc/nginx/nginx.conf
COPY deploy/docker/supervisord.conf /etc/supervisord.conf
COPY deploy/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD wget -qO- http://127.0.0.1:8080/health || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
