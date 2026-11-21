CREATE TABLE finance__transaction (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_type ENUM('income', 'expense') NOT NULL,
    merchant VARCHAR(255) NOT NULL,
    amount DECIMAL(20,2) NOT NULL,
    category VARCHAR(200) NULL,
    transaction_date TIMESTAMP NOT NULL,
    budget INT UNSIGNED NULL,
    reference_note VARCHAR(255) NULL,
    notes VARCHAR(500) NULL,
    account INT UNSIGNED,
    status ENUM('varified','void') NOT NULL DEFAULT 'varified',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_finance__transaction_budget FOREIGN KEY (budget) REFERENCES finance__budget (id) ON DELETE CASCADE,
    CONSTRAINT fk_finance__transaction_account FOREIGN KEY (account) REFERENCES finance__account (id) ON DELETE CASCADE
);