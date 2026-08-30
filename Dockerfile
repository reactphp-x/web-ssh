############################################
# Base Image (CLI for ReactPHP worker)
############################################
FROM serversideup/php:8.2-cli AS base

############################################
# Development Image
############################################
FROM base AS development

USER root

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN install-php-extensions sqlite3 uv \
    && apt-get update \
    && apt-get install -y --no-install-recommends openssh-client \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-serversideup-set-id www-data ${USER_ID}:${GROUP_ID} \
    && docker-php-serversideup-set-file-permissions --owner ${USER_ID}:${GROUP_ID}

WORKDIR /var/www/html

USER www-data

############################################
# Production Image
############################################
FROM development AS production

USER root
COPY --chown=www-data:www-data . /var/www/html
USER www-data

RUN composer install --no-dev --optimize-autoloader --no-interaction

CMD ["php", "start.php", "start", "-d"]
