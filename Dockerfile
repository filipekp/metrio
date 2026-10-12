FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql zip curl \
    && rm -rf /var/lib/apt/lists/*
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && a2enmod rewrite headers
WORKDIR /var/www/html
