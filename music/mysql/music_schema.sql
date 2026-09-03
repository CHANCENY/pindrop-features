-- ============================================================================
-- Music Platform — Database Schema (MySQL / InnoDB)
-- Prefix: music_. No FOREIGN KEYs against core tables (users, file_managed) —
-- DatabasePermissionGuard restricts core-table SELECT to admin/super_admin,
-- so ownership/authorship is denormalized onto these tables at write time,
-- same pattern established in the qa plugin (see QuestionService docblock
-- there for the full rationale).
-- ============================================================================

CREATE TABLE music_artists (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug                VARCHAR(190) NOT NULL,
    name                VARCHAR(190) NOT NULL,
    bio                 TEXT NULL,
    avatar_url          VARCHAR(500) NULL,
    verified            TINYINT(1) NOT NULL DEFAULT 0,
    follower_count      INT UNSIGNED NOT NULL DEFAULT 0,
    tracks_count        INT UNSIGNED NOT NULL DEFAULT 0,
    owner_user_id       INT UNSIGNED NOT NULL COMMENT 'account that manages this artist profile; 0 = house/admin-managed',
    owner_username      VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'denormalized snapshot, see file header',
    status              ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_music_artists_slug (slug),
    INDEX idx_music_artists_owner (owner_user_id),
    FULLTEXT KEY ft_music_artists_name_bio (name, bio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE music_albums (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    artist_id           INT UNSIGNED NOT NULL,
    slug                VARCHAR(190) NOT NULL,
    title               VARCHAR(190) NOT NULL,
    cover_url           VARCHAR(500) NULL,
    type                ENUM('album', 'single', 'ep', 'compilation') NOT NULL DEFAULT 'album',
    release_date        DATE NULL,
    tracks_count        INT UNSIGNED NOT NULL DEFAULT 0,
    status              ENUM('published', 'draft', 'removed') NOT NULL DEFAULT 'published',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_music_albums_artist_slug (artist_id, slug),
    INDEX idx_music_albums_artist (artist_id),
    INDEX idx_music_albums_release (release_date),
    FULLTEXT KEY ft_music_albums_title (title),
    CONSTRAINT fk_music_albums_artist
        FOREIGN KEY (artist_id) REFERENCES music_artists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE music_tracks (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    album_id            INT UNSIGNED NULL COMMENT 'nullable: a single/standalone upload need not belong to an album',
    artist_id           INT UNSIGNED NOT NULL,
    title               VARCHAR(190) NOT NULL,
    slug                VARCHAR(190) NOT NULL,
    audio_uri           VARCHAR(500) NOT NULL COMMENT 'FileSystemService stream-wrapper URI, e.g. public://music/tracks/...',
    cover_url           VARCHAR(500) NULL COMMENT 'falls back to album cover client-side if null',
    duration_seconds    INT UNSIGNED NOT NULL DEFAULT 0,
    track_number        SMALLINT UNSIGNED NULL,
    genre               VARCHAR(60) NULL,
    lyrics              MEDIUMTEXT NULL,
    plays_count         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    likes_count         INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by_user_id INT UNSIGNED NOT NULL,
    uploaded_by_username VARCHAR(190) NOT NULL DEFAULT '',
    status              ENUM('published', 'pending', 'removed') NOT NULL DEFAULT 'published',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_music_tracks_artist_slug (artist_id, slug),
    INDEX idx_music_tracks_album (album_id),
    INDEX idx_music_tracks_artist (artist_id),
    INDEX idx_music_tracks_status (status),
    INDEX idx_music_tracks_genre (genre),
    INDEX idx_music_tracks_plays (plays_count),
    FULLTEXT KEY ft_music_tracks_title (title),
    CONSTRAINT fk_music_tracks_album
        FOREIGN KEY (album_id) REFERENCES music_albums(id) ON DELETE SET NULL,
    CONSTRAINT fk_music_tracks_artist
        FOREIGN KEY (artist_id) REFERENCES music_artists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE music_playlists (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    author_username     VARCHAR(190) NOT NULL DEFAULT '',
    slug                VARCHAR(190) NOT NULL,
    title               VARCHAR(190) NOT NULL,
    description         VARCHAR(500) NULL,
    cover_url           VARCHAR(500) NULL COMMENT 'auto-generated 2x2 collage of first 4 tracks'' covers if null, handled client-side',
    is_public           TINYINT(1) NOT NULL DEFAULT 0,
    tracks_count        INT UNSIGNED NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_music_playlists_user_slug (user_id, slug),
    INDEX idx_music_playlists_user (user_id),
    INDEX idx_music_playlists_public (is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE music_playlist_tracks (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    playlist_id         INT UNSIGNED NOT NULL,
    track_id            BIGINT UNSIGNED NOT NULL,
    position            INT UNSIGNED NOT NULL DEFAULT 0,
    added_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_music_playlist_tracks_playlist (playlist_id, position),
    INDEX idx_music_playlist_tracks_track (track_id),
    CONSTRAINT fk_music_playlist_tracks_playlist
        FOREIGN KEY (playlist_id) REFERENCES music_playlists(id) ON DELETE CASCADE,
    CONSTRAINT fk_music_playlist_tracks_track
        FOREIGN KEY (track_id) REFERENCES music_tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE music_likes (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    likeable_type       ENUM('track', 'album') NOT NULL,
    likeable_id         BIGINT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_music_likes_user_item (user_id, likeable_type, likeable_id),
    INDEX idx_music_likes_item (likeable_type, likeable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE music_follows (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    artist_id           INT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_music_follows_user_artist (user_id, artist_id),
    INDEX idx_music_follows_artist (artist_id),
    CONSTRAINT fk_music_follows_artist
        FOREIGN KEY (artist_id) REFERENCES music_artists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE music_listening_history (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    track_id            BIGINT UNSIGNED NOT NULL,
    played_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_music_history_user_played (user_id, played_at),
    INDEX idx_music_history_track (track_id),
    CONSTRAINT fk_music_history_track
        FOREIGN KEY (track_id) REFERENCES music_tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE music_uploads (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    track_id            BIGINT UNSIGNED NOT NULL,
    original_filename   VARCHAR(255) NOT NULL,
    mime_type           VARCHAR(100) NOT NULL,
    filesize            BIGINT UNSIGNED NOT NULL,
    checksum_sha256     CHAR(64) NULL,
    uploaded_by_user_id INT UNSIGNED NOT NULL,
    uploaded_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_music_uploads_track (track_id),
    CONSTRAINT fk_music_uploads_track
        FOREIGN KEY (track_id) REFERENCES music_tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE music_reports (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reporter_id         INT UNSIGNED NOT NULL,
    reportable_type     ENUM('track', 'album', 'artist', 'playlist') NOT NULL,
    reportable_id       BIGINT UNSIGNED NOT NULL,
    reason              VARCHAR(500) NOT NULL,
    status              ENUM('pending', 'resolved') NOT NULL DEFAULT 'pending',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_music_reports_status (status),
    INDEX idx_music_reports_item (reportable_type, reportable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
