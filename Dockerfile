# Use an official PHP image
FROM php:8.2-cli

# Install necessary extensions (optional)
RUN docker-php-ext-install pdo pdo_mysql

# Copy project files
WORKDIR /app
COPY . /app

# Expose port
EXPOSE 10000

# Start PHP built-in server
CMD ["php", "-S", "0.0.0.0:10000", "-t", "."]
