CREATE DATABASE IF NOT EXISTS valen_mysql
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE valen_mysql;

CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    course VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS planner_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    student_name VARCHAR(80) NOT NULL DEFAULT 'Student',
    theme ENUM('light', 'dark') NOT NULL DEFAULT 'light',
    focus_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 25,
    short_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    long_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15
);

INSERT IGNORE INTO planner_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS subjects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(30) NOT NULL UNIQUE,
    instructor VARCHAR(100) NOT NULL DEFAULT '',
    schedule_text VARCHAR(120) NOT NULL DEFAULT '',
    color CHAR(7) NOT NULL DEFAULT '#bf7184',
    description TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_id INT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    task_type VARCHAR(30) NOT NULL DEFAULT 'Assignment',
    priority ENUM('Low', 'Medium', 'High') NOT NULL DEFAULT 'Medium',
    due_at DATETIME NOT NULL,
    status ENUM('Pending', 'In Progress', 'Completed') NOT NULL DEFAULT 'Pending',
    attachment VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tasks_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS study_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_id INT UNSIGNED NULL,
    topic VARCHAR(180) NOT NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    goal VARCHAR(160) NOT NULL DEFAULT '',
    notes TEXT NOT NULL,
    completed TINYINT(1) NOT NULL DEFAULT 0,
    is_timer TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sessions_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_id INT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    attachment VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notes_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL
);

INSERT IGNORE INTO subjects (id, name, code, instructor, schedule_text, color, description) VALUES
(1, 'Introduction to Computing', 'CS 101', 'Prof. Reyes', 'Mon / Wed · 9:00 AM', '#b96f82', 'Foundations of computing and digital systems.'),
(2, 'Academic Writing', 'ENG 102', 'Prof. Cruz', 'Tue / Thu · 10:30 AM', '#6e8a78', 'Research, clear arguments, and effective writing.');

INSERT INTO tasks (subject_id, title, description, task_type, priority, due_at, status)
SELECT 1, 'Read chapter 3: Data and information', 'Make a short summary of key terms.', 'Reading', 'Medium', DATE_ADD(NOW(), INTERVAL 2 DAY), 'Pending'
WHERE NOT EXISTS (SELECT 1 FROM tasks WHERE title = 'Read chapter 3: Data and information');

INSERT INTO tasks (subject_id, title, description, task_type, priority, due_at, status)
SELECT 2, 'Outline the research introduction', 'Bring a one-page outline to class.', 'Assignment', 'High', DATE_ADD(NOW(), INTERVAL 4 DAY), 'In Progress'
WHERE NOT EXISTS (SELECT 1 FROM tasks WHERE title = 'Outline the research introduction');

INSERT IGNORE INTO students (name, email, course) VALUES
('Juan Dela Cruz', 'juan@example.com', 'BSIT'),
('Maria Santos', 'maria@example.com', 'BSCS');
