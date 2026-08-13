-- DockPolice : schéma MySQL pour le système de tickets SAV
-- À exécuter une seule fois sur le serveur MySQL central.

CREATE DATABASE IF NOT EXISTS dockpolice
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE dockpolice;

CREATE TABLE IF NOT EXISTS tickets (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    machine_name VARCHAR(100) NOT NULL,
    user_name    VARCHAR(100) NOT NULL,
    ip_address   VARCHAR(45),
    category     VARCHAR(50)  NOT NULL,
    priority     ENUM('Basse', 'Normale', 'Haute', 'Urgente') NOT NULL DEFAULT 'Normale',
    subject      VARCHAR(200) NOT NULL,
    description  TEXT NOT NULL,
    status       ENUM('Ouvert', 'En cours', 'Résolu', 'Fermé') NOT NULL DEFAULT 'Ouvert',
    assigned_to  VARCHAR(100),
    resolved_at  DATETIME NULL,
    INDEX idx_user    (user_name),
    INDEX idx_status  (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Utilisateur dédié aux clients DockPolice (droits limités).
-- Syntaxe MariaDB : plugin natif explicite via "IDENTIFIED VIA ... USING PASSWORD(...)".
DROP USER IF EXISTS 'dockpolice_client'@'%';
DROP USER IF EXISTS 'dockpolice_client'@'localhost';

CREATE USER 'dockpolice_client'@'%'
    IDENTIFIED VIA mysql_native_password USING PASSWORD('MOT_DE_PASSE_FORT');
CREATE USER 'dockpolice_client'@'localhost'
    IDENTIFIED VIA mysql_native_password USING PASSWORD('MOT_DE_PASSE_FORT');

GRANT SELECT, INSERT ON dockpolice.tickets TO 'dockpolice_client'@'%';
GRANT SELECT, INSERT ON dockpolice.tickets TO 'dockpolice_client'@'localhost';

FLUSH PRIVILEGES;

-- Chaîne de connexion à coller dans apps.json :
--   "TicketConnectionString": "Server=mysql.police.local;Port=3306;Database=dockpolice;Uid=dockpolice_client;Pwd=MOT_DE_PASSE_FORT;"
