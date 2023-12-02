FROM docker.io/martialblog/limesurvey:6-apache

USER root
RUN apt-get update && apt-get install -y \
	unzip \
	&& rm -rf /var/lib/apt/lists/*

USER 33
ADD --chown=33:33 \
	#--checksum=md5:746ff9ce82c14f247e8aacdafa8b16a4 \
	https://github.com/BDSU/limesurvey-oauth2/releases/download/v1.0.0/AuthOAuth2.zip \
	plugins/
RUN unzip plugins/AuthOAuth2.zip -d plugins && rm -rf plugins/AuthOAuth2.zip
