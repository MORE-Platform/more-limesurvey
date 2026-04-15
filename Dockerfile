FROM composer:2 AS vendor

WORKDIR /app

# No tags or releases are available so I pinned the latest commit from the masterbranch dating '04.02.2025'
RUN apk add --no-cache git \
    && mkdir AuthOAuth2 && cd AuthOAuth2 \
    && git init \
    && git remote add origin https://github.com/SondagesPro/limesurvey-oauth2.git \
    && git fetch --depth 1 origin 738b27c5ad9c0e782c28f9b8347a9d2bc57c3851 \
    && git checkout FETCH_HEAD \
    && composer install --no-dev --optimize-autoloader \
    && rm -rf .git composer.json composer.lock /var/lib/apt/lists/*

FROM docker.io/martialblog/limesurvey:6-apache

USER root
COPY --from=vendor --chown=33:33 /app/AuthOAuth2 /var/www/html/plugins/AuthOAuth2
COPY --chown=33:33 customPlugins/HelloWorld /var/www/html/plugins/HelloWorld
COPY --chown=33:33 customPlugins/UserAuditLogPlugin /var/www/html/plugins/UserAuditLogPlugin

USER 33