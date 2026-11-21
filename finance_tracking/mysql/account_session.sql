CREATE TABLE finance__account_session (
    sid VARCHAR(128) NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_finance__account_session_sid FOREIGN KEY (sid) REFERENCES user_session(session_id) ON DELETE CASCADE,
    CONSTRAINT fk_finance__account_session_account_id FOREIGN KEY (account_id) REFERENCES finance__account(id) ON DELETE CASCADE
);