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

FROM alpine:3.20 AS plugin_zips

# Space-separated list of plugin specs in the format `<directory-name>@<zip-url>`.
# Example:
#   --build-arg PLUGINS="AuthSurvey@https://example.com/AuthSurvey.zip UserAuditLogPlugin@https://example.com/UserAuditLogPlugin.zip"
ARG PLUGINS=""

WORKDIR /tmp/plugins

RUN apk add --no-cache curl unzip \
    && mkdir -p /out \
    && if [ -z "${PLUGINS}" ]; then \
         echo "No plugins configured; skipping plugin download."; \
       else \
         set -- ${PLUGINS}; \
         plugin_count="$#"; \
         index=1; \
         for plugin_spec in "$@"; do \
           plugin_dir_name="${plugin_spec%%@*}"; \
           plugin_url="${plugin_spec#*@}"; \
           if [ -z "${plugin_dir_name}" ] || [ -z "${plugin_url}" ] || [ "${plugin_spec}" = "${plugin_dir_name}" ]; then \
             echo "Invalid plugin spec '${plugin_spec}'. Expected format '<directory-name>@<zip-url>'." >&2; \
             exit 1; \
           fi; \
           workdir="/tmp/plugins/plugin-${index}"; \
           mkdir -p "${workdir}"; \
           echo "Downloading plugin ${index}/${plugin_count}: ${plugin_dir_name}"; \
           curl -fsSL "${plugin_url}" -o "${workdir}/plugin.zip"; \
           test -s "${workdir}/plugin.zip"; \
           unzip -tq "${workdir}/plugin.zip"; \
           unzip -q "${workdir}/plugin.zip" -d "${workdir}/unzipped"; \
           if [ -d "${workdir}/unzipped/${plugin_dir_name}" ]; then \
             cp -R "${workdir}/unzipped/${plugin_dir_name}" "/out/${plugin_dir_name}"; \
           else \
             mkdir -p "/out/${plugin_dir_name}"; \
             cp -R "${workdir}/unzipped/." "/out/${plugin_dir_name}/"; \
           fi; \
           test -d "/out/${plugin_dir_name}"; \
           echo "Installed plugin: ${plugin_dir_name}"; \
           index=$((index + 1)); \
         done; \
       fi

FROM docker.io/martialblog/limesurvey:6-apache

USER root
COPY --from=vendor --chown=33:33 /app/AuthOAuth2 /var/www/html/plugins/AuthOAuth2
COPY --from=plugin_zips --chown=33:33 /out/ /var/www/html/plugins/

# Fix KCFinder issue (PHP 8.1 compatibility and permissions)
COPY kcfinder.conf /etc/apache2/conf-enabled/kcfinder.conf
# Tune PHP for long-running LimeSurvey/eCRF surveys with large forms and long sessions.
RUN set -eux; \
    { \
      echo '; LimeSurvey/eCRF tuning'; \
      echo '; Keep PHP sessions valid for 6 hours so long surveys are not garbage-collected too early.'; \
      echo 'session.gc_maxlifetime = 21600'; \
      echo 'session.cookie_lifetime = 0'; \
      echo 'session.cookie_samesite = Lax'; \
      echo 'session.cookie_httponly = 1'; \
      echo '; Allow large eCRF pages with many questions/subquestions without truncating submitted fields.'; \
      echo 'max_input_vars = 20000'; \
      echo 'max_input_nesting_level = 128'; \
      echo '; Allow larger survey payloads and file uploads.'; \
      echo 'post_max_size = 128M'; \
      echo 'upload_max_filesize = 128M'; \
      echo '; Give long submit/save requests enough time to finish.'; \
      echo 'max_execution_time = 21600'; \
      echo 'max_input_time = 21600'; \
      echo '; Give PHP enough memory for large survey pages and exports.'; \
      echo 'memory_limit = 512M'; \
    } > /usr/local/etc/php/conf.d/limesurvey-custom.ini

USER 33