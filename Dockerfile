FROM php:8.2-cli
COPY . /app
WORKDIR /app
EXPOSE 80
CMD ["php", "-S", "0.0.0.0:80", "bot.php"]
