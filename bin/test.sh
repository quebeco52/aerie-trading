#!/bin/bash
USER_ID=$(id -u)
GROUP_ID=$(id -g)

cd "$(dirname "$0")/.." || exit 1

docker compose --env-file .env.dev -f docker-compose.dev.yml exec --user "$USER_ID:$GROUP_ID" aerie-app php bin/phpunit "$@"
