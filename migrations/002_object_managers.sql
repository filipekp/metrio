-- Správce objektu: role s právy pro odečty a kontrolu všech měřidel jemu přiřazených objektů.
-- Zachovává existující uživatele, přístupy a všechna data měřidel.
ALTER TABLE users MODIFY COLUMN role ENUM('admin','manager','user') NOT NULL DEFAULT 'user';

CREATE TABLE building_managers (
  building_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (building_id, user_id),
  FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
