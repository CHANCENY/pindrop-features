CREATE TABLE finance__preference (
    preference_name ENUM (
        'Weekly money note',
        'Budget alerts',
        'Marketing emails',
        'Low balance alerts',
        'Large transaction alerts',
        'Bill payment reminders',
        'Monthly account statements',
        'Savings goal milestones',
        'Security and login alerts'
    ) NOT NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    preference_rules JSON NULL,
    preference_owner INT UNSIGNED NOT NULL,
    PRIMARY KEY (preference_owner, preference_name),
    CONSTRAINT fk_preference_owner 
        FOREIGN KEY (preference_owner) 
        REFERENCES finance__account(id) 
        ON DELETE CASCADE
) ENGINE=InnoDB;
