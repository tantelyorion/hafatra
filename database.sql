SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ========================
-- TABLE: users
-- ========================
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  bio TEXT,
  avatar VARCHAR(255) DEFAULT 'default.png',
  status ENUM('online','offline','away') DEFAULT 'offline',
  last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
  dark_mode TINYINT(1) DEFAULT 0,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- TABLE: conversations
-- ========================
CREATE TABLE conversations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('direct','group','channel') DEFAULT 'direct',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  group_name VARCHAR(100),
  group_avatar VARCHAR(255),
  group_description TEXT,
  group_created_by INT,
  channel_description TEXT,
  channel_avatar VARCHAR(255),
  channel_public TINYINT(1) DEFAULT 1,
  channel_subscribers INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- TABLE: conversation_participants
-- ========================
CREATE TABLE conversation_participants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  user_id INT NOT NULL,
  status ENUM('pending','accepted','spam','blocked','deleted') DEFAULT 'pending',
  joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  role ENUM('member','moderator','admin') DEFAULT 'member',
  deleted_at DATETIME NULL,
  banned_at DATETIME NULL,
  banned_by INT NULL,
  ban_reason VARCHAR(255),
  UNIQUE (conversation_id, user_id),
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- TABLE: contacts
-- ========================
CREATE TABLE contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  contact_phone VARCHAR(20) NOT NULL,
  contact_user_id INT NULL,
  nickname VARCHAR(100),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- TABLE: messages
-- ========================
CREATE TABLE messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  sender_id INT NOT NULL,
  type ENUM('text','image','video','file','voice','system') DEFAULT 'text',
  content TEXT,
  file_path VARCHAR(255),
  file_name VARCHAR(255),
  file_size INT,
  reply_to INT,
  is_deleted TINYINT(1) DEFAULT 0,
  is_edited TINYINT(1) DEFAULT 0,
  edited_at DATETIME NULL,
  sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  duration INT,
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (reply_to) REFERENCES messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- TABLE: message_reads
-- ========================
CREATE TABLE message_reads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_id INT NOT NULL,
  user_id INT NOT NULL,
  read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (message_id, user_id),
  FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- TABLE: message_reactions
-- ========================
CREATE TABLE message_reactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_id INT NOT NULL,
  user_id INT NOT NULL,
  emoji VARCHAR(10) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (message_id, user_id),
  FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- TABLE: calls
-- ========================
CREATE TABLE calls (
  id INT AUTO_INCREMENT PRIMARY KEY,
  caller_id INT NOT NULL,
  callee_id INT NOT NULL,
  conversation_id INT NOT NULL,
  status ENUM('ringing','accepted','rejected','ended','missed','busy') DEFAULT 'ringing',
  started_at DATETIME NULL,
  ended_at DATETIME NULL,
  duration INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  call_type ENUM('audio','video') DEFAULT 'audio',
  FOREIGN KEY (caller_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (callee_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- TABLE: call_signals
-- ========================
CREATE TABLE call_signals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  call_id INT NOT NULL,
  from_user INT NOT NULL,
  to_user INT NOT NULL,
  signal_type ENUM('offer','answer','ice_candidate','hangup') NOT NULL,
  payload LONGTEXT NOT NULL,
  processed TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
  FOREIGN KEY (from_user) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (to_user) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- TABLE: blocks
-- ========================
CREATE TABLE blocks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  blocker_id INT NOT NULL,
  blocked_id INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (blocker_id, blocked_id),
  FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- TABLE: reports
-- ========================
CREATE TABLE reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reporter_id INT NOT NULL,
  reported_user_id INT NULL,
  conversation_id INT NULL,
  message_id INT NULL,
  reason ENUM('spam','harassment','inappropriate','fake','other') NOT NULL,
  description TEXT,
  status ENUM('pending','reviewed','resolved') DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- TABLE: statuses
-- ========================
CREATE TABLE statuses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  type ENUM('text','image','video') DEFAULT 'text',
  content TEXT,
  file_path VARCHAR(255),
  bg_color VARCHAR(20) DEFAULT '#1DA1F2',
  text_color VARCHAR(20) DEFAULT '#ffffff',
  font_style VARCHAR(50) DEFAULT 'normal',
  expires_at DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- TABLE: status_views
-- ========================
CREATE TABLE status_views (
  id INT AUTO_INCREMENT PRIMARY KEY,
  status_id INT NOT NULL,
  viewer_id INT NOT NULL,
  viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (status_id, viewer_id),
  FOREIGN KEY (status_id) REFERENCES statuses(id) ON DELETE CASCADE,
  FOREIGN KEY (viewer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================
-- TABLE: channel_subscribers
-- ========================
CREATE TABLE channel_subscribers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  channel_id INT NOT NULL,
  user_id INT NOT NULL,
  role ENUM('subscriber','moderator','admin') DEFAULT 'subscriber',
  joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (channel_id, user_id),
  FOREIGN KEY (channel_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;