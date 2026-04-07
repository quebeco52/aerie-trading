#!/bin/bash

# Abort the script immediately if any command fails
set -e

echo -e "\n\e[1;34m Starting Immutable Deployment for Aerie Exchange...\e[0m\n"

# Load secure environment variables
export $(grep -v '^#' .env.local | xargs)

DC="docker compose -f docker-compose.prod.yml"

echo -e "\e[33m[1/6] Freezing the Market (Stopping Ticker)...\e[0m"
$DC stop aerie-ticker

echo -e "\e[33m[2/6] Taking Database Snapshot...\e[0m"
mkdir -p backups
BACKUP_FILE="backups/backup_$(date +%F_%H-%M-%S).sql.gz"
$DC exec -T aerie-database mariadb-dump -u root -p"$MYSQL_ROOT_PASSWORD" symfony_db | gzip > "$BACKUP_FILE"
echo -e "\e[32m      -> Saved to $BACKUP_FILE\e[0m"

echo -e "\e[33m[3/6] Pulling latest code from GitHub...\e[0m"
git pull origin main

echo -e "\e[33m[4/6] Building Immutable Production Images...\e[0m"
$DC build --no-cache aerie-php aerie-scheduler aerie-ticker aerie-websocket aerie-caddy

echo -e "\e[33m[5/6] Swapping to New Images & Running Migrations...\e[0m"
$DC up -d

$DC exec -T aerie-php php bin/console cache:clear
$DC exec -T aerie-php php bin/console cache:warmup

$DC exec -T aerie-php php bin/console doctrine:migrations:migrate --no-interaction

echo -e "\e[33m[6/6] Reigniting the Engines...\e[0m"
$DC up -d aerie-ticker

echo -e "\n\e[1;32m✅ Deployment Complete! The Aerie Exchange is fully updated and online.\e[0m\n"