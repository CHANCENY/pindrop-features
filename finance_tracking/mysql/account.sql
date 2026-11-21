CREATE TABLE finance__account (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(200) NOT NULL,
    account_type ENUM(
    -- Cash & Banking (Assets)
    'Checking',
    'Savings',
    'Cash',
    'Digital Wallet',       -- e.g., PayPal, Venmo, Mobile Money
    'Money Market',
    'Certificate of Deposit',

    -- Credit & Debt (Liabilities)
    'Credit Card',
    'Line of Credit',
    'Personal Loan',
    'Auto Loan',
    'Student Loan',
    'Mortgage',
    'Buy Now Pay Later',    -- e.g., Klarna, Affirm

    -- Investments & Retirement (Assets)
    'Brokerage',
    'Retirement',           -- e.g., 401(k), IRA, Pension
    'Health Savings',       -- e.g., HSA, FSA
    'Education Savings',    -- e.g., 529 Plan
    'Crypto',

    -- Fixed Assets & Valuables
    'Real Estate',
    'Vehicle',
    'Other Asset',

    -- Bills & Other
    'Home Utilities',
    'Other Liability'
    ) NOT NULL DEFAULT 'Checking',
    institution_name VARCHAR(200) NOT NULL,
    account_email VARCHAR(200) NOT NULL,
    account_phone VARCHAR(200) NOT NULL,
    account_owner BIGINT UNSIGNED NOT NULL,
    account_currency CHAR(3) NOT NULL DEFAULT 'USD',
    account_balance DECIMAL(20, 2) NOT NULL DEFAULT 0.00,
    account_timezone VARCHAR(100) NOT NULL DEFAULT 'UTC',
    account_first_name VARCHAR(100) NULL,
    account_last_name VARCHAR(100) NULL,

    CONSTRAINT fk_finance__account_account_owner FOREIGN KEY (account_owner) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;