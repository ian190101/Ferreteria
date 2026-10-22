-- Permisos limitados a los esquemas de la instancia desechable, sin privilegios globales.
CREATE USER IF NOT EXISTS 'calmina_test'@'%' IDENTIFIED BY '';
GRANT ALL PRIVILEGES ON fabrica_calmina_aroma_testing.* TO 'calmina_test'@'%';
GRANT ALL PRIVILEGES ON `sandbox\_%`.* TO 'calmina_test'@'%';
CREATE DATABASE IF NOT EXISTS fabrica_calmina_aroma_testing_restore_verification CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'calmina_restore'@'%' IDENTIFIED BY '';
GRANT ALL PRIVILEGES ON fabrica_calmina_aroma_testing_restore_verification.* TO 'calmina_restore'@'%';
