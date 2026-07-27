-- ============================================================================
-- Q&A Platform — Database Schema (MySQL / InnoDB)
-- All tables are prefixed with qa_ to avoid collisions with core or other
-- plugins' tables (node_data, users, etc. are core; nothing here touches them).
-- user_id / author columns reference core `users`.id but are NOT declared as
-- FOREIGN KEYs against a core table, since plugins may not assume core schema
-- internals — referential integrity for user_id is enforced at the application
-- layer instead.
-- ============================================================================

CREATE TABLE qa_questions (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    author_username     VARCHAR(190) NOT NULL DEFAULT '',
    author_avatar_url    VARCHAR(500) NULL,
    title               VARCHAR(255) NOT NULL,
    slug                VARCHAR(255) NOT NULL,
    body                MEDIUMTEXT NOT NULL,
    meta_description    VARCHAR(255) NULL,
    views               INT UNSIGNED NOT NULL DEFAULT 0,
    votes_count         INT NOT NULL DEFAULT 0,
    answers_count       INT UNSIGNED NOT NULL DEFAULT 0,
    bookmarks_count     INT UNSIGNED NOT NULL DEFAULT 0,
    accepted_answer_id  BIGINT UNSIGNED NULL,
    status              ENUM('open','closed','deleted') NOT NULL DEFAULT 'open',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    published_at        DATETIME NULL,

    UNIQUE KEY uniq_qa_questions_slug (slug),
    INDEX idx_qa_questions_user (user_id),
    INDEX idx_qa_questions_status (status),
    INDEX idx_qa_questions_created (created_at),
    INDEX idx_qa_questions_views (views),
    INDEX idx_qa_questions_votes (votes_count),
    FULLTEXT KEY ft_qa_questions_title_body (title, body)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE qa_answers (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id         BIGINT UNSIGNED NOT NULL,
    user_id             INT UNSIGNED NOT NULL,
    author_username     VARCHAR(190) NOT NULL DEFAULT '',
    author_avatar_url    VARCHAR(500) NULL,
    body            MEDIUMTEXT NOT NULL,
    votes_count     INT NOT NULL DEFAULT 0,
    is_accepted     TINYINT(1) NOT NULL DEFAULT 0,
    status          ENUM('visible','deleted') NOT NULL DEFAULT 'visible',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_qa_answers_question (question_id),
    INDEX idx_qa_answers_user (user_id),
    INDEX idx_qa_answers_accepted (is_accepted),
    CONSTRAINT fk_qa_answers_question
        FOREIGN KEY (question_id) REFERENCES qa_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE qa_comments (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    author_username     VARCHAR(190) NOT NULL DEFAULT '',
    commentable_id      BIGINT UNSIGNED NOT NULL,
    commentable_type    ENUM('question','answer') NOT NULL,
    parent_id           BIGINT UNSIGNED NULL,
    body                TEXT NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_qa_comments_commentable (commentable_type, commentable_id),
    INDEX idx_qa_comments_parent (parent_id),
    CONSTRAINT fk_qa_comments_parent
        FOREIGN KEY (parent_id) REFERENCES qa_comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE qa_tags (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(60) NOT NULL,
    slug                VARCHAR(60) NOT NULL,
    description         VARCHAR(500) NULL,
    questions_count     INT UNSIGNED NOT NULL DEFAULT 0,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_qa_tags_name (name),
    UNIQUE KEY uniq_qa_tags_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE qa_question_tags (
    question_id     BIGINT UNSIGNED NOT NULL,
    tag_id          INT UNSIGNED NOT NULL,

    PRIMARY KEY (question_id, tag_id),
    INDEX idx_qa_question_tags_tag (tag_id),
    CONSTRAINT fk_qa_question_tags_question
        FOREIGN KEY (question_id) REFERENCES qa_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_qa_question_tags_tag
        FOREIGN KEY (tag_id) REFERENCES qa_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE qa_votes (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    votable_id      BIGINT UNSIGNED NOT NULL,
    votable_type    ENUM('question','answer') NOT NULL,
    type            ENUM('upvote','downvote') NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_qa_votes_user_votable (user_id, votable_id, votable_type),
    INDEX idx_qa_votes_votable (votable_type, votable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE qa_bookmarks (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    question_id     BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_qa_bookmarks_user_question (user_id, question_id),
    CONSTRAINT fk_qa_bookmarks_question
        FOREIGN KEY (question_id) REFERENCES qa_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE qa_reputation_history (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    change_amount   INT NOT NULL,
    reason          VARCHAR(60) NOT NULL,
    source_id       BIGINT UNSIGNED NULL,
    source_type     VARCHAR(30) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_qa_reputation_user (user_id),
    INDEX idx_qa_reputation_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE qa_attachments (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attachable_id       BIGINT UNSIGNED NOT NULL,
    attachable_type     ENUM('question','answer') NOT NULL,
    user_id             INT UNSIGNED NOT NULL,
    filename            VARCHAR(255) NOT NULL,
    filepath            VARCHAR(500) NOT NULL,
    mimetype            VARCHAR(100) NOT NULL,
    filesize            INT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_qa_attachments_attachable (attachable_type, attachable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE qa_edit_history (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    editable_id     BIGINT UNSIGNED NOT NULL,
    editable_type   ENUM('question','answer') NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    old_content     MEDIUMTEXT NOT NULL,
    new_content     MEDIUMTEXT NOT NULL,
    edited_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_qa_edit_history_editable (editable_type, editable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE qa_reports (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reporter_id         INT UNSIGNED NOT NULL,
    reportable_id       BIGINT UNSIGNED NOT NULL,
    reportable_type     ENUM('question','answer','comment') NOT NULL,
    reason              VARCHAR(500) NOT NULL,
    status              ENUM('pending','reviewed','resolved') NOT NULL DEFAULT 'pending',
    moderator_id        INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_qa_reports_status (status),
    INDEX idx_qa_reports_reportable (reportable_type, reportable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
