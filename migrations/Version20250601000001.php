<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * EnMémoire.com — Migration initiale
 * Crée toutes les tables du projet
 */
final class Version20250601000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'EnMémoire.com — Création initiale de toutes les tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('SET NAMES utf8mb4');
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');

        // users
        $this->addSql("CREATE TABLE users (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            uuid CHAR(36) NOT NULL UNIQUE,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            email VARCHAR(191) NOT NULL UNIQUE,
            phone_whatsapp VARCHAR(20) DEFAULT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(30) NOT NULL DEFAULT 'ROLE_USER',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            avatar_url VARCHAR(500) DEFAULT NULL,
            locale VARCHAR(5) NOT NULL DEFAULT 'fr',
            otp_code VARCHAR(10) DEFAULT NULL,
            otp_expires_at DATETIME DEFAULT NULL,
            otp_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            email_verified TINYINT(1) NOT NULL DEFAULT 0,
            phone_verified TINYINT(1) NOT NULL DEFAULT 0,
            remember_token VARCHAR(100) DEFAULT NULL,
            last_login_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            INDEX idx_email (email),
            INDEX idx_status (status),
            INDEX idx_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // memorial_themes
        $this->addSql("CREATE TABLE memorial_themes (
            id INT UNSIGNED AUTO_INCREMENT NOT NULL,
            slug VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT NULL,
            preview_url VARCHAR(500) DEFAULT NULL,
            css_class VARCHAR(80) DEFAULT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'free',
            price DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // memorial_formulas
        $this->addSql("CREATE TABLE memorial_formulas (
            id INT UNSIGNED AUTO_INCREMENT NOT NULL,
            slug VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT NULL,
            price DECIMAL(10,2) UNSIGNED NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            duration_years SMALLINT UNSIGNED DEFAULT NULL,
            max_events SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            max_media_gb SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            has_live TINYINT(1) NOT NULL DEFAULT 0,
            has_premium_themes TINYINT(1) NOT NULL DEFAULT 0,
            has_qr_code TINYINT(1) NOT NULL DEFAULT 1,
            has_video TINYINT(1) NOT NULL DEFAULT 0,
            has_advanced_stats TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // memorial_pages
        $this->addSql("CREATE TABLE memorial_pages (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            uuid CHAR(36) NOT NULL UNIQUE,
            page_code VARCHAR(12) NOT NULL UNIQUE,
            deceased_first_name VARCHAR(100) NOT NULL,
            deceased_last_name VARCHAR(100) NOT NULL,
            deceased_nickname VARCHAR(100) DEFAULT NULL,
            deceased_birth_date DATE NOT NULL,
            deceased_death_date DATE NOT NULL,
            deceased_birth_place VARCHAR(200) DEFAULT NULL,
            deceased_death_place VARCHAR(200) NOT NULL,
            deceased_profession VARCHAR(200) DEFAULT NULL,
            deceased_quote TEXT DEFAULT NULL,
            main_photo_url VARCHAR(500) DEFAULT NULL,
            obituary_text LONGTEXT DEFAULT NULL,
            biography_text LONGTEXT DEFAULT NULL,
            thank_you_message TEXT DEFAULT NULL,
            formula_id INT UNSIGNED NOT NULL,
            theme_id INT UNSIGNED NOT NULL DEFAULT 1,
            visibility VARCHAR(20) NOT NULL DEFAULT 'public',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            qr_code_url VARCHAR(500) DEFAULT NULL,
            slug VARCHAR(255) NOT NULL UNIQUE,
            expires_at DATE DEFAULT NULL,
            visit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_slug (slug),
            INDEX idx_page_code (page_code),
            INDEX idx_status (status),
            INDEX idx_deceased_name (deceased_last_name, deceased_first_name),
            FOREIGN KEY (formula_id) REFERENCES memorial_formulas(id),
            FOREIGN KEY (theme_id) REFERENCES memorial_themes(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // memorial_moderators
        $this->addSql("CREATE TABLE memorial_moderators (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            memorial_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            moderator_code VARCHAR(12) NOT NULL UNIQUE,
            is_owner TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            invited_by BIGINT UNSIGNED DEFAULT NULL,
            invited_at DATETIME DEFAULT NULL,
            accepted_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_memorial_user (memorial_id, user_id),
            FOREIGN KEY (memorial_id) REFERENCES memorial_pages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (invited_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // moderator_trust_list
        $this->addSql("CREATE TABLE moderator_trust_list (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            moderator_id BIGINT UNSIGNED NOT NULL,
            trusted_user_id BIGINT UNSIGNED NOT NULL,
            added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_trust (moderator_id, trusted_user_id),
            FOREIGN KEY (moderator_id) REFERENCES memorial_moderators(id) ON DELETE CASCADE,
            FOREIGN KEY (trusted_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // life_timeline
        $this->addSql("CREATE TABLE life_timeline (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            memorial_id BIGINT UNSIGNED NOT NULL,
            event_date DATE NOT NULL,
            event_date_precision VARCHAR(10) NOT NULL DEFAULT 'day',
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            media_url VARCHAR(500) DEFAULT NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_memorial (memorial_id),
            FOREIGN KEY (memorial_id) REFERENCES memorial_pages(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // memorial_events
        $this->addSql("CREATE TABLE memorial_events (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            uuid CHAR(36) NOT NULL UNIQUE,
            memorial_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(30) NOT NULL,
            custom_type VARCHAR(100) DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            event_date DATETIME NOT NULL,
            location_name VARCHAR(255) DEFAULT NULL,
            location_address VARCHAR(500) DEFAULT NULL,
            live_url VARCHAR(500) DEFAULT NULL,
            program_text LONGTEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'upcoming',
            visit_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_memorial (memorial_id),
            INDEX idx_event_date (event_date),
            FOREIGN KEY (memorial_id) REFERENCES memorial_pages(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // media_gallery
        $this->addSql("CREATE TABLE media_gallery (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            uuid CHAR(36) NOT NULL UNIQUE,
            memorial_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED DEFAULT NULL,
            type VARCHAR(10) NOT NULL DEFAULT 'photo',
            url VARCHAR(500) NOT NULL,
            thumbnail_url VARCHAR(500) DEFAULT NULL,
            caption VARCHAR(500) DEFAULT NULL,
            file_size_kb INT UNSIGNED DEFAULT NULL,
            duration_sec INT UNSIGNED DEFAULT NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            uploaded_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_memorial (memorial_id),
            FOREIGN KEY (memorial_id) REFERENCES memorial_pages(id) ON DELETE CASCADE,
            FOREIGN KEY (event_id) REFERENCES memorial_events(id) ON DELETE SET NULL,
            FOREIGN KEY (uploaded_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // condolences
        $this->addSql("CREATE TABLE condolences (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            memorial_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED DEFAULT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            message TEXT NOT NULL,
            is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            moderated_by BIGINT UNSIGNED DEFAULT NULL,
            moderated_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_memorial_status (memorial_id, status),
            FOREIGN KEY (memorial_id) REFERENCES memorial_pages(id) ON DELETE CASCADE,
            FOREIGN KEY (event_id) REFERENCES memorial_events(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (moderated_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // testimonials
        $this->addSql("CREATE TABLE testimonials (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            memorial_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) DEFAULT NULL,
            content TEXT NOT NULL,
            relation_to_deceased VARCHAR(100) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            moderated_by BIGINT UNSIGNED DEFAULT NULL,
            moderated_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_memorial (memorial_id),
            FOREIGN KEY (memorial_id) REFERENCES memorial_pages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (moderated_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // guestbook
        $this->addSql("CREATE TABLE guestbook (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            memorial_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            signature_text TEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            moderated_by BIGINT UNSIGNED DEFAULT NULL,
            moderated_at DATETIME DEFAULT NULL,
            signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_memorial (user_id, memorial_id),
            INDEX idx_memorial (memorial_id),
            FOREIGN KEY (memorial_id) REFERENCES memorial_pages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (moderated_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // family_connections
        $this->addSql("CREATE TABLE family_connections (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            memorial_from_id BIGINT UNSIGNED NOT NULL,
            memorial_to_id BIGINT UNSIGNED NOT NULL,
            relation_from VARCHAR(100) NOT NULL,
            relation_to VARCHAR(100) NOT NULL,
            requested_by BIGINT UNSIGNED NOT NULL,
            confirmed_by BIGINT UNSIGNED DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            responded_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_connection (memorial_from_id, memorial_to_id),
            FOREIGN KEY (memorial_from_id) REFERENCES memorial_pages(id) ON DELETE CASCADE,
            FOREIGN KEY (memorial_to_id) REFERENCES memorial_pages(id) ON DELETE CASCADE,
            FOREIGN KEY (requested_by) REFERENCES users(id),
            FOREIGN KEY (confirmed_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // announcements
        $this->addSql("CREATE TABLE announcements (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            memorial_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED DEFAULT NULL,
            posted_by BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            is_pinned TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (memorial_id) REFERENCES memorial_pages(id) ON DELETE CASCADE,
            FOREIGN KEY (event_id) REFERENCES memorial_events(id) ON DELETE SET NULL,
            FOREIGN KEY (posted_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // gadget_catalog
        $this->addSql("CREATE TABLE gadget_catalog (
            id INT UNSIGNED AUTO_INCREMENT NOT NULL,
            slug VARCHAR(80) NOT NULL UNIQUE,
            type VARCHAR(20) NOT NULL,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT NULL,
            image_url VARCHAR(500) DEFAULT NULL,
            animation_url VARCHAR(500) DEFAULT NULL,
            price DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            allows_custom_text TINYINT(1) NOT NULL DEFAULT 0,
            max_text_length SMALLINT UNSIGNED DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // notifications
        $this->addSql("CREATE TABLE notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(80) NOT NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT DEFAULT NULL,
            link_url VARCHAR(500) DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            related_type VARCHAR(80) DEFAULT NULL,
            related_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_user_unread (user_id, is_read),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // audit_logs
        $this->addSql("CREATE TABLE audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(120) NOT NULL,
            target_type VARCHAR(80) DEFAULT NULL,
            target_id BIGINT UNSIGNED DEFAULT NULL,
            old_value JSON DEFAULT NULL,
            new_value JSON DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_actor (actor_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (actor_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');

        // --- Données initiales ---

        // Thèmes
        $this->addSql("INSERT INTO memorial_themes (slug, name, description, css_class, type, sort_order) VALUES
            ('classic-white',  'Classique Blanc',     'Sobre et élégant, fond blanc, tons gris',          'theme-classic-white',  'free',    1),
            ('nature-green',   'Nature & Sérénité',   'Tons verts doux, ambiance naturelle et apaisante', 'theme-nature-green',   'free',    2),
            ('ocean-blue',     'Océan Bleu',          'Tons bleus profonds, paix et éternité',            'theme-ocean-blue',     'free',    3),
            ('sunset-warm',    'Coucher de Soleil',   'Tons chauds orangés, chaleureux et mémoriel',      'theme-sunset-warm',    'free',    4),
            ('night-stars',    'Étoiles de Nuit',     'Fond sombre avec étoiles, poétique et sobre',      'theme-night-stars',    'free',    5),
            ('african-gold',   'Afrique Dorée',       'Couleurs des textiles africains, or et bordeaux',  'theme-african-gold',   'paid',    6),
            ('royal-purple',   'Majesté Violette',    'Violet royal, élégance et noblesse',               'theme-royal-purple',   'paid',    7),
            ('cherry-blossom', 'Fleurs de Cerisier',  'Rose tendre, délicat et poétique',                 'theme-cherry-blossom', 'paid',    8),
            ('golden-light',   'Lumière Dorée',       'Or et lumière, hommage lumineux et solennel',      'theme-golden-light',   'paid',    9),
            ('eternal-black',  'Éternité Noire',      'Sobre, puissant, intemporel',                      'theme-eternal-black',  'special', 10)
        ");

        // Formules
        $this->addSql("INSERT INTO memorial_formulas (slug, name, description, price, currency, duration_years, max_events, max_media_gb, has_live, has_premium_themes, has_qr_code, has_video, has_advanced_stats, sort_order) VALUES
            ('essentielle', 'Essentielle', 'Page mémorielle, avis de décès, condoléances, livre d''or, 5 photos',        19.99, 'USD',  1,   1,   1,  0, 0, 1, 0, 0, 1),
            ('souvenir',    'Souvenir',    'Tout Essentielle + Ligne de vie, galerie illimitée, 3 événements, vidéos',    49.99, 'USD',  3,   3,  10,  0, 0, 1, 1, 0, 2),
            ('heritage',    'Héritage',    'Tout Souvenir + Live, thèmes premium, QR Code gravable, stats avancées', 99.99, 'USD', NULL, 999,  50,  1, 1, 1, 1, 1, 3)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'audit_logs','notifications','gadget_catalog','announcements','family_connections',
            'guestbook','testimonials','condolences','media_gallery','memorial_events',
            'life_timeline','moderator_trust_list','memorial_moderators','memorial_pages',
            'memorial_formulas','memorial_themes','users'
        ] as $table) {
            $this->addSql("DROP TABLE IF EXISTS {$table}");
        }
        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
    }
}
