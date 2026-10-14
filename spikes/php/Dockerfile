# Spike PHP do motor do fechamento — FrankenPHP em modo clássico (sem worker).
FROM dunglas/frankenphp:1-php8.4

# unzip, para o composer extrair pacotes --prefer-dist.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

# Extensões: Postgres (pdo_pgsql) e zip (extração de pacotes pelo composer).
RUN install-php-extensions pdo_pgsql zip

# Composer, a partir da imagem oficial.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Primeiro as definições de dependência, para aproveitar o cache de camadas.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --no-autoloader

# Depois o código.
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache storage/framework/views storage/framework/sessions bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Limites de PHP para o máximo contratual de 10.000 empresas por /competencias/abrir (uma transação por empresa,
# ~35 ms cada no ambiente de teste => ~6 min no pior caso): tempo e memória (validação por curinga do Laravel).
RUN printf 'max_execution_time=900\nmemory_limit=1024M\n' > "$PHP_INI_DIR/conf.d/zz-motor.ini"

# A conexão vem só de MOTOR_DB (Requisito 1.2). APP_KEY fixa: o spike não cifra nada sensível.
ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_KEY=base64:c3Bpa2UtcGhwLW1vdG9yLWZlY2hhbWVudG8tMzJieXg= \
    LOG_CHANNEL=stdout \
    SERVER_NAME=:8080

EXPOSE 8080

# Modo clássico (process-per-request): nada de negócio fica em memória entre
# requisições (Requisito 1.4). O Caddyfile aponta a raiz para public/.
COPY Caddyfile /etc/frankenphp/Caddyfile
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
