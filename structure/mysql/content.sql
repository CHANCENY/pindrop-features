CREATE TABLE content_data (
                              `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                              `title` VARCHAR(500) NOT NULL,
                              `bundle` VARCHAR(255) NOT NULL,

                              `uid` BIGINT UNSIGNED NOT NULL,

                              `uuid` CHAR(36) NOT NULL,
                              `slug` VARCHAR(255) UNIQUE NULL,

                              `status` TINYINT(1) DEFAULT 0,
                              `created` INT UNSIGNED NOT NULL,
                              `changed` INT UNSIGNED NOT NULL,

                              `deleted` TINYINT(1) NULL DEFAULT NULL,

                              INDEX idx_bundle (`bundle`),
                              INDEX idx_uid (`uid`),

                              CONSTRAINT fk_content_user
                                  FOREIGN KEY (`uid`)
                                      REFERENCES `users`(`id`)
                                      ON DELETE CASCADE
                                      ON UPDATE CASCADE

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER `content_data_before_insert`
    BEFORE INSERT ON `content_data`
    FOR EACH ROW
BEGIN
    DECLARE slug_count INT DEFAULT 0;
    DECLARE original_slug VARCHAR(255);

    -- Generate UUID if not provided
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN
        SET NEW.uuid = UUID();
END IF;

-- Generate slug if not provided
IF NEW.slug IS NULL OR NEW.slug = '' THEN

        SET NEW.slug = LOWER(
            REPLACE(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(NEW.title, ' ', '-'),
                        '.', ''),
                    ',', ''),
                '/', ''),
            '_', '-')
        );

        -- Normalize dashes
        SET NEW.slug = REPLACE(NEW.slug, '-+', '-');
        SET NEW.slug = TRIM(BOTH '-' FROM NEW.slug);

        SET original_slug = NEW.slug;

        -- Ensure uniqueness
        WHILE (SELECT COUNT(*) FROM content_data WHERE slug = NEW.slug) > 0 DO
               SET slug_count = slug_count + 1;
SET NEW.slug = CONCAT(original_slug, '-', slug_count);
END WHILE;

END IF;

END
