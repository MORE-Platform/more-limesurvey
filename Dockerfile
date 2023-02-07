FROM --platform=linux/amd64 docker.io/martialblog/limesurvey:latest

USER root

# install composer
RUN curl -sS https://getcomposer.org/installer | php \
      && chmod +x composer.phar && mv composer.phar /usr/local/bin/composer

RUN apt-get update && apt-get install unzip

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html
ADD https://github.com/BDSU/limesurvey-oauth2/releases/download/v1.0.0/AuthOAuth2.zip upload/plugins
RUN cd upload/plugins; unzip AuthOAuth2.zip; rm -r AuthOAuth2.zip
RUN cd upload/plugins/AuthOAuth2; composer install

USER $User
