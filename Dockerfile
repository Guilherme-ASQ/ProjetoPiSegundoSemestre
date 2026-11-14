FROM php:8.2-cli
WORKDIR /app
RUN apt-get update && apt-get install -y \
libpq-dev \
libcurl4-openssl-dev \
&& docker-php-ext-install pgsql pdo_pgsql curl \
&& apt-get clean \
&& rm -rf /var/lib/apt/lists/*
COPY . /app
EXPOSE 10000
CMD ["sh", "-c", "php -d upload_max_filesize=10M -d post_max_size=60M -d max_file_uploads=20 -d display_errors=0 -d log_errors=1 -S 0.0.0.0:${PORT:-10000} -t /app"]
