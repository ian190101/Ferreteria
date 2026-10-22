#!/usr/bin/env bash
set -euo pipefail

# Este usuario nunca recibe permisos sobre la base operativa ni privilegios globales.
maintenance_source="${MYSQL_DATABASE:?Falta MYSQL_DATABASE}"
maintenance_password="${RESTORE_DB_PASSWORD:?Falta RESTORE_DB_PASSWORD}"
[[ "$maintenance_source" =~ ^[a-zA-Z0-9_]{1,43}$ ]] || { echo 'Nombre de base no válido para el ensayo de recuperación.' >&2; exit 1; }
[[ "$maintenance_password" =~ ^[a-zA-Z0-9_-]{24,128}$ ]] || { echo 'RESTORE_DB_PASSWORD requiere entre 24 y 128 caracteres alfanuméricos, guion o guion bajo.' >&2; exit 1; }
maintenance_database="${maintenance_source}_restore_verification"
MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?Falta MYSQL_ROOT_PASSWORD}" mysql --protocol=socket --user=root <<SQL
CREATE DATABASE IF NOT EXISTS \`$maintenance_database\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'calmina_restore'@'%' IDENTIFIED BY '$maintenance_password';
GRANT ALL PRIVILEGES ON \`$maintenance_database\`.* TO 'calmina_restore'@'%';
SQL
