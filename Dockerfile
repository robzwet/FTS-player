# ═══════════════════════════════════════════════
#  Video Queue — PHP/Apache container
# ═══════════════════════════════════════════════
FROM php:8.2-apache

# Install PDO MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy Apache vhost config
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

# Copy entrypoint and db-init to locations outside the web root
COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/db-init.php   /db-init.php
RUN chmod +x /entrypoint.sh

# Copy application files into web root
WORKDIR /var/www/html
COPY . .

# Remove files that shouldn't be served publicly
RUN rm -rf docker docker-compose.yml README.md .dockerignore .git \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
