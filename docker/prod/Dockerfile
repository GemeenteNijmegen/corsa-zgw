FROM php:8.4-fpm

RUN apt-get update && apt-get upgrade -y && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    zip \
    unzip

# Clean up to reduce image size
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    pcntl \
    bcmath \
    intl \
    zip \
    opcache

# Get Composer (PHP package manager)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Add PHP config
COPY ./php.ini /usr/local/etc/php/conf.d/custom.ini

WORKDIR /var/www
CMD ["php-fpm"]
EXPOSE 9000
