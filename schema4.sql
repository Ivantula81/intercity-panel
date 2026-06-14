-- Пользовательские переменные для шаблонов: {имя_переменной} → значение
CREATE TABLE IF NOT EXISTS variables (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE,
  value TEXT NOT NULL,
  note VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO variables (name, value, note)
SELECT 'подпись', 'С уважением, Интерсити Тур 🚍', 'подставляется как {подпись}'
WHERE NOT EXISTS (SELECT 1 FROM variables);
