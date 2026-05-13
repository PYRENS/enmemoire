<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * EnMémoire.com — Migration 002
 * Ajoute les tables manquantes de la migration initiale :
 * gadget_purchases, user_gadget_wallet, event_gadget_allocations,
 * gadget_interactions, payments, promotions, user_theme_purchases,
 * page_visits, reports, audit_logs
 */
final class Version20250601000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'EnMémoire.com — Tables gadgets, paiements, promotions, audit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');

        $this->addSql("CREATE TABLE IF NOT EXISTS gadget_purchases (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            uuid CHAR(36) NOT NULL UNIQUE,
            user_id BIGINT UNSIGNED NOT NULL,
            gadget_id INT UNSIGNED NOT NULL,
            quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) UNSIGNED NOT NULL,
            total_price DECIMAL(10,2) UNSIGNED NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            payment_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_user (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (gadget_id) REFERENCES gadget_catalog(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql("CREATE TABLE IF NOT EXISTS user_gadget_wallet (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            gadget_id INT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_gadget (user_id, gadget_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (gadget_id) REFERENCES gadget_catalog(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql("CREATE TABLE IF NOT EXISTS event_gadget_allocations (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            event_id BIGINT UNSIGNED NOT NULL,
            gadget_id INT UNSIGNED NOT NULL,
            allocated_by BIGINT UNSIGNED NOT NULL,
            allocation_type VARCHAR(30) NOT NULL,
            total_quantity INT UNSIGNED DEFAULT NULL,
            remaining_quantity INT UNSIGNED DEFAULT NULL,
            distribution_mode VARCHAR(20) NOT NULL DEFAULT 'exposed',
            source_type VARCHAR(20) NOT NULL DEFAULT 'maker',
            source_user_id BIGINT UNSIGNED DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_event (event_id),
            FOREIGN KEY (event_id) REFERENCES memorial_events(id) ON DELETE CASCADE,
            FOREIGN KEY (gadget_id) REFERENCES gadget_catalog(id),
            FOREIGN KEY (allocated_by) REFERENCES users(id),
            FOREIGN KEY (source_user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql("CREATE TABLE IF NOT EXISTS gadget_interactions (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            uuid CHAR(36) NOT NULL UNIQUE,
            memorial_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED DEFAULT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            gadget_id INT UNSIGNED NOT NULL,
            allocation_id BIGINT UNSIGNED DEFAULT NULL,
            custom_text VARCHAR(255) DEFAULT NULL,
            action VARCHAR(30) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_memorial (memorial_id),
            INDEX idx_event (event_id),
            INDEX idx_user (user_id),
            FOREIGN KEY (memorial_id) REFERENCES memorial_pages(id) ON DELETE CASCADE,
            FOREIGN KEY (event_id) REFERENCES memorial_events(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (gadget_id) REFERENCES gadget_catalog(id),
            FOREIGN KEY (allocation_id) REFERENCES event_gadget_allocations(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql("CREATE TABLE IF NOT EXISTS payments (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            uuid CHAR(36) NOT NULL UNIQUE,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(30) NOT NULL,
            reference_id BIGINT UNSIGNED DEFAULT NULL,
            amount DECIMAL(12,2) UNSIGNED NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            provider VARCHAR(30) NOT NULL,
            provider_tx_id VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_user (user_id),
            INDEX idx_status (status),
            INDEX idx_provider_tx (provider_tx_id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql("CREATE TABLE IF NOT EXISTS promotions (
            id INT UNSIGNED AUTO_INCREMENT NOT NULL,
            code VARCHAR(50) NOT NULL UNIQUE,
            description VARCHAR(255) DEFAULT NULL,
            discount_type VARCHAR(10) NOT NULL DEFAULT 'percent',
            discount_value DECIMAL(10,2) UNSIGNED NOT NULL,
            applies_to VARCHAR(20) NOT NULL DEFAULT 'all',
            max_uses INT UNSIGNED DEFAULT NULL,
            used_count INT UNSIGNED NOT NULL DEFAULT 0,
            valid_from DATETIME DEFAULT NULL,
            valid_until DATETIME DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql("CREATE TABLE IF NOT EXISTS user_theme_purchases (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            theme_id INT UNSIGNED NOT NULL,
            memorial_id BIGINT UNSIGNED NOT NULL,
            payment_id BIGINT UNSIGNED DEFAULT NULL,
            purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_theme_memorial (user_id, theme_id, memorial_id),
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (theme_id) REFERENCES memorial_themes(id),
            FOREIGN KEY (memorial_id) REFERENCES memorial_pages(id) ON DELETE CASCADE,
            FOREIGN KEY (payment_id) REFERENCES payments(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql("CREATE TABLE IF NOT EXISTS page_visits (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            memorial_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            ip_hash CHAR(64) DEFAULT NULL,
            user_agent_hash CHAR(64) DEFAULT NULL,
            visited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_memorial (memorial_id),
            INDEX idx_visited_at (visited_at),
            FOREIGN KEY (memorial_id) REFERENCES memorial_pages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql("CREATE TABLE IF NOT EXISTS reports (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            reporter_id BIGINT UNSIGNED NOT NULL,
            content_type VARCHAR(30) NOT NULL,
            content_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(30) NOT NULL,
            details TEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            handled_by BIGINT UNSIGNED DEFAULT NULL,
            handled_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_status (status),
            INDEX idx_content (content_type, content_id),
            FOREIGN KEY (reporter_id) REFERENCES users(id),
            FOREIGN KEY (handled_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'user_theme_purchases','page_visits','reports','promotions',
            'payments','gadget_interactions','event_gadget_allocations',
            'user_gadget_wallet','gadget_purchases',
        ] as $table) {
            $this->addSql("DROP TABLE IF EXISTS {$table}");
        }
        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }
}
