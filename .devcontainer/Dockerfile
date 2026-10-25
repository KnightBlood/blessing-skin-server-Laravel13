# [Choice] PHP version。运行时要求 PHP >= 8.5（composer.json 的 "php": "^8.5"
# 与 bootstrap/chkenv.php）。devcontainers/php 自 3 代起不再提供 bullseye，
# 8.5 仅有 bookworm / trixie 变体；旧式 `0-` 命名空间无 8.5。
# 可选值示例：3-8.5-bookworm、3-8.5-trixie、3-8.4-bookworm。
ARG VARIANT=3-8.5-bookworm
FROM mcr.microsoft.com/devcontainers/php:${VARIANT}

# Install MariaDB client
RUN apt-get update && export DEBIAN_FRONTEND=noninteractive \
    && apt-get install -y mariadb-client zlib1g-dev libpng-dev libzip-dev libwebp-dev \
    && apt-get clean -y && rm -rf /var/lib/apt/lists/*

# Install php extensions。imagick 是 bootstrap/chkenv.php 的必需扩展。
RUN docker-php-ext-install mysqli pdo pdo_mysql gd zip \
    && pecl install imagick \
    && docker-php-ext-enable imagick

# [Choice] Node.js version: none, lts/*, 16, 14, 12, 10
ARG NODE_VERSION="none"
RUN if [ "${NODE_VERSION}" != "none" ]; then su vscode -c "umask 0002 && . /usr/local/share/nvm/nvm.sh && nvm install ${NODE_VERSION} 2>&1"; fi

# Enable Apache rewrite module
RUN a2enmod rewrite

# [Optional] Uncomment this section to install additional OS packages.
# RUN apt-get update && export DEBIAN_FRONTEND=noninteractive \
#     && apt-get -y install --no-install-recommends <your-package-list-here>

# [Optional] Uncomment this line to install global node packages.
# RUN su vscode -c "source /usr/local/share/nvm/nvm.sh && npm install -g <your-package-here>" 2>&1

