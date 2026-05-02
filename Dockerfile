FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libldap2-dev \
    libsqlite3-dev \
    && docker-php-ext-configure ldap \
    && docker-php-ext-install ldap pdo pdo_sqlite \
    && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html

RUN mkdir -p /var/www/html/assets/db \
    && chown -R www-data:www-data /var/www/html/assets/db

EXPOSE 80
