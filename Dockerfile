FROM php:8.2-apache

# The PHP backend uses cURL for external API requests.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite \
    && a2enmod setenvif \
    && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf \
    && sed -i '\|CustomLog .*access.log combined|d' /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' \
        'SetEnvIf Request_URI "^/api(/|$)" api_request' \
        'SetEnvIfNoCase Request_URI "\.(js|css|png|jpe?g|gif|ico|svg|woff2?)$" !api_request' \
        'CustomLog ${APACHE_LOG_DIR}/access.log combined env=api_request' \
        > /etc/apache2/conf-available/api-logging.conf \
    && a2enconf api-logging \
    && printf '%s\n' \
        'display_errors=Off' \
        'log_errors=On' \
        'error_reporting=E_ALL' \
        'error_log=/proc/self/fd/2' \
        > /usr/local/etc/php/conf.d/zz-render-logging.ini

WORKDIR /var/www/html

# .dockerignore keeps local credentials and generated data out of the image.
COPY . /var/www/html

EXPOSE 10000
