# Use Official PHP + Apache image
FROM php:8.2-apache

# Install PostgreSQL PDO extension
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Enable Apache rewrite module
RUN a2enmod rewrite

# Copy all PHP backend files to Apache web root
COPY . /var/www/html/

# Expose HTTP port 80
EXPOSE 80
