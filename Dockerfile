FROM composer:2 AS vendor

WORKDIR /app
RUN apk add --no-cache git \
    && git clone --depth 1 https://github.com/SondagesPro/limesurvey-oauth2.git AuthOAuth2 \
    && cd AuthOAuth2 \
    && composer install --no-dev --optimize-autoloader \
    && rm -rf .git composer.json composer.lock /var/lib/apt/lists/*

FROM docker.io/martialblog/limesurvey:6-apache

USER root
COPY --from=vendor --chown=33:33 /app/AuthOAuth2 /var/www/html/plugins/AuthOAuth2

USER 33