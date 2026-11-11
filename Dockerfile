FROM php:8.2-cli-alpine

RUN apk add --no-cache curl-dev \
    && docker-php-ext-install pdo pdo_mysql curl

WORKDIR /app

COPY . .

EXPOSE 10000

CMD ["php", "-S", "0.0.0.0:10000", "-t", "backend", "backend/router.php"]
