-- Vertwal Academy Admin Dashboard Database Setup - CORRECTED
DROP DATABASE IF EXISTS auth_system;
CREATE DATABASE auth_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE auth_system;

-- Users table (for players)
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(100) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  verification_code VARCHAR(10),
  is_verified TINYINT(1) DEFAULT 0,
  reset_token VARCHAR(32),
  reset_token_expiry DATETIME,
  profile_photo_path VARCHAR(255),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Professional users (staff) with expanded roles
CREATE TABLE professional_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  email VARCHAR(100) NOT NULL UNIQUE,
  role ENUM('admin','scout','coach','medical','club','manager','analyst') NOT NULL,
  status ENUM('active','inactive','pending','suspended') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_login TIMESTAMP NULL,
  first_name VARCHAR(50),
  last_name VARCHAR(50),
  organization VARCHAR(100)
) ENGINE=InnoDB;

-- Player registrations
CREATE TABLE player_registrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  first_name VARCHAR(50) NOT NULL,
  last_name VARCHAR(50) NOT NULL,
  birth_day INT NOT NULL,
  birth_month INT NOT NULL,
  birth_year INT NOT NULL,
  gender ENUM('male','female','other') NOT NULL,
  phone VARCHAR(20) NOT NULL,
  city VARCHAR(50) NOT NULL,
  weight DECIMAL(5,2) NOT NULL,
  passport VARCHAR(50),
  photo_path VARCHAR(255),
  birth_certificate_path VARCHAR(255),
  education_certificate_path VARCHAR(255),
  passport_photo_path VARCHAR(255),
  registration_status ENUM('pending','approved','rejected') DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  rejection_reason TEXT,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Course modules
CREATE TABLE course_modules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(100) NOT NULL,
  content TEXT NOT NULL,
  module_number INT NOT NULL,
  age_group VARCHAR(10) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Player progress tracking
CREATE TABLE player_progress (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  module_id INT NOT NULL,
  progress_percentage INT DEFAULT 0,
  is_completed BOOLEAN DEFAULT FALSE,
  completed_at DATETIME,
  quiz_score INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (module_id) REFERENCES course_modules(id) ON DELETE CASCADE,
  UNIQUE KEY unique_user_module (user_id, module_id)
) ENGINE=InnoDB;

-- Player quiz scores (detailed tracking)
CREATE TABLE player_scores (
  score_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  module_id INT NOT NULL,
  quiz_score INT NOT NULL,
  max_possible_score INT NOT NULL DEFAULT 5,
  completion_date DATETIME DEFAULT CURRENT_TIMESTAMP,
  attempts INT NOT NULL DEFAULT 1,
  best_score BOOLEAN DEFAULT FALSE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (module_id) REFERENCES course_modules(id) ON DELETE CASCADE,
  UNIQUE KEY unique_user_module_attempt (user_id, module_id, attempts)
) ENGINE=InnoDB;

-- Notifications system
CREATE TABLE notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  professional_user_id INT,
  title VARCHAR(100) NOT NULL,
  message TEXT NOT NULL,
  type VARCHAR(20) DEFAULT 'info',
  is_read BOOLEAN DEFAULT FALSE,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (professional_user_id) REFERENCES professional_users(id) ON DELETE CASCADE,
  CHECK (user_id IS NOT NULL OR professional_user_id IS NOT NULL)
) ENGINE=InnoDB;

-- ==========================================================
-- CORRECTED MESSAGING SYSTEM TABLE
-- ==========================================================
CREATE TABLE messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sender_id INT NOT NULL,
  sender_type VARCHAR(50) NOT NULL, -- ADDED
  recipient_id INT NOT NULL,
  recipient_type VARCHAR(50) NOT NULL, -- ADDED
  subject VARCHAR(100) NOT NULL,
  message TEXT NOT NULL,
  is_read BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  -- REMOVED Foreign Keys to allow messages to/from 'users' and 'professional_users'
) ENGINE=InnoDB;
-- ==========================================================

-- Security tracking
CREATE TABLE login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  ip_address VARCHAR(45) NOT NULL,
  attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  success BOOLEAN NOT NULL,
  FOREIGN KEY (user_id) REFERENCES professional_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Comprehensive role permissions
CREATE TABLE role_permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role VARCHAR(20) NOT NULL,
  permission VARCHAR(50) NOT NULL,
  is_enabled BOOLEAN DEFAULT TRUE,
  UNIQUE KEY unique_role_permission (role, permission)
) ENGINE=InnoDB;

-- System configuration
CREATE TABLE system_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(50) NOT NULL UNIQUE,
  setting_value TEXT,
  description TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Audit logging
CREATE TABLE admin_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(50) NOT NULL,
  description TEXT,
  ip_address VARCHAR(45),
  user_agent TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Scouting reports
CREATE TABLE scouting_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  scout_id INT NOT NULL,
  player_id INT NOT NULL,
  match_date DATE,
  opponent VARCHAR(100),
  position VARCHAR(50),
  overall_rating INT CHECK (overall_rating BETWEEN 1 AND 10),
  technical_skills INT CHECK (technical_skills BETWEEN 1 AND 10),
  physical_attributes INT CHECK (physical_attributes BETWEEN 1 AND 10),
  mental_attributes INT CHECK (mental_attributes BETWEEN 1 AND 10),
  report_text TEXT,
  recommendation ENUM('highly_recommended','recommended','average','not_recommended'),
  status ENUM('draft','completed','reviewed') DEFAULT 'draft',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (scout_id) REFERENCES professional_users(id) ON DELETE CASCADE,
  FOREIGN KEY (player_id) REFERENCES player_registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Medical records
CREATE TABLE medical_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  player_id INT NOT NULL,
  medical_staff_id INT NOT NULL,
  record_type ENUM('checkup','injury','treatment','clearance') NOT NULL,
  record_date DATE NOT NULL,
  description TEXT,
  diagnosis TEXT,
  treatment TEXT,
  status ENUM('active','resolved','ongoing') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (player_id) REFERENCES player_registrations(id) ON DELETE CASCADE,
  FOREIGN KEY (medical_staff_id) REFERENCES professional_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Training sessions
CREATE TABLE training_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  coach_id INT NOT NULL,
  session_name VARCHAR(100) NOT NULL,
  age_group VARCHAR(10) NOT NULL,
  session_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  focus_area VARCHAR(100),
  attendance_count INT DEFAULT 0,
  max_capacity INT DEFAULT 25,
  status ENUM('scheduled','completed','cancelled') DEFAULT 'scheduled',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (coach_id) REFERENCES professional_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Club watchlist
CREATE TABLE club_watchlist (
  id INT AUTO_INCREMENT PRIMARY KEY,
  club_id INT NOT NULL,
  player_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (club_id) REFERENCES professional_users(id) ON DELETE CASCADE,
  FOREIGN KEY (player_id) REFERENCES player_registrations(id) ON DELETE CASCADE,
  UNIQUE KEY club_player_unique (club_id, player_id)
) ENGINE=InnoDB;

-- Club requests
CREATE TABLE club_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  club_id INT NOT NULL,
  player_id INT NOT NULL,
  request_type ENUM('trial','signing','evaluation') NOT NULL,
  notes TEXT,
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  response TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (club_id) REFERENCES professional_users(id) ON DELETE CASCADE,
  FOREIGN KEY (player_id) REFERENCES player_registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Club documents
CREATE TABLE club_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  club_id INT NOT NULL,
  document_type VARCHAR(50) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  upload_date DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (club_id) REFERENCES professional_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==========================================================
-- CORRECTED MATCH VIDEOS TABLE
-- ==========================================================
CREATE TABLE match_videos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  coach_id INT, -- CORRECTED from club_id
  match_name VARCHAR(255) NOT NULL,
  match_date DATE NOT NULL,
  age_group VARCHAR(50), -- ADDED
  opponent VARCHAR(100), -- ADDED
  competition VARCHAR(255),
  location VARCHAR(255),
  video_url VARCHAR(512) NOT NULL,
  featured_players TEXT,
  is_public BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (coach_id) REFERENCES professional_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- ==========================================================


-- Club activity log
CREATE TABLE club_activity (
  id INT AUTO_INCREMENT PRIMARY KEY,
  club_id INT NOT NULL,
  activity_type VARCHAR(50) NOT NULL,
  description TEXT NOT NULL,
  related_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (club_id) REFERENCES professional_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Club licenses
CREATE TABLE club_licenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  license_path VARCHAR(255) NOT NULL,
  is_approved BOOLEAN DEFAULT FALSE,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES professional_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Performance indexes
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_username ON users(username);
CREATE INDEX idx_admin_logs_created_at ON admin_logs(created_at);

-- =============================================
-- SAMPLE DATA (UNCHANGED)
-- =============================================

-- Insert users with properly hashed passwords
INSERT INTO users (username, email, password, is_verified) VALUES
('player1', 'player1@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1),
('player2', 'player2@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1),
('player3', 'player3@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1);

-- Insert professional users with proper roles and status
INSERT INTO professional_users (username, email, password, first_name, last_name, role, status, organization) VALUES
('admin', 'admin@ethioscout.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System', 'Administrator', 'admin', 'active', 'Vertwal Academy'),
('scout1', 'scout1@vertwalacademy.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ababe', 'Scout', 'scout', 'active', 'Ethio Scouts'),
('coach1', 'coach1@vertwalacademy.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'sewunet bishaw', 'Coach', 'coach', 'active', 'Addis Academy'),
('medical1', 'medical1@vertwalacademy.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'medanit', 'Physio', 'medical', 'active', 'City Medical'),
('club1', 'club1@vertwalacademy.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'buna', 'United', 'club', 'active', 'Ethiopian Coffy FC'),
('manager1', 'manager1@vertwalacademy.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Abdu', 'Manager', 'manager', 'active', 'Vertwal Academy');

-- Insert comprehensive role permissions
INSERT INTO role_permissions (role, permission, is_enabled) VALUES
('admin', 'full_access', TRUE), ('admin', 'user_management', TRUE), ('admin', 'system_config', TRUE),
('scout', 'player_evaluation', TRUE), ('scout', 'upload_video', TRUE), ('scout', 'create_report', TRUE), ('scout', 'view_players', TRUE),
('coach', 'training_schedule', TRUE), ('coach', 'match_stats', TRUE), ('coach', 'player_progress', TRUE), ('coach', 'create_session', TRUE),
('medical', 'injury_report', TRUE), ('medical', 'medical_advice', TRUE), ('medical', 'view_records', TRUE), ('medical', 'update_records', TRUE),
('club', 'request_player', TRUE), ('club', 'watchlist', TRUE), ('club', 'view_reports', TRUE), ('club', 'upload_documents', TRUE),
('manager', 'team_management', TRUE), ('manager', 'staff_management', TRUE), ('manager', 'performance_review', TRUE), ('manager', 'budget_control', TRUE);

-- Insert player registrations
INSERT INTO player_registrations (user_id, first_name, last_name, birth_day, birth_month, birth_year, gender, phone, city, weight, registration_status) VALUES
(1, 'Abel', 'Tesfaye', 15, 3, 2005, 'male', '+251911223344', 'Addis Ababa', 65.5, 'approved'),
(2, 'Sara', 'Kebede', 22, 7, 2007, 'female', '+251922334455', 'Dire Dawa', 'approved'),
(3, 'Dawit', 'Mekonnen', 10, 11, 2003, 'male', '+251933445566', 'Bahir Dar', 70.1, 'approved');

-- Insert course modules
INSERT INTO course_modules (title, content, module_number, age_group) VALUES
('Module 1: Introduction to Football', 'Football basics, simple rules, ball control games.', 1, '6-8'),
('Module 1: Football Rules', 'Rule details, teamwork, basic positions.', 1, '9-11'),
('Module 1: Advanced Rules', 'Offside, fouls, referee signals.', 1, '12-14'),
('Module 1: Tactics & Rules', 'Strategies, role analysis, pro match study.', 1, '15-18');

-- Insert player progress (ensuring no duplicate user_id and module_id combinations)
INSERT INTO player_progress (user_id, module_id, progress_percentage, is_completed, quiz_score) VALUES
(1, 1, 75, FALSE, NULL),
(1, 2, 100, TRUE, 85),
(2, 1, 50, FALSE, NULL),
(3, 3, 100, TRUE, 92);

-- Insert system settings
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('two_factor_auth', 'false', 'Enable two-factor authentication'),
('login_tracking', 'true', 'Track login attempts'),
('max_login_attempts', '5', 'Maximum login attempts before lockout');

-- Insert admin logs
INSERT INTO admin_logs (action, description, ip_address) VALUES
('SYSTEM_INIT', 'Database initialized', '127.0.0.1'),
('USER_CREATED', 'Admin user created', '127.0.0.1');