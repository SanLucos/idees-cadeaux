FROM php:8.0-fpm

# Install dependencies
RUN apt-get update -y
RUN apt-get autoremove -y
RUN apt-get autoclean -y
RUN apt-get install -y libmcrypt-dev \
    mariadb-client

# Install extensions
RUN docker-php-ext-install pdo_mysql \
    pcntl

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
