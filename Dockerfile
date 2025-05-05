FROM php:8.1-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first
COPY composer.json composer.lock* ./

# Install dependencies
RUN composer install --no-interaction --no-dev --optimize-autoloader

# Copy the rest of the application
COPY . .

# Create src directory if it doesn't exist
RUN mkdir -p src

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# Expose port 8080
EXPOSE 8080

# Start PHP server
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/var/www/html"] 