-- Beispiel-„Host-Daten" für die Demo, damit der Datencheck-Schritt (check_data /
-- DataProvider) etwas zum Lesen hat. Entspricht dem Katalog in AppDataProvider
-- (order/user/invoice). Idempotent (IF NOT EXISTS + REPLACE).

CREATE TABLE IF NOT EXISTS orders (
    id     INT PRIMARY KEY,
    status VARCHAR(32) NOT NULL,
    total  INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
REPLACE INTO orders (id, status, total) VALUES
    (1, 'paid', 99),
    (2, 'pending', 49);

CREATE TABLE IF NOT EXISTS users (
    id    INT PRIMARY KEY,
    name  VARCHAR(128),
    email VARCHAR(190),
    vip   TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
REPLACE INTO users (id, name, email, vip) VALUES
    (1, 'Mara', 'mara@example.com', 1),
    (2, 'Ben', 'ben@example.com', 0);

CREATE TABLE IF NOT EXISTS invoices (
    id     INT PRIMARY KEY,
    paid   TINYINT(1) NOT NULL DEFAULT 0,
    amount INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
REPLACE INTO invoices (id, paid, amount) VALUES
    (1, 1, 99),
    (2, 0, 49);
