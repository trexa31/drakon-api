FROM php:8.2-apache

# PDO MySQL extension'ını yükle
RUN docker-php-ext-install pdo_mysql

# Apache'nin çalışma dizinini ayarla
WORKDIR /var/www/html

# Tüm dosyaları kopyala
COPY . .

# Apache'yi başlat
EXPOSE 80
