-- ============================================================================
-- Site Identity — Database Schema (MySQL / InnoDB)
-- ============================================================================

-- Single-row (id always 1) settings table. Using a real table rather than
-- core's generic site_settings.sql key-value store keeps this plugin fully
-- self-contained and independently portable/removable.
CREATE TABLE site_identity_settings (
    id                  TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    site_name           VARCHAR(190) NOT NULL DEFAULT 'My Site',
    theme_color         VARCHAR(7) NOT NULL DEFAULT '#0a66c2',
    background_color    VARCHAR(7) NOT NULL DEFAULT '#f8f9fb',
    robots_txt          MEDIUMTEXT NULL,
    ads_txt             MEDIUMTEXT NULL,
    sitemap_url         VARCHAR(500) NULL COMMENT 'Absolute or root-relative URL, appended to robots.txt as Sitemap: — left NULL if no sitemap plugin is installed',
    logo_source         ENUM('none','text','image') NOT NULL DEFAULT 'none',
    logo_text           VARCHAR(4) NULL COMMENT 'Initials/glyph used when logo_source = text',
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_site_identity_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per generated asset variant. Storing bytes in the DB (rather than
-- on disk) means this plugin doesn't depend on any particular filesystem
-- layout or a confirmed static-file-serving route — every icon route reads
-- straight from here and streams it back with the right Content-Type.
CREATE TABLE site_identity_assets (
    variant         VARCHAR(40) NOT NULL PRIMARY KEY,
    mime_type       VARCHAR(100) NOT NULL,
    data            LONGBLOB NOT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
