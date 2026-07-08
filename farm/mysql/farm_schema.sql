-- ============================================================================
-- PigFarm ERP - Database Schema
-- Dialect: PostgreSQL (uses SERIAL / TIMESTAMP / CHECK constraints)
--   - For MySQL: replace SERIAL with INT AUTO_INCREMENT, TIMESTAMP defaults
--     with DATETIME, and drop unsupported CHECK enums in favor of ENUM(...).
-- Each section maps to the page/form that captures the data.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. FACILITIES  (farms.html, facility-add.html, facility-manage.html)
-- ----------------------------------------------------------------------------
CREATE TABLE facilities (
    facility_id     SERIAL PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    location        VARCHAR(150),
    manager_name    VARCHAR(120),
    barns_count     INTEGER DEFAULT 0,
    capacity        INTEGER DEFAULT 0,           -- max pigs
    current_load    INTEGER DEFAULT 0,           -- current pig count (denormalized snapshot)
    status          VARCHAR(30) NOT NULL DEFAULT 'Operational'
                        CHECK (status IN ('Operational','Under Construction','Maintenance','Decommissioned')),
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- 2. BARNS  (facility-manage.html "Barns in this Facility" table)
-- ----------------------------------------------------------------------------
CREATE TABLE barns (
    barn_id         SERIAL PRIMARY KEY,
    facility_id     INTEGER NOT NULL REFERENCES facilities(facility_id) ON DELETE CASCADE,
    name            VARCHAR(60) NOT NULL,
    pens_count      INTEGER DEFAULT 0,
    capacity        INTEGER DEFAULT 0,
    current_load    INTEGER DEFAULT 0,
    status          VARCHAR(30) NOT NULL DEFAULT 'Operational'
                        CHECK (status IN ('Operational','Maintenance','Decommissioned')),
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- 3. PENS  (subdivision of a barn; referenced by pig location fields)
-- ----------------------------------------------------------------------------
CREATE TABLE pens (
    pen_id          SERIAL PRIMARY KEY,
    barn_id         INTEGER NOT NULL REFERENCES barns(barn_id) ON DELETE CASCADE,
    name            VARCHAR(60) NOT NULL,
    capacity        INTEGER DEFAULT 0,
    current_load    INTEGER DEFAULT 0
);

-- ----------------------------------------------------------------------------
-- 4. PIGS  (livestock.html, pig-register.html, pig-view.html, pig-edit.html)
-- ----------------------------------------------------------------------------
CREATE TABLE pigs (
    pig_id          VARCHAR(20) PRIMARY KEY,      -- Tag / ID Number, e.g. 'PIG-10293'
    breed           VARCHAR(60) NOT NULL,
    sex             VARCHAR(20) NOT NULL
                        CHECK (sex IN ('Female (Sow)','Male (Boar)','Female (Gilt)','Male (Barrow)')),
    date_of_birth   DATE,
    sire_id         VARCHAR(20) REFERENCES pigs(pig_id) ON DELETE SET NULL,
    dam_id          VARCHAR(20) REFERENCES pigs(pig_id) ON DELETE SET NULL,
    facility_id     INTEGER REFERENCES facilities(facility_id) ON DELETE SET NULL,
    barn_id         INTEGER REFERENCES barns(barn_id) ON DELETE SET NULL,
    pen_id          INTEGER REFERENCES pens(pen_id) ON DELETE SET NULL,
    location_label  VARCHAR(120),                  -- free-text fallback, e.g. "Barn 02, Pen 05"
    current_weight_kg NUMERIC(8,2),
    health_status   VARCHAR(30) NOT NULL DEFAULT 'Healthy'
                        CHECK (health_status IN ('Healthy','Quarantine','Under Observation', 'Deceased')),
    notes           TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- 5. WEIGHT HISTORY  (pig-view.html "Weight History" tab)
-- ----------------------------------------------------------------------------
CREATE TABLE weight_records (
    weight_record_id SERIAL PRIMARY KEY,
    pig_id          VARCHAR(20) NOT NULL REFERENCES pigs(pig_id) ON DELETE CASCADE,
    weight_kg       NUMERIC(8,2) NOT NULL,
    recorded_date   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- 6. INSEMINATIONS  (breeding.html, insemination-log.html)
-- ----------------------------------------------------------------------------
CREATE TABLE inseminations (
    insemination_id SERIAL PRIMARY KEY,
    sow_id          VARCHAR(20) NOT NULL REFERENCES pigs(pig_id) ON DELETE CASCADE,
    boar_id         VARCHAR(20) REFERENCES pigs(pig_id) ON DELETE SET NULL,
    semen_batch     VARCHAR(60),                    -- used when method = Artificial Insemination
    insemination_date DATE NOT NULL,
    method          VARCHAR(30) NOT NULL DEFAULT 'Artificial Insemination'
                        CHECK (method IN ('Natural Mating','Artificial Insemination')),
    facility_id     INTEGER REFERENCES facilities(facility_id) ON DELETE SET NULL,                    
    location_label  VARCHAR(120),
    technician      VARCHAR(120),
    expected_due_date DATE,
    status          VARCHAR(30) NOT NULL DEFAULT 'Confirmed'
                        CHECK (status IN ('Confirmed','Imminent','Not Pregnant','Failed')),
    notes           TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- 7. FARROWINGS  (birth events resulting from an insemination; pig-view history)
-- ----------------------------------------------------------------------------
CREATE TABLE farrowings (
    farrowing_id    SERIAL PRIMARY KEY,
    insemination_id INTEGER REFERENCES inseminations(insemination_id) ON DELETE SET NULL,
    sow_id          VARCHAR(20) NOT NULL REFERENCES pigs(pig_id) ON DELETE CASCADE,
    farrowing_date  DATE NOT NULL,
    litter_size     INTEGER,
    piglets_alive   INTEGER,
    piglets_stillborn INTEGER DEFAULT 0,
    notes           TEXT
);

CREATE TABLE farrowing_piglets (
    fp_id    SERIAL PRIMARY KEY,
    farrowing_id INTEGER REFERENCES farrowings(farrowing_id) ON DELETE SET NULL,
    pig_id          VARCHAR(20) NOT NULL REFERENCES pigs(pig_id) ON DELETE CASCADE
);
-- ----------------------------------------------------------------------------
-- 8. TREATMENTS  (health.html "Medical Records" tab, treatment-log.html)
-- ----------------------------------------------------------------------------
CREATE TABLE treatments (
    treatment_id    SERIAL PRIMARY KEY,
    pig_id          VARCHAR(20) REFERENCES pigs(pig_id) ON DELETE CASCADE,
    animal_group    VARCHAR(120),                   -- used when treating a batch/group instead of a single pig
    diagnosis       VARCHAR(150) NOT NULL,
    treatment       VARCHAR(150) NOT NULL,
    dosage          VARCHAR(100),
    treatment_date  DATE NOT NULL,
    duration_days   INTEGER,
    attending_vet   VARCHAR(120),
    outcome         VARCHAR(30) NOT NULL DEFAULT 'Under Treatment'
                        CHECK (outcome IN ('Under Treatment','Recovered','Ongoing Monitoring','Deceased')),
    notes           TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- 9. VACCINATIONS  (health.html "Vaccination Schedule" tab)
-- ----------------------------------------------------------------------------
CREATE TABLE vaccinations (
    vaccination_id  SERIAL PRIMARY KEY,
    animal_group    VARCHAR(120) NOT NULL,
    vaccine_type    VARCHAR(120) NOT NULL,
    batch_id        VARCHAR(60),
    scheduled_date  DATE NOT NULL,
    assigned_to     VARCHAR(120),
    status          VARCHAR(30) NOT NULL DEFAULT 'Upcoming'
                        CHECK (status IN ('Upcoming','Overdue','Completed')),
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE vaccination_group_pigs (
    vgid  SERIAL PRIMARY KEY,
    pig_id          VARCHAR(20) REFERENCES pigs(pig_id) ON DELETE CASCADE,
    animal_group          VARCHAR(20) REFERENCES vaccinations(animal_group) ON DELETE CASCADE
);

-- ----------------------------------------------------------------------------
-- 10. FEED FORMULAS  (feeding.html, feed-formula-add.html)
-- ----------------------------------------------------------------------------
CREATE TABLE feed_formulas (
    formula_id      SERIAL PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    target_group    VARCHAR(60) NOT NULL
                        CHECK (target_group IN ('Weaned Piglets','Pregnant Sows','Lactating Sows','Fattening Pigs','Boars')),
    cost_per_ton    NUMERIC(10,2),
    status          VARCHAR(30) NOT NULL DEFAULT 'Active'
                        CHECK (status IN ('Active','Draft','Discontinued')),
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE feed_formula_ingredients (
    ingredient_id   SERIAL PRIMARY KEY,
    formula_id      INTEGER NOT NULL REFERENCES feed_formulas(formula_id) ON DELETE CASCADE,
    ingredient_name VARCHAR(120) NOT NULL,
    percentage      NUMERIC(5,2) NOT NULL CHECK (percentage >= 0 AND percentage <= 100),
    cost_per_ton    NUMERIC(10,2)
);

-- ----------------------------------------------------------------------------
-- 11. FEED SILOS  (feeding.html "Feed Silo Levels")
-- ----------------------------------------------------------------------------
CREATE TABLE feed_silos (
    silo_id         SERIAL PRIMARY KEY,
    name            VARCHAR(60) NOT NULL,
    contents        VARCHAR(60),
    capacity_tons   NUMERIC(10,2),
    current_level_pct NUMERIC(5,2) CHECK (current_level_pct >= 0 AND current_level_pct <= 100)
);

-- ----------------------------------------------------------------------------
-- 12. INVENTORY ITEMS  (inventory.html, item-scan.html)
-- ----------------------------------------------------------------------------
CREATE TABLE inventory_items (
    item_id         SERIAL PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    category        VARCHAR(60) NOT NULL,
    barcode         VARCHAR(60) UNIQUE,
    quantity        NUMERIC(12,2) NOT NULL DEFAULT 0,
    unit            VARCHAR(30) NOT NULL,
    location        VARCHAR(120),
    reorder_point   NUMERIC(12,2) DEFAULT 0,
    status          VARCHAR(30) NOT NULL DEFAULT 'In Stock'
                        CHECK (status IN ('In Stock','Low Stock','Out of Stock')),
    notes           TEXT,                    
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- 14. FINANCIAL TRANSACTIONS  (finance.html, expense-add.html, income-add.html)
-- ----------------------------------------------------------------------------
CREATE TABLE transactions (
    transaction_id  SERIAL PRIMARY KEY,
    transaction_type VARCHAR(10) NOT NULL CHECK (transaction_type IN ('Income','Expense')),
    transaction_date DATE NOT NULL,
    category        VARCHAR(60) NOT NULL,            -- e.g. 'Feed Purchase', 'Livestock Sale'
    description     VARCHAR(200),
    entity_name     VARCHAR(150),                    -- vendor (expense) or client (income)
    amount          NUMERIC(14,2) NOT NULL CHECK (amount >= 0),
    payment_method  VARCHAR(30)
                        CHECK (payment_method IN ('Bank Transfer','Cash','Cheque','Credit','Mobile Wallet', 'Purchase Order') OR payment_method IS NULL),
    status          VARCHAR(30) NOT NULL DEFAULT 'Pending'
                        CHECK (status IN ('Cleared','Paid','Pending','Overdue')),
    notes           TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- 15. PURCHASE ORDERS  (purchase-orders.html, purchase-order-add.html)
-- ----------------------------------------------------------------------------
CREATE TABLE purchase_orders (
    po_id           SERIAL PRIMARY KEY,
    po_number       VARCHAR(30) UNIQUE NOT NULL,     -- e.g. 'PO-2026-0143'
    supplier        VARCHAR(150) NOT NULL,
    order_date      DATE NOT NULL,
    expected_delivery DATE,
    delivery_location VARCHAR(120),
    status          VARCHAR(30) NOT NULL DEFAULT 'Draft'
                        CHECK (status IN ('Draft','Submitted','Awaiting Delivery','Received','Delayed','Cancelled')),
    total_amount    NUMERIC(14,2) DEFAULT 0,
    notes           TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    purchase_type   VARCHAR(30) NOT NULL DEFAULT 'Income'
                        CHECK (status IN ('Income','Expense'))
);

CREATE TABLE purchase_order_items (
    po_item_id      SERIAL PRIMARY KEY,
    po_id           INTEGER NOT NULL REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    item_name       VARCHAR(150) NOT NULL,
    quantity        NUMERIC(12,2) NOT NULL,
    unit            VARCHAR(30),
    unit_price      NUMERIC(12,2) NOT NULL,
    line_total      NUMERIC(14,2) GENERATED ALWAYS AS (quantity * unit_price) STORED
);

CREATE TABLE pig_tags_counter (
    id      SERIAL PRIMARY KEY AUTO_INCREMENT,
    unit            INT(30)
);

-- ============================================================================
-- INDEXES
-- ============================================================================
CREATE INDEX idx_pigs_facility        ON pigs(facility_id);
CREATE INDEX idx_pigs_barn            ON pigs(barn_id);
CREATE INDEX idx_pigs_health_status   ON pigs(health_status);
CREATE INDEX idx_weight_pig           ON weight_records(pig_id);
CREATE INDEX idx_inseminations_sow    ON inseminations(sow_id);
CREATE INDEX idx_farrowings_sow       ON farrowings(sow_id);
CREATE INDEX idx_treatments_pig       ON treatments(pig_id);
CREATE INDEX idx_formula_ingredients  ON feed_formula_ingredients(formula_id);
CREATE INDEX idx_inventory_barcode    ON inventory_items(barcode);
CREATE INDEX idx_scans_item           ON inventory_scans(item_id);
CREATE INDEX idx_transactions_type    ON transactions(transaction_type);
CREATE INDEX idx_transactions_date    ON transactions(transaction_date);
CREATE INDEX idx_po_status            ON purchase_orders(status);
CREATE INDEX idx_po_items_po          ON purchase_order_items(po_id);