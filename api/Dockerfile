FROM php:8.4-fpm

# System dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    unzip \
    nginx \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        bcmath \
        intl \
        opcache \
        pcntl \
        mbstring

# Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# PHP configuration
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# Nginx configuration
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Supervisor configuration (runs both php-fpm and nginx)
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Working directory
WORKDIR /var/www/html

# Copy application (in production; in dev this is overridden by volume mount)
COPY . /var/www/html

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
