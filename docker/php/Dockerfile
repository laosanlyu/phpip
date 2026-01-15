FROM php:8.4-fpm-alpine AS base

# Install system dependencies
RUN apk add --no-cache \
    curl \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    oniguruma-dev \
    mariadb-client \
    mariadb-connector-c \
    nodejs \
    npm \
    icu-dev \
    icu-libs

# Configure MySQL client to skip SSL (for Alpine mariadb-client compatibility)
RUN mkdir -p /root/.my.cnf.d && echo -e "[client]\nssl=0\nssl-verify-server-cert=false" > /root/.my.cnf

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    xml \
    zip \
    gd \
    opcache \
    intl

# Install Redis extension
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Development stage
FROM base AS development

# Install dev dependencies
RUN apk add --no-cache \
    linux-headers \
    $PHPIZE_DEPS

# Install Xdebug for development
RUN pecl install xdebug && docker-php-ext-enable xdebug

# Copy application files
COPY . .

# Install PHP dependencies
RUN composer install --no-interaction --prefer-dist

# Install Node dependencies and build assets
RUN npm install && npm run build

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Production stage
FROM base AS production

# Copy application files
COPY . .

# Install only production PHP dependencies
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Install Node dependencies and build assets for production
RUN npm install && npm run build && rm -rf node_modules

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Copy entrypoint script
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Expose port 9000 for PHP-FPM
EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
