
<?php
require_once 'config.php';

// Check if session is not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if scout is logged in
if (!isset($_SESSION['professional_logged_in']) || $_SESSION['professional_role'] !== 'scout') {
    header('Location:login.php');
    exit();
}

// Check if user has scout role
if ($_SESSION['professional_role'] !== 'scout') {
    header('Location: unauthorized.php');
    exit();
}

// Database helper function
function getPDO() {
    static $pdo;
    if (!$pdo) {
        try {
            $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}

// Enhanced Messaging System Class
class MessagingSystem {
    private $pdo;
    
    public function __construct() {
        $this->pdo = getPDO();
    }
    
    public function sendMessage($sender_id, $recipient_id, $subject, $message) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO messages (sender_id, recipient_id, subject, message, created_at, is_read) 
                VALUES (?, ?, ?, ?, NOW(), 0)
            ");
            
            $result = $stmt->execute([$sender_id, $recipient_id, $subject, $message]);
            
            if ($result) {
                $this->createNotification($recipient_id, 'New Message', "You have a new message: " . $subject);
                return ['success' => true, 'message' => 'Message sent successfully'];
            }
            
            return ['success' => false, 'message' => 'Failed to send message'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function getMessages($user_id, $limit = 50) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT m.*, 
                       u.username as sender_name,
                       u.role as sender_role,
                       u.organization as sender_organization
                FROM messages m 
                JOIN professional_users u ON m.sender_id = u.id 
                WHERE m.recipient_id = ? 
                ORDER BY m.created_at DESC 
                LIMIT ?
            ");
            
            $stmt->execute([$user_id, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            return [];
        }
    }
    
    public function getSentMessages($user_id, $limit = 50) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT m.*, 
                       u.username as recipient_name,
                       u.role as recipient_role,
                       u.organization as recipient_organization
                FROM messages m 
                JOIN professional_users u ON m.recipient_id = u.id 
                WHERE m.sender_id = ? 
                ORDER BY m.created_at DESC 
                LIMIT ?
            ");
            
            $stmt->execute([$user_id, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            return [];
        }
    }
    
    public function getMessage($message_id, $user_id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT m.*, 
                       sender.username as sender_name,
                       sender.role as sender_role,
                       recipient.username as recipient_name,
                       recipient.role as recipient_role
                FROM messages m 
                JOIN professional_users sender ON m.sender_id = sender.id 
                JOIN professional_users recipient ON m.recipient_id = recipient.id 
                WHERE m.id = ? AND (m.sender_id = ? OR m.recipient_id = ?)
            ");
            
            $stmt->execute([$message_id, $user_id, $user_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            return null;
        }
    }
    
    public function markAsRead($message_id, $user_id) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE messages 
                SET is_read = 1 
                WHERE id = ? AND recipient_id = ?
            ");
            
            return $stmt->execute([$message_id, $user_id]);
            
        } catch (PDOException $e) {
            return false;
        }
    }
    
    public function getUnreadCount($user_id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count 
                FROM messages 
                WHERE recipient_id = ? AND is_read = 0
            ");
            
            $stmt->execute([$user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'];
            
        } catch (PDOException $e) {
            return 0;
        }
    }
    
    public function deleteMessage($message_id, $user_id) {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM messages 
                WHERE id = ? AND (sender_id = ? OR recipient_id = ?)
            ");
            
            return $stmt->execute([$message_id, $user_id, $user_id]);
            
        } catch (PDOException $e) {
            return false;
        }
    }
    
    public function getAvailableRecipients($current_user_id, $role_filter = null) {
        try {
            $query = "
                SELECT id, username, role, organization 
                FROM professional_users 
                WHERE id != ? AND status = 'active'
            ";
            
            $params = [$current_user_id];
            
            if ($role_filter && in_array($role_filter, ['admin', 'coach', 'medical', 'club', 'scout'])) {
                $query .= " AND role = ?";
                $params[] = $role_filter;
            }
            
            $query .= " ORDER BY role ASC, username ASC";
            
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            return [];
        }
    }
    
    private function createNotification($recipient_id, $title, $message) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (recipient_id, title, message, created_at, is_read) 
                VALUES (?, ?, ?, NOW(), 0)
            ");
            
            return $stmt->execute([$recipient_id, $title, $message]);
            
        } catch (PDOException $e) {
            return false;
        }
    }
    
    public function searchMessages($user_id, $search_term, $limit = 20) {
        try {
            $search_term = '%' . $search_term . '%';
            
            $stmt = $this->pdo->prepare("
                SELECT m.*, 
                       u.username as sender_name,
                       u.role as sender_role
                FROM messages m 
                JOIN professional_users u ON m.sender_id = u.id 
                WHERE m.recipient_id = ? 
                AND (m.subject LIKE ? OR m.message LIKE ? OR u.username LIKE ?)
                ORDER BY m.created_at DESC 
                LIMIT ?
            ");
            
            $stmt->execute([$user_id, $search_term, $search_term, $search_term, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            return [];
        }
    }
}

// Initialize messaging system
$messaging = new MessagingSystem();

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $pdo = getPDO();
    
    switch ($_POST['action']) {
        case 'get_notifications':
            $stmt = $pdo->prepare("SELECT * FROM notifications WHERE recipient_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
            $stmt->execute([$_SESSION['professional_id']]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_id = ? AND is_read = 0");
            $stmt->execute([$_SESSION['professional_id']]);
            
            echo json_encode($notifications);
            exit();
          
        // Enhanced Messaging Actions
        case 'send_message':
            $sender_id = (int)$_SESSION['professional_id'];
            $recipient_id = (int)$_POST['recipient_id'];
            $subject = trim($_POST['subject']);
            $message = trim($_POST['message']);
            
            if (empty($recipient_id) || empty($subject) || empty($message)) {
                echo json_encode(['success' => false, 'message' => 'All fields are required']);
                exit();
            }
            
            // Debug: Log the values
            error_log("Sending message: sender_id=$sender_id, recipient_id=$recipient_id, subject=$subject");
            
            // Verify recipient exists and is active - check both tables
            $stmt = $pdo->prepare("SELECT id, username FROM professional_users WHERE id = ? AND status = 'active'");
            $stmt->execute([$recipient_id]);
            $recipient = $stmt->fetch();
            
            if (!$recipient) {
                // Also check regular users table as fallback
                $stmt = $pdo->prepare("SELECT id, email as username FROM users WHERE id = ? AND status = 'active'");
                $stmt->execute([$recipient_id]);
                $recipient = $stmt->fetch();
                
                if (!$recipient) {
                    echo json_encode(['success' => false, 'message' => 'Invalid recipient selected']);
                    exit();
                }
            }
            
            // Insert message directly with better error handling
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO messages (sender_id, recipient_id, subject, message, created_at, is_read) 
                    VALUES (?, ?, ?, ?, NOW(), 0)
                ");
                
                $result = $stmt->execute([$sender_id, $recipient_id, $subject, $message]);
                
                if ($result) {
                    // Create notification
                    $stmt = $pdo->prepare("
                        INSERT INTO notifications (recipient_id, title, message, created_at, is_read) 
                        VALUES (?, ?, ?, NOW(), 0)
                    ");
                    $stmt->execute([$recipient_id, 'New Message', "You have a new message: " . $subject]);
                    
                    echo json_encode(['success' => true, 'message' => 'Message sent successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to insert message']);
                }
            } catch (PDOException $e) {
                error_log("Message send error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit();

        case 'get_messages':
            $user_id = (int)$_SESSION['professional_id'];
            
            try {
                $stmt = $pdo->prepare("
                    SELECT m.*, 
                           u.username as sender_name,
                           u.role as sender_role,
                           u.organization as sender_organization
                    FROM messages m 
                    JOIN professional_users u ON m.sender_id = u.id 
                    WHERE m.recipient_id = ? 
                    ORDER BY m.created_at DESC 
                    LIMIT 50
                ");
                
                $stmt->execute([$user_id]);
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Debug: Log messages found
                error_log("Messages found for user $user_id: " . count($messages));
                
                echo json_encode($messages);
            } catch (PDOException $e) {
                error_log("Get messages error: " . $e->getMessage());
                echo json_encode([]);
            }
            exit();

        case 'get_sent_messages':
            $user_id = (int)$_SESSION['professional_id'];
            $messages = $messaging->getSentMessages($user_id);
            echo json_encode($messages);
            exit();

        case 'get_message':
            $message_id = (int)$_POST['message_id'];
            $user_id = (int)$_SESSION['professional_id'];
            $message = $messaging->getMessage($message_id, $user_id);
            
            if ($message) {
                if ($message['recipient_id'] == $user_id) {
                    $messaging->markAsRead($message_id, $user_id);
                }
                echo json_encode(['success' => true, 'message' => $message]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Message not found']);
            }
            exit();

        case 'mark_message_read':
            $message_id = (int)$_POST['message_id'];
            $user_id = (int)$_SESSION['professional_id'];
            $result = $messaging->markAsRead($message_id, $user_id);
            echo json_encode(['success' => $result]);
            exit();

        case 'delete_message':
            $message_id = (int)$_POST['message_id'];
            $user_id = (int)$_SESSION['professional_id'];
            $result = $messaging->deleteMessage($message_id, $user_id);
            echo json_encode(['success' => $result]);
            exit();

        case 'get_unread_count':
            $user_id = (int)$_SESSION['professional_id'];
            $count = $messaging->getUnreadCount($user_id);
            echo json_encode(['count' => $count]);
            exit();

        case 'get_recipients':
            $user_id = (int)$_SESSION['professional_id'];
            $role_filter = isset($_POST['role_filter']) && !empty($_POST['role_filter']) ? $_POST['role_filter'] : null;
            
            try {
                $query = "
                    SELECT id, username, role, organization 
                    FROM professional_users 
                    WHERE id != ? AND status = 'active'
                ";
                
                $params = [$user_id];
                
                if ($role_filter && in_array($role_filter, ['admin', 'coach', 'medical', 'club', 'scout'])) {
                    $query .= " AND role = ?";
                    $params[] = $role_filter;
                }
                
                $query .= " ORDER BY role ASC, username ASC";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Debug: Log recipients found
                error_log("Recipients found: " . count($recipients));
                
                echo json_encode($recipients);
            } catch (PDOException $e) {
                error_log("Get recipients error: " . $e->getMessage());
                echo json_encode([]);
            }
            exit();

        case 'search_messages':
            $user_id = (int)$_SESSION['professional_id'];
            $search_term = trim($_POST['search_term']);
            if (empty($search_term)) {
                echo json_encode([]);
                exit();
            }
            
            $messages = $messaging->searchMessages($user_id, $search_term);
            echo json_encode($messages);
            exit();
            
        case 'mark_notification_read':
            $notification_id = (int)$_POST['notification_id'];
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
            $result = $stmt->execute([$notification_id]);
            
            echo json_encode(['success' => $result]);
            exit();
            
        case 'get_player_details':
            $player_id = (int)$_POST['player_id'];
            $stmt = $pdo->prepare("SELECT pr.*, u.email FROM player_registrations pr JOIN users u ON pr.user_id = u.id WHERE pr.id = ?");
            $stmt->execute([$player_id]);
            $player = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($player) {
                $age = calculateAge($player['birth_day'], $player['birth_month'], $player['birth_year']);
                $player['age'] = $age;
                echo json_encode($player);
            } else {
                echo json_encode(['error' => 'Player not found']);
            }
            exit();

        case 'add_match':
            $session_name = trim($_POST['session_name']);
            $session_date = trim($_POST['session_date']);
            $start_time = trim($_POST['start_time']);
            $age_group = trim($_POST['age_group']);
            $focus_area = trim($_POST['focus_area']);
            $status = 'scheduled'; // Default status for new matches
            $coach_id = $_SESSION['professional_id']; // Get the ID of the logged-in scout

            if (empty($session_name) || empty($session_date) || empty($start_time) || empty($age_group)) {
                echo json_encode(['success' => false, 'message' => 'Please fill all required match fields.']);
                exit();
            }

            try {
                $stmt = $pdo->prepare("INSERT INTO training_sessions (session_name, session_date, start_time, age_group, focus_area, status, coach_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $result = $stmt->execute([$session_name, $session_date, $start_time, $age_group, $focus_area, $status, $coach_id]);

                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Match added successfully!']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to add match.']);
                }
            } catch (PDOException $e) {
                error_log("Add match error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit();

        case 'add_course_module':
            $module_number = filter_input(INPUT_POST, 'module_number', FILTER_VALIDATE_INT);
            $module_title = trim($_POST['module_title']);
            $age_group = trim($_POST['age_group']);
            $description = trim($_POST['description']);
            $video_url = trim($_POST['video_url']);
            // For simplicity, quiz_data is stored as text. In a real app, this might be JSON or a separate table.
            $quiz_data = trim($_POST['quiz_data']); 

            if ($module_number === false || empty($module_title) || empty($age_group) || empty($description)) {
                echo json_encode(['success' => false, 'message' => 'Please fill all required course module fields.']);
                exit();
            }

            try {
                $stmt = $pdo->prepare("INSERT INTO course_modules (module_number, module_title, age_group, description, video_url, quiz_data, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $result = $stmt->execute([$module_number, $module_title, $age_group, $description, $video_url, $quiz_data]);

                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Course module added successfully!']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to add course module.']);
                }
            } catch (PDOException $e) {
                error_log("Add course module error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit();
    }
}

// Helper function to calculate age
function calculateAge($day, $month, $year) {
    if (!$day || !$month || !$year) return 'N/A';
    $birthDate = "$year-$month-$day";
    $today = date("Y-m-d");
    try {
        $diff = date_diff(date_create($birthDate), date_create($today));
        return $diff->format('%y');
    } catch (Exception $e) {
        return 'N/A';
    }
}

// Get scout dashboard statistics
function getScoutDashboardStats($scout_id) {
    $pdo = getPDO();
    $stats = [];
    
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT player_id) as count FROM scouting_reports WHERE scout_id = ?");
    $stmt->execute([$scout_id]);
    $stats['total_players'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT player_id) as count FROM scouting_reports WHERE scout_id = ? AND recommendation IN ('highly_recommended', 'recommended')");
    $stmt->execute([$scout_id]);
    $stats['recommended_players'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT player_id) as count FROM scouting_reports WHERE scout_id = ? AND status = 'draft'");
    $stmt->execute([$scout_id]);
    $stats['draft_reports'] = $stmt->fetch()['count'];
    
    $stmt = $pdo->prepare("SELECT sr.*, pr.first_name, pr.last_name 
                          FROM scouting_reports sr 
                          JOIN player_registrations pr ON sr.player_id = pr.id 
                          WHERE sr.scout_id = ? 
                          ORDER BY sr.created_at DESC 
                          LIMIT 5");
    $stmt->execute([$scout_id]);
    $stats['recent_reports'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT * FROM training_sessions 
                          WHERE session_date >= CURDATE() 
                          ORDER BY session_date ASC 
                          LIMIT 3");
    $stmt->execute();
    $stats['upcoming_matches'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $stats;
}

function getPlayersForScout($scout_id) {
    $pdo = getPDO();
    
    // Selecting pr.* ensures that we get all columns from player_registrations, including `user_id`
    $stmt = $pdo->prepare("
        SELECT pr.*, 
               (SELECT COUNT(*) FROM scouting_reports WHERE player_id = pr.id AND scout_id = ?) as report_count,
               (SELECT MAX(overall_rating) FROM scouting_reports WHERE player_id = pr.id AND scout_id = ?) as max_rating
        FROM player_registrations pr
        LEFT JOIN scouting_reports sr ON pr.id = sr.player_id
        WHERE sr.scout_id = ? OR sr.scout_id IS NULL
        GROUP BY pr.id
        ORDER BY max_rating DESC, pr.created_at DESC
    ");
    $stmt->execute([$scout_id, $scout_id, $scout_id]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getScoutReports($scout_id) {
    $pdo = getPDO();
    
    $stmt = $pdo->prepare("
        SELECT sr.*, pr.first_name, pr.last_name 
        FROM scouting_reports sr
        JOIN player_registrations pr ON sr.player_id = pr.id
        WHERE sr.scout_id = ?
        ORDER BY sr.created_at DESC
    ");
    $stmt->execute([$scout_id]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get all distinct course modules for display in scout dashboard
function getAllCourseModules() {
    $courseData = [
        "1" => [
            "title" => "Module 1: Introduction to Football & Basic Rules",
            "6-8" => [
                "Understand what football is",
                "Learn the basic rules (no hands, out of bounds, goals)",
                "Simple games to practice ball control",
                "Quiz"
            ],
            "9-11" => [
                "Detailed rules overview",
                "Importance of teamwork",
                "Basic positions on the field",
                "Quiz"
            ],
            "12-14" => [
                "Understanding offside rule",
                "Fouls and penalties explained",
                "Referee signals and game flow",
                "Quiz"
            ],
            "15-18" => [
                "In-depth study of game strategies",
                "Role of different positions tactically",
                "Analysis of professional matches for rules",
                "Quiz"
            ]
        ],
        "2" => [
            "title" => "Module 2: Ball Control & Dribbling",
            "6-8" => [
                "Basic dribbling with feet",
                "Simple ball stops and starts",
                "Fun obstacle dribbling drills",
                "Quiz"
            ],
            "9-11" => [
                "Using different parts of the foot",
                "Changing direction while dribbling",
                "Shielding the ball from opponents",
                "Quiz"
            ],
            "12-14" => [
                "Advanced dribbling moves (step-overs, feints)",
                "Dribbling under pressure",
                "One-on-one dribbling drills",
                "Quiz"
            ],
            "15-18" => [
                "Creative dribbling techniques",
                "Dribbling in tight spaces",
                "Integrating dribbling into team play",
                "Quiz"
            ]
        ],
        "3" => [
            "title" => "Module 3: Passing & Receiving",
            "6-8" => [
                "Simple short passes with inside foot",
                "Basic receiving and controlling the ball",
                "Passing games in pairs",
                "Quiz"
            ],
            "9-11" => [
                "Passing accuracy drills",
                "Receiving with different body parts (chest, thigh)",
                "Introduction to long passes",
                "Quiz"
            ],
            "12-14" => [
                "Passing under pressure",
                "One-touch passing drills",
                "Communication during passing",
                "Quiz"
            ],
            "15-18" => [
                "Tactical passing (through balls, switches)",
                "Quick combination plays",
                "Analyzing passing in real matches",
                "Quiz"
            ]
        ],
        "4" => [
            "title" => "Module 4: Shooting & Scoring",
            "6-8" => [
                "Basic shooting techniques with inside foot",
                "Target practice (shooting at goals)",
                "Fun shooting games",
                "Quiz"
            ],
            "9-11" => [
                "Shooting with laces for power",
                "Accuracy and placement drills",
                "Shooting on the move",
                "Quiz"
            ],
            "12-14" => [
                "Shooting under pressure",
                "Volley and half-volley shooting",
                "Penalty kick basics",
                "Quiz"
            ],
            "15-18" => [
                "Finishing techniques in different scenarios",
                "Shooting with both feet",
                "Advanced penalty and free kick techniques",
                "Quiz"
            ]
        ],
        "5" => [
            "title" => "Module 5: Fitness & Team Play",
            "6-8" => [
                "Basic warm-ups and stretches",
                "Fun fitness games to improve stamina",
                "Introduction to playing as a team",
                "Final quiz"
            ],
            "9-11" => [
                "Endurance and speed drills",
                "Understanding positions in team play",
                "Basic tactical awareness",
                "Final quiz"
            ],
            "12-14" => [
                "Position-specific fitness",
                "Team formations and roles",
                "Communication on the field",
                "Final quiz"
            ],
            "15-18" => [
                "Advanced conditioning and recovery",
                "In-depth tactical formations",
                "Leadership and decision making",
                "Final quiz"
            ]
        ]
    ];
    
    $all_courses = [];
    foreach ($courseData as $moduleNumber => $module) {
        foreach ($module as $ageGroup => $details) {
            if ($ageGroup !== 'title') {
                $all_courses[] = [
                    'module_number' => $moduleNumber,
                    'module_title' => $module['title'],
                    'age_group' => $ageGroup,
                    'description' => implode(', ', $details) // Combine details into a single string
                ];
            }
        }
    }
    return $all_courses;
}

// Get data for dashboard
$scout_id = $_SESSION['professional_id'];
$stats = getScoutDashboardStats($scout_id);
$players = getPlayersForScout($scout_id);
$reports = getScoutReports($scout_id);
$all_courses = getAllCourseModules(); // Fetch all courses for dynamic display
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scout Dashboard | Ethiopian Online Football Scouting</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #3498db;
            --secondary: #2ecc71;
            --danger: #e74c3c;
            --warning: #f39c12;
            --dark: #2c3e50;
            --light: #ecf0f1;
            --gray: #95a5a6;
            --dark-mode-bg: #1a1a2e;
            --dark-mode-card: #16213e;
            --dark-mode-text: #f1f1f1;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: background-color 0.3s, color 0.3s;
        }
        
        body {
            display: flex;
            min-height: 100vh;
            background-color: #f5f6fa;
        }
        
        body.dark-mode {
            background-color: var(--dark-mode-bg);
            color: var(--dark-mode-text);
        }
        
        .sidebar {
            width: 250px;
            background-color: var(--dark);
            color: white;
            height: 100vh;
            position: fixed;
            padding: 20px 0;
            transition: all 0.3s;
            z-index: 100;
        }
        
        body.dark-mode .sidebar {
            background-color: #0f0f1a;
        }

        .sidebar.collapsed {
            width: 70px;
            overflow: hidden;
        }

        .sidebar.collapsed .sidebar-header h3, .sidebar.collapsed .menu-item span {
            display: none;
        }

        .sidebar.collapsed .menu-item {
            justify-content: center;
        }
        .sidebar.collapsed .menu-item i {
            margin-right: 0;
            font-size: 1.2rem;
        }
        
        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .sidebar-header h3 {
            font-size: 1.2rem;
        }

        .sidebar-header i {
            cursor: pointer;
        }
        
        .sidebar-menu {
            padding: 20px 0;
            height: calc(100vh - 80px);
            overflow-y: auto;
        }
        
        .menu-item {
            padding: 12px 20px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            margin: 5px 0;
        }
        
        .menu-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .menu-item.active {
            background-color: var(--primary);
        }
        
        .main-content {
            margin-left: 250px;
            width: calc(100% - 250px);
            padding: 20px;
            min-height: 100vh;
            transition: all 0.3s;
        }

        .main-content.expanded {
            margin-left: 70px;
            width: calc(100% - 70px);
        }
        
        body.dark-mode .main-content {
            background-color: var(--dark-mode-bg);
            color: var(--dark-mode-text);
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 10px 0;
            position: sticky;
            top: 0;
            background-color: inherit;
            z-index: 10;
        }
        
        .search-bar {
            display: flex;
            align-items: center;
            background-color: white;
            padding: 8px 15px;
            border-radius: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            width: 300px;
        }
        
        body.dark-mode .search-bar {
            background-color: var(--dark-mode-card);
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
        }
        
        .search-bar input {
            border: none;
            outline: none;
            width: 100%;
            padding: 5px;
            background-color: transparent;
        }
        
        body.dark-mode .search-bar input {
            color: var(--dark-mode-text);
        }
        
        .search-bar input::placeholder {
            color: var(--gray);
        }
        
        .user-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .action-icon {
            position: relative;
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }
        
        .action-icon:hover {
            background-color: rgba(0,0,0,0.1);
        }
        
        body.dark-mode .action-icon:hover {
            background-color: rgba(255,255,255,0.1);
        }
        
        .notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            cursor: pointer;
            position: relative;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            font-weight: bold;
        }

        .content-section {
            display: none;
        }

        .content-section.active {
            display: block;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .dashboard-card {
            background-color: white;
            border-radius: 10px;
            padding: 20px;
            display: flex;
            flex-direction: column; /* Changed for better layout */
            gap: 10px; /* Adjusted gap */
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        body.dark-mode .dashboard-card {
             background-color: var(--dark-mode-card);
             box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        .dashboard-card .card-header {
             width: 100%;
             padding-bottom: 10px;
             border-bottom: 1px solid #eee;
             margin-bottom: 10px;
        }
        body.dark-mode .dashboard-card .card-header {
            border-bottom-color: #444;
        }
        .dashboard-card .card-header h3 { font-size: 1.1rem; }


        .dashboard-card .icon {
            font-size: 2.5rem;
            padding: 15px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dashboard-card .icon.total { background-color: #eaf2fa; color: #3498db; }
        .dashboard-card .icon.approved { background-color: #eafaf1; color: #2ecc71; }
        .dashboard-card .icon.pending { background-color: #fef8e7; color: #f39c12; }
        .dashboard-card .icon.rejected { background-color: #fbe9e7; color: #e74c3c; }

        .dashboard-card .card-content .value {
            font-size: 2rem;
            font-weight: 700;
        }

        .dashboard-card .card-content .label {
            font-size: 1rem;
            color: var(--gray);
        }
        .dashboard-card .card-body { padding: 0; width: 100%; }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        body.dark-mode .data-table {
            background-color: var(--dark-mode-card);
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .data-table th, .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        body.dark-mode .data-table th, 
        body.dark-mode .data-table td {
            border-bottom: 1px solid #444;
        }
        
        .data-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        body.dark-mode .data-table th {
            background-color: #0f0f1a;
        }
        
        .data-table tr:hover {
            background-color: #f8f9fa;
        }
        
        body.dark-mode .data-table tr:hover {
            background-color: rgba(255,255,255,0.05);
        }
        
        .status {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status.watched { background-color: #d1e7fd; color: #0a58ca; }
        .status.recommended { background-color: #d4edda; color: #155724; }
        .status.not-scouted { background-color: #f8d7da; color: #721c24; }
        .status.draft { background-color: #fff3cd; color: #856404; }
        .status.completed { background-color: #d4edda; color: #155724; }
        .status.scheduled { background-color: #d1ecf1; color: #0c5460; } /* New status for matches */
        
        .action-btn {
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 5px;
            font-size: 12px;
            transition: opacity 0.2s, background-color 0.2s;
        }
        
        .action-btn:hover {
            opacity: 0.9;
        }
        
        .btn-primary { background-color: var(--primary); color: white; }
        .btn-secondary { background-color: var(--gray); color: white; }
        .btn-danger { background-color: var(--danger); color: white; }
        .btn-success { background-color: var(--secondary); color: white; }
        .btn-warning { background-color: var(--warning); color: white; }

        .rating-stars .fa-star {
            color: var(--warning);
        }
        .rating-stars .fa-star.empty {
            color: #ddd;
        }

        .card-footer {
            padding: 15px 20px;
            background-color: #f8f9fa;
            border-top: 1px solid #eee;
        }
        
        body.dark-mode .card-footer {
            background-color: #0f0f1a;
            border-top-color: #444;
        }

        .footer {
            background-color: white;
            padding: 15px 20px;
            text-align: center;
            border-top: 1px solid #eee;
            margin-top: 30px;
            font-size: 14px;
            color: var(--gray);
        }
        
        body.dark-mode .footer {
            background-color: var(--dark-mode-card);
            border-top: 1px solid #444;
            color: #777;
        }
        
        .footer-links {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-bottom: 10px;
        }
        
        .footer-link {
            color: var(--gray);
            text-decoration: none;
        }
        
        .footer-link:hover {
            color: var(--primary);
        }

        .notification-dropdown, .profile-dropdown {
            animation: fadeIn 0.2s ease-in-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .notification-dropdown a, .profile-dropdown a {
            transition: background-color 0.2s;
        }
        
        .notification-dropdown a:hover, .profile-dropdown a:hover {
            background-color: rgba(0,0,0,0.05);
        }
        
        body.dark-mode .notification-dropdown a:hover, 
        body.dark-mode .profile-dropdown a:hover {
            background-color: rgba(255,255,255,0.05);
        }
        
        .contact-item {
            transition: background-color 0.2s;
        }
        
        .contact-item:hover {
            background-color: rgba(0,0,0,0.05);
        }
        
        body.dark-mode .contact-item:hover {
            background-color: rgba(255,255,255,0.05);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            overflow-y: auto;
        }

        .modal-content {
            background: white;
            margin: 2rem auto;
            padding: 2rem;
            border-radius: 10px;
            max-width: 800px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
        }

        body.dark-mode .modal-content {
            background: var(--dark-mode-card);
            color: var(--dark-mode-text);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
        }

        body.dark-mode .modal-header {
            border-bottom-color: #444;
        }

        .close-modal {
            font-size: 1.5rem;
            cursor: pointer;
            background: none;
            border: none;
            color: inherit;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: white;
            color: var(--dark);
        }

        body.dark-mode .form-control {
            background-color: var(--dark-mode-bg);
            border-color: #444;
            color: var(--dark-mode-text);
        }

        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 1000;
            display: none;
            max-height: 500px;
            overflow-y: auto;
        }

        body.dark-mode .notification-dropdown {
            background: var(--dark-mode-card);
        }

        .notification-dropdown.show {
            display: block;
        }

        .notification-header {
            padding: 1rem;
            border-bottom: 1px solid #eee;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        body.dark-mode .notification-header {
            border-bottom-color: #444;
        }

        .notification-item {
            padding: 1rem;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background 0.2s;
        }

        body.dark-mode .notification-item {
            border-bottom-color: #444;
        }

        .notification-item:hover {
            background: #f8f9fa;
        }

        body.dark-mode .notification-item:hover {
            background: rgba(255,255,255,0.05);
        }

        .notification-item.unread {
            background: #f0f7ff;
        }

        body.dark-mode .notification-item.unread {
            background: rgba(52, 152, 219, 0.1);
        }

        .notification-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .notification-time {
            font-size: 0.8rem;
            color: #666;
        }

        .role-filter-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .role-filter-btn {
            padding: 8px 16px;
            border: 2px solid var(--primary);
            background: transparent;
            color: var(--primary);
            border-radius: 20px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }

        .role-filter-btn:hover,
        .role-filter-btn.active {
            background: var(--primary);
            color: white;
        }

        @media (max-width: 1200px) {
            .player-stats { grid-template-columns: repeat(2, 1fr); }
            .player-sections { flex-direction: column; }
        }
        
        @media (max-width: 992px) {
            .player-profile { flex-direction: column; }
            .player-photo { width: 150px; height: 150px; margin-bottom: 15px; }
            .form-row { flex-direction: column; gap: 0; }
        }
        
        @media (max-width: 768px) {
            .sidebar { width: 70px; overflow: hidden; }
            .sidebar-header h3, .menu-item span { display: none; }
            .menu-item { justify-content: center; }
            .menu-item i { margin-right: 0; font-size: 1.2rem; }
            .main-content { margin-left: 70px; width: calc(100% - 70px); }
            .search-bar { width: 200px; }
            .player-stats { grid-template-columns: 1fr; }
        }
        
        @media (max-width: 576px) {
            .header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .search-bar { width: 100%; }
            .user-actions { width: 100%; justify-content: space-between; }
        }
        /* Profile Image Container */
.profile-image-container {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    background-color: #eee;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

/* Form Controls */
.form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background-color: #f5f5f5;
    color: #333;
}

.form-control:read-only {
    cursor: not-allowed;
}

.form-control.editable {
    background-color: #fff;
    border: 1px solid var(--primary);
    cursor: text;
}

/* Notifications */
.notification {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 5px;
    color: white;
    z-index: 1000;
    transition: opacity 0.5s;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
}

.notification-info {
    background-color: var(--primary);
}

.notification-success {
    background-color: #28a745;
}

.notification-error {
    background-color: #dc3545;
}

.notification-warning {
    background-color: #ffc107;
    color: #333;
}

.fade-out {
    opacity: 0;
}

/* Modal */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    display: flex;
    justify-content: center;
    align-items: center;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
}

.modal-overlay.active {
    opacity: 1;
    visibility: visible;
}

.modal-content {
    background-color: white;
    padding: 25px;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
    transform: translateY(-20px);
    transition: transform 0.3s ease;
}

.modal-overlay.active .modal-content {
    transform: translateY(0);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-title {
    margin: 0;
    font-size: 1.5rem;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #666;
}

.modal-body {
    margin-bottom: 20px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
    </style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3>Scouting System</h3>
            <i class="fas fa-bars" id="sidebarToggle"></i>
        </div>
        <div class="sidebar-menu">
            <div class="menu-item active" data-section="dashboard">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </div>
            <div class="menu-item" data-section="players">
                <i class="fas fa-users"></i>
                <span>Player list</span>
            </div>
            <div class="menu-item" data-section="reports">
                <i class="fas fa-file-alt"></i>
                <span>Scouting Reports</span>
            </div>
            <div class="menu-item" data-section="matches">
                <i class="fas fa-calendar-alt"></i>
                <span>View report</span>
            </div>
            <div class="menu-item" data-section="courses">
                <i class="fas fa-book"></i>
                <span>Scout Schedule</span>
            </div>
            <div class="menu-item" data-section="messages">
                <i class="fas fa-envelope"></i>
                <span>Messages</span>
            </div>
            <div class="menu-item" data-section="settings">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </div>
            <div class="menu-item" data-section="logout">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </div>
        </div>
    </div>
    
    <div class="main-content" id="mainContent">
        <div class="header">
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search players, reports..." id="searchInput">
            </div>
            <div class="user-actions">
                <div class="action-icon" id="darkModeToggle">
                    <i class="fas fa-moon"></i>
                </div>
                <div class="action-icon notification-bell" id="notificationBell">
                    <i class="fas fa-bell"></i>
                    <div class="notification-count" id="notificationBadge" style="display: none;">0</div>
                </div>
                <div class="user-profile" id="userProfile">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['professional_name'] ?? 'S', 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['professional_name'] ?? 'Scout User'); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Dashboard Content -->
        <div class="content-section active" data-content="dashboard">
            <h2 style="margin-bottom: 20px;">Scout Dashboard</h2>
            
            <section class="cards">
                <div class="dashboard-card">
                    <div class="icon total"><i class="fas fa-users"></i></div>
                    <div class="card-content">
                        <div class="value"><?php echo $stats['total_players']; ?></div>
                        <div class="label">Players Tracked</div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="icon approved"><i class="fas fa-user-check"></i></div>
                    <div class="card-content">
                        <div class="value"><?php echo $stats['recommended_players']; ?></div>
                        <div class="label">Recommended</div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="icon pending"><i class="fas fa-user-clock"></i></div>
                    <div class="card-content">
                        <div class="value"><?php echo $stats['draft_reports']; ?></div>
                        <div class="label">Draft Reports</div>
                    </div>
                </div>
            </section>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-user-plus" style="color: var(--primary);"></i> Recent Reports</h3>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Player</th>
                                    <th>Rating</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['recent_reports'] as $report): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($report['first_name'] . ' ' . $report['last_name']); ?></td>
                                    <td class="rating-stars">
                                        <?php 
                                        $rating = $report['overall_rating'] ?? 0;
                                        for ($i = 1; $i <= 5; $i++) {
                                            echo $i <= $rating ? '<i class="fas fa-star"></i>' : '<i class="fas fa-star empty"></i>';
                                        }
                                        ?>
                                    </td>
                                    <td><span class="status <?php echo htmlspecialchars(strtolower($report['status'])); ?>"><?php echo ucfirst(htmlspecialchars($report['status'])); ?></span></td>
                                    <td><?php echo date('M j, Y', strtotime($report['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-calendar-check" style="color: var(--secondary);"></i> Upcoming Matches</h3>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Session</th>
                                    <th>Date & Time</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['upcoming_matches'] as $match): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($match['session_name']); ?></td>
                                    <td><?php echo date('M j, Y H:i', strtotime($match['session_date'] . ' ' . $match['start_time'])); ?></td>
                                    <td><button class="action-btn btn-success" style="font-size: 12px;">Mark for Visit</button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Player Prospects Content -->
        <div class="content-section" data-content="players">
            <h2 style="margin-bottom: 20px;">Player Prospects</h2>
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div class="search-bar" style="width: 400px;">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search players..." id="playerSearch">
                </div>
                <button class="action-btn btn-primary" onclick="openPlayerModal()">
                    <i class="fas fa-plus"></i> Add New Prospect
                </button>
            </div>
            
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Age</th>
                        <th>City</th>
                        <th>Reports</th>
                        <th>Rating</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($players as $player): 
                        $age = calculateAge($player['birth_day'], $player['birth_month'], $player['birth_year']);
                        $rating = $player['max_rating'] ?? 0;
                        $player_name = htmlspecialchars($player['first_name'] . ' ' . $player['last_name'], ENT_QUOTES);
                        // Make sure user_id is available. It should be from `pr.*`
                        $user_id = $player['user_id'] ?? 0; 
                    ?>
                    <tr>
                        <td><?php echo $player_name; ?></td>
                        <td><?php echo $age; ?></td>
                        <td><?php echo htmlspecialchars($player['city']); ?></td>
                        <td><?php echo $player['report_count'] ?? 0; ?></td>
                        <td class="rating-stars">
                            <?php 
                            if ($rating > 0) {
                                for ($i = 1; $i <= 5; $i++) {
                                    echo $i <= $rating ? '<i class="fas fa-star"></i>' : '<i class="fas fa-star empty"></i>';
                                }
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </td>
                        <td>
                            <span class="status <?php echo $rating >= 4 ? 'recommended' : ($rating > 0 ? 'watched' : 'not-scouted'); ?>">
                                <?php echo $rating >= 4 ? 'Recommended' : ($rating > 0 ? 'Watched' : 'Not Scouted'); ?>
                            </span>
                        </td>
                        <td>
                            <button class="action-btn btn-primary" onclick="viewPlayerDetails(<?php echo $player['id']; ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="action-btn btn-secondary" onclick="editPlayerReport(<?php echo $player['id']; ?>)">
                                <i class="fas fa-edit"></i> Report
                            </button>
                            <!-- [NEW] Message Player Button -->
                            <button class="action-btn btn-success" onclick="openMessageToPlayerModal(<?php echo $user_id; ?>, '<?php echo $player_name; ?>')">
                                <i class="fas fa-envelope"></i> Message
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Scouting Reports Content -->
        <div class="content-section" data-content="reports">
            <h2 style="margin-bottom: 20px;">Scouting Reports</h2>
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div class="search-bar" style="width: 400px;">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search reports..." id="reportSearch">
                </div>
                <button class="action-btn btn-primary" onclick="openReportModal()">
                    <i class="fas fa-plus"></i> Create New Report
                </button>
            </div>
            
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Report ID</th>
                        <th>Player</th>
                        <th>Date</th>
                        <th>Rating</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $report): ?>
                    <tr>
                        <td>#<?php echo $report['id']; ?></td>
                        <td><?php echo htmlspecialchars($report['first_name'] . ' ' . $report['last_name']); ?></td>
                        <td><?php echo date('M j, Y', strtotime($report['created_at'])); ?></td>
                        <td class="rating-stars">
                            <?php 
                            $rating = $report['overall_rating'] ?? 0;
                            for ($i = 1; $i <= 5; $i++) {
                                echo $i <= $rating ? '<i class="fas fa-star"></i>' : '<i class="fas fa-star empty"></i>';
                            }
                            ?>
                        </td>
                        <td><span class="status <?php echo htmlspecialchars(strtolower($report['status'])); ?>"><?php echo ucfirst(htmlspecialchars($report['status'])); ?></span></td>
                        <td>
                            <button class="action-btn btn-primary" onclick="viewReportDetails(<?php echo $report['id']; ?>)">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button class="action-btn btn-secondary" onclick="editReport(<?php echo $report['id']; ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Match Schedule Content -->
        <div class="content-section" data-content="matches">
            <h2 style="margin-bottom: 20px;">Match Schedule</h2>
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div class="search-bar" style="width: 400px;">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search matches..." id="matchSearch">
                </div>
                <div>
                    <button class="action-btn btn-secondary" style="margin-right: 10px;">
                        <i class="fas fa-calendar"></i> Calendar View
                    </button>
                    <button class="action-btn btn-primary" onclick="openAddMatchModal()">
                        <i class="fas fa-plus"></i> Add Match
                    </button>
                </div>
            </div>
            
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Match</th>
                        <th>Age Group</th>
                        <th>Focus Area</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['upcoming_matches'] as $match): ?>
                    <tr>
                        <td><?php echo date('M j, Y H:i', strtotime($match['session_date'] . ' ' . $match['start_time'])); ?></td>
                        <td><?php echo htmlspecialchars($match['session_name']); ?></td>
                        <td><?php echo htmlspecialchars($match['age_group']); ?></td>
                        <td><?php echo htmlspecialchars($match['focus_area'] ?? 'N/A'); ?></td>
                        <td><span class="status scheduled"><?php echo ucfirst(htmlspecialchars($match['status'])); ?></span></td>
                        <td>
                            <button class="action-btn btn-primary"><i class="fas fa-eye"></i> View</button>
                            <button class="action-btn btn-success"><i class="fas fa-check"></i> Attend</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Training Courses Content -->
        <div class="content-section" data-content="courses">
            <h2 style="margin-bottom: 20px;">Training Courses</h2>
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div class="search-bar" style="width: 400px;">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search courses..." id="courseSearch">
                </div>
                <button class="action-btn btn-primary" onclick="openAddCourseModuleModal()">
                    <i class="fas fa-plus"></i> Add New Course Module
                </button>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
                <?php if (empty($all_courses)): ?>
                    <p>No training courses available yet. Add one to get started!</p>
                <?php else: ?>
                    <?php foreach ($all_courses as $course): ?>
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3><i class="fas fa-book-open" style="color: var(--primary);"></i> <?php echo htmlspecialchars($course['module_title']); ?></h3>
                            </div>
                            <div class="card-body">
                                <p><strong>Age Group:</strong> <?php echo htmlspecialchars($course['age_group']); ?></p>
                                <p><?php echo htmlspecialchars($course['description']); ?></p>
                                <div style="margin-top: 15px;">
                                    <span class="status watched">Available</span>
                                </div>
                            </div>
                            <div class="card-footer">
                                <button class="action-btn btn-primary" style="width: 100%;">View Details</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Enhanced Messages Content -->
        <div class="content-section" data-content="messages">
            <h2 style="margin-bottom: 20px;">Messages</h2>
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div class="search-bar" style="width: 400px;">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search messages..." id="messageSearch">
                </div>
                <div style="display: flex; align-items:center;">
                   <!-- [NEW] Inbox/Sent Buttons -->
                   <div class="message-view-buttons" style="margin-right: 15px;">
                        <button class="action-btn btn-primary active" id="inboxBtn" onclick="toggleMessageView('inbox', this)">
                            <i class="fas fa-inbox"></i> Inbox
                        </button>
                        <button class="action-btn btn-secondary" id="sentBtn" onclick="toggleMessageView('sent', this)">
                            <i class="fas fa-paper-plane"></i> Sent
                        </button>
                    </div>
                    <button class="action-btn btn-primary" onclick="openNewMessageModal()">
                        <i class="fas fa-plus"></i> New Message
                    </button>
                </div>
            </div>
            
            <table class="data-table">
                <thead>
                    <tr>
                        <th>From/To</th>
                        <th>Subject</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="messagesList">
                    <!-- Messages will be loaded here via AJAX -->
                </tbody>
            </table>
        </div>

        <!-- Settings Content -->
        <div class="content-section" data-content="settings">
    <h2 style="margin-bottom: 20px;">Settings</h2>
    
    <div class="dashboard-card">
        <div class="card-header">
            <h3><i class="fas fa-user-cog" style="color: var(--primary);"></i> Profile Settings</h3>
        </div>
        <div class="card-body">
            <div style="display: flex; gap: 30px; margin-bottom: 20px;">
                <div style="width: 150px; height: 150px; border-radius: 50%; background-color: #eee; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                    <img id="profileImage" src="<?php echo isset($_SESSION['profile_photo']) ? htmlspecialchars($_SESSION['profile_photo']) : 'data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 100 100\"><rect width=\"100\" height=\"100\" fill=\"%23eee\"/><text x=\"50\" y=\"60\" font-size=\"50\" text-anchor=\"middle\" fill=\"%23aaa\">' . substr(htmlspecialchars($_SESSION['professional_name'] ?? 'User'), 0, 1) . '</text></svg>'; ?>" style="width: 100%; height: 100%; object-fit: cover; display: block;">
                </div>
                <div style="flex: 1;">
                    <h3 style="margin-bottom: 10px;"><?php echo htmlspecialchars($_SESSION['professional_name'] ?? 'Scout User'); ?></h3>
                    <p style="color: var(--gray); margin-bottom: 15px;">Regional Scout - <?php echo htmlspecialchars($_SESSION['organization'] ?? 'Addis Ababa'); ?></p>
                    <button id="editProfileBtn" class="action-btn btn-secondary"><i class="fas fa-edit"></i> Edit Profile</button>
                    <button id="changePhotoBtn" class="action-btn btn-secondary"><i class="fas fa-camera"></i> Change Photo</button>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div>
                    <h4 style="margin-bottom: 15px;">Account Information</h4>
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; color: var(--gray); margin-bottom: 5px;">Email</label>
                        <input type="text" id="emailInput" class="form-control" value="<?php echo htmlspecialchars($_SESSION['professional_email'] ?? 'scout@ethfootball.org'); ?>" readonly>
                    </div>
                    
                </div>
                <div>
                    <h4 style="margin-bottom: 15px;">Password</h4>
                    <button id="changePasswordBtn" class="action-btn btn-primary" style="width: 100%; margin-bottom: 15px;">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                    <h4 style="margin-bottom: 15px;">Notifications</h4>
                    <div style="display: flex; align-items: center; margin-bottom: 10px;">
                        <input type="checkbox" id="emailNotifications" <?php echo isset($_SESSION['email_notifications']) && $_SESSION['email_notifications'] ? 'checked' : 'checked'; ?> style="margin-right: 10px;">
                        <label for="emailNotifications">Email Notifications</label>
                    </div>
                    <div style="display: flex; align-items: center;">
                        <input type="checkbox" id="pushNotifications" <?php echo isset($_SESSION['push_notifications']) && $_SESSION['push_notifications'] ? 'checked' : 'checked'; ?> style="margin-right: 10px;">
                        <label for="pushNotifications">Push Notifications</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer" style="text-align: right; display: none;">
            <button id="cancelChangesBtn" class="action-btn btn-secondary" style="margin-right: 10px;">Cancel</button>
            <button id="saveChangesBtn" class="action-btn btn-primary">Save Changes</button>
        </div>
    </div>
</div>

<div class="footer">
    <div class="footer-links">
        <a href="#" class="footer-link">Privacy Policy</a>
        <a href="#" class="footer-link">Terms of Use</a>
        <a href="#" class="footer-link">Contact Us</a>
    </div>
    <div>© 2025 Ethiopian Online Football Scouting. All rights reserved.</div>
    <div style="margin-top: 5px; font-size: 12px;">Version 1.0.1</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // DOM Elements
    const editProfileBtn = document.getElementById('editProfileBtn');
    const changePhotoBtn = document.getElementById('changePhotoBtn');
    const changePasswordBtn = document.getElementById('changePasswordBtn');
    const saveChangesBtn = document.getElementById('saveChangesBtn');
    const cancelChangesBtn = document.getElementById('cancelChangesBtn');
    const emailInput = document.getElementById('emailInput');
    const emailNotifications = document.getElementById('emailNotifications');
    const pushNotifications = document.getElementById('pushNotifications');
    const profileImage = document.getElementById('profileImage');
    const cardFooter = document.querySelector('.content-section[data-content="settings"] .card-footer');

    // Edit Profile Functionality
    if(editProfileBtn){
        editProfileBtn.addEventListener('click', function() {
            // Enable editing for form fields
            emailInput.readOnly = false;
            emailInput.classList.add('editable');
            
            // Show save/cancel buttons
            cardFooter.style.display = 'block';
            
            // Focus on the first field
            emailInput.focus();
        });
    }

    // Change Photo Functionality
    if(changePhotoBtn){
        changePhotoBtn.addEventListener('click', function() {
            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = 'image/*';
            
            fileInput.addEventListener('change', function(e) {
                if (e.target.files && e.target.files[0]) {
                    // Validate file size (max 2MB)
                    if (e.target.files[0].size > 2 * 1024 * 1024) {
                        showNotification('Image size should be less than 2MB', 'error');
                        return;
                    }
                    
                    // Validate file type
                    const validTypes = ['image/jpeg', 'image/png', 'image/gif'];
                    if (!validTypes.includes(e.target.files[0].type)) {
                        showNotification('Only JPG, PNG or GIF images are allowed', 'error');
                        return;
                    }
                    
                    const reader = new FileReader();
                    
                    reader.onload = function(event) {
                        // Update the profile picture preview
                        profileImage.src = event.target.result;
                        
                        // Upload to server
                        uploadProfilePhoto(e.target.files[0]);
                    };
                    
                    reader.readAsDataURL(e.target.files[0]);
                }
            });
            
            fileInput.click();
        });
    }

    // Save Changes Functionality
    if(saveChangesBtn){
        saveChangesBtn.addEventListener('click', function() {
            const formData = {
                email: emailInput.value,
                email_notifications: emailNotifications.checked,
                push_notifications: pushNotifications.checked
            };
            
            if (!formData.email.includes('@')) {
                showNotification('Please enter a valid email address', 'error');
                return;
            }
            
            saveChangesBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            saveChangesBtn.disabled = true;
            
            // Mock API call for demonstration
            setTimeout(() => {
                showNotification('Profile updated successfully!', 'success');
                emailInput.readOnly = true;
                emailInput.classList.remove('editable');
                cardFooter.style.display = 'none';
                saveChangesBtn.innerHTML = 'Save Changes';
                saveChangesBtn.disabled = false;
            }, 1000);
        });
    }
    
    // Cancel Changes Functionality
    if(cancelChangesBtn) {
        cancelChangesBtn.addEventListener('click', function() {
            emailInput.value = '<?php echo htmlspecialchars($_SESSION['professional_email'] ?? 'scout@ethfootball.org'); ?>';
            emailNotifications.checked = <?php echo isset($_SESSION['email_notifications']) && $_SESSION['email_notifications'] ? 'true' : 'true'; ?>;
            pushNotifications.checked = <?php echo isset($_SESSION['push_notifications']) && $_SESSION['push_notifications'] ? 'true' : 'true'; ?>;
            
            emailInput.readOnly = true;
            emailInput.classList.remove('editable');
            cardFooter.style.display = 'none';
        });
    }

    if(changePasswordBtn){
        changePasswordBtn.addEventListener('click', function() {
            showChangePasswordModal();
        });
    }
});

function uploadProfilePhoto(file) {
    const formData = new FormData();
    formData.append('profile_photo', file);

    const changePhotoBtn = document.getElementById('changePhotoBtn');
    changePhotoBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    changePhotoBtn.disabled = true;

    // Mock API call
    setTimeout(() => {
        showNotification('Profile photo updated successfully!', 'success');
        changePhotoBtn.innerHTML = '<i class="fas fa-camera"></i> Change Photo';
        changePhotoBtn.disabled = false;
    }, 1500);
}

function showChangePasswordModal() {
    // Modal implementation would go here...
    showNotification("This would open a change password modal.", 'info');
}
</script>

    <!-- Enhanced New Message Modal -->
    <div id="newMessageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>New Message</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="messageForm">
                    <div class="form-group role-filter-buttons">
                        <label>Filter Recipients by Role</label>
                        <div>
                            <button type="button" class="role-filter-btn active" data-role="">All</button>
                            <button type="button" class="role-filter-btn" data-role="admin">Admin</button>
                            <button type="button" class="role-filter-btn" data-role="coach">Coach</button>
                            <button type="button" class="role-filter-btn" data-role="medical">Medical</button>
                            <button type="button" class="role-filter-btn" data-role="club">Club</button>
                            <button type="button" class="role-filter-btn" data-role="scout">Scout</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Recipient</label>
                        <select id="recipientSelect" class="form-control" required>
                            <option value="">Select recipient...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" id="messageSubject" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea id="messageContent" class="form-control" rows="5" required></textarea>
                    </div>
                    <button type="submit" class="action-btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Send Message
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- View Message Modal -->
    <div id="viewMessageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="messageViewTitle"></h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="messageViewContent"></div>
                <div id="messageViewMeta" style="margin-top: 20px; font-size: 0.9em; color: var(--gray);"></div>
            </div>
        </div>
    </div>

    <!-- Player Details Modal -->
    <div id="playerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Player Details</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div id="playerDetails">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>

    <!-- Report Modal -->
    <div id="reportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Scouting Report</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div id="reportDetails">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>

    <!-- Add Match Modal -->
    <div id="addMatchModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Match Session</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addMatchForm">
                    <div class="form-group">
                        <label for="matchSessionName">Session Name</label>
                        <input type="text" id="matchSessionName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="matchSessionDate">Date</label>
                        <input type="date" id="matchSessionDate" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="matchStartTime">Start Time</label>
                        <input type="time" id="matchStartTime" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="matchAgeGroup">Age Group</label>
                        <select id="matchAgeGroup" class="form-control" required>
                            <option value="">Select Age Group</option>
                            <option value="6-8">6-8</option>
                            <option value="9-11">9-11</option>
                            <option value="12-14">12-14</option>
                            <option value="15-18">15-18</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="matchFocusArea">Focus Area (Optional)</label>
                        <input type="text" id="matchFocusArea" class="form-control">
                    </div>
                    <button type="submit" class="action-btn btn-primary">
                        <i class="fas fa-calendar-plus"></i> Add Match
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Course Module Modal -->
    <div id="addCourseModuleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Course Module</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addCourseModuleForm">
                    <div class="form-group">
                        <label for="moduleNumber">Module Number</label>
                        <input type="number" id="moduleNumber" class="form-control" min="1" required>
                    </div>
                    <div class="form-group">
                        <label for="moduleTitle">Module Title</label>
                        <input type="text" id="moduleTitle" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="moduleAgeGroup">Age Group</label>
                        <select id="moduleAgeGroup" class="form-control" required>
                            <option value="">Select Age Group</option>
                            <option value="6-8">6-8</option>
                            <option value="9-11">9-11</option>
                            <option value="12-14">12-14</option>
                            <option value="15-18">15-18</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="moduleDescription">Description</label>
                        <textarea id="moduleDescription" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="moduleVideoUrl">Video URL (Optional)</label>
                        <input type="url" id="moduleVideoUrl" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="moduleQuizData">Quiz Data (JSON or Text, Optional)</label>
                        <textarea id="moduleQuizData" class="form-control" rows="5" placeholder="e.g., [{question: '...', options: ['A','B'], answer: 'A'}]"></textarea>
                    </div>
                    <button type="submit" class="action-btn btn-primary">
                        <i class="fas fa-plus-circle"></i> Add Module
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Notification Dropdown -->
    <div class="notification-dropdown" id="notificationDropdown">
        <div class="notification-header">
            <span>Notifications</span>
            <button onclick="markAllNotificationsRead()" style="background: none; border: none; color: var(--primary); cursor: pointer;">
                Mark all as read
            </button>
        </div>
        <div id="notificationList">
            <!-- Notifications will be loaded here -->
        </div>
    </div>

    <script>
        let currentRoleFilter = '';

        document.addEventListener('DOMContentLoaded', () => {
            // Sidebar Toggle
            document.getElementById('sidebarToggle').addEventListener('click', () => {
                document.getElementById('sidebar').classList.toggle('collapsed');
                document.getElementById('mainContent').classList.toggle('expanded');
            });

            // Dark Mode Toggle
            document.getElementById('darkModeToggle').addEventListener('click', () => {
                document.body.classList.toggle('dark-mode');
                localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
            });

            // Check for saved dark mode preference
            if (localStorage.getItem('darkMode') === 'true') {
                document.body.classList.add('dark-mode');
            }

            // Menu item clicks - show corresponding content section
            document.querySelectorAll('.menu-item').forEach(item => {
                item.addEventListener('click', function() {
                    if (this.dataset.section === 'logout') {
                        if (confirm('Are you sure you want to logout?')) {
                            window.location.href = 'logout.php';
                        }
                        return;
                    }

                    document.querySelectorAll('.menu-item').forEach(i => i.classList.remove('active'));
                    this.classList.add('active');
                    
                    document.querySelectorAll('.content-section').forEach(section => section.classList.remove('active'));
                    
                    const sectionToShow = document.querySelector(`.content-section[data-content="${this.dataset.section}"]`);
                    if (sectionToShow) sectionToShow.classList.add('active');

                    if (this.dataset.section === 'messages') {
                        toggleMessageView('inbox', document.getElementById('inboxBtn'));
                        loadRecipients();
                    }
                });
            });

            // Role filter buttons
            document.querySelectorAll('.role-filter-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.role-filter-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    currentRoleFilter = this.dataset.role;
                    loadRecipients(currentRoleFilter);
                });
            });

            // Notification Bell Click
            document.getElementById('notificationBell').addEventListener('click', (e) => {
                e.stopPropagation();
                toggleNotifications();
            });
            
            document.getElementById('userProfile').addEventListener('click', (e) => {
                e.stopPropagation();
                toggleProfileDropdown();
            });
            
            document.addEventListener('click', (e) => {
                if (!e.target.closest('#userProfile') && !e.target.closest('.profile-dropdown')) {
                    document.querySelector('.profile-dropdown')?.remove();
                }
                if (!e.target.closest('#notificationBell') && !e.target.closest('.notification-dropdown')) {
                    document.getElementById('notificationDropdown').style.display = 'none';
                }
            });
            
            loadNotifications();
        });
        
        // [NEW] Function to toggle between Inbox and Sent views
        function toggleMessageView(view, clickedButton) {
            document.querySelectorAll('.message-view-buttons .action-btn').forEach(btn => {
                btn.classList.remove('btn-primary', 'active');
                btn.classList.add('btn-secondary');
            });
            
            clickedButton.classList.remove('btn-secondary');
            clickedButton.classList.add('btn-primary', 'active');
            
            if (view === 'inbox') {
                loadMessages();
            } else {
                loadSentMessages();
            }
        }

        // Messaging Functions
        function loadMessages() {
            fetch('scout-dashboard.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=get_messages' })
            .then(response => response.json())
            .then(messages => {
                const messagesList = document.getElementById('messagesList');
                messagesList.innerHTML = '';
                if (messages.length === 0) {
                    messagesList.innerHTML = '<tr><td colspan="5" style="text-align: center;">Your inbox is empty.</td></tr>';
                    return;
                }
                messages.forEach(message => {
                    const row = document.createElement('tr');
                    row.style.fontWeight = message.is_read ? 'normal' : 'bold';
                    row.innerHTML = `
                        <td>
                            <div><strong>${message.sender_name}</strong></div>
                            <div style="font-size: 0.8em; color: var(--gray);">${message.sender_role}</div>
                        </td>
                        <td>${message.subject}</td>
                        <td>${new Date(message.created_at).toLocaleString()}</td>
                        <td><span class="status ${message.is_read ? 'completed' : 'draft'}">${message.is_read ? 'Read' : 'Unread'}</span></td>
                        <td>
                            <button class="action-btn btn-primary" onclick="viewMessage(${message.id})"><i class="fas fa-eye"></i></button>
                            <button class="action-btn btn-danger" onclick="deleteMessage(${message.id})"><i class="fas fa-trash"></i></button>
                        </td>`;
                    messagesList.appendChild(row);
                });
            }).catch(error => console.error('Error loading messages:', error));
        }

        function loadSentMessages() {
            fetch('scout-dashboard.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=get_sent_messages' })
            .then(response => response.json())
            .then(messages => {
                const messagesList = document.getElementById('messagesList');
                messagesList.innerHTML = '';
                if (messages.length === 0) {
                    messagesList.innerHTML = '<tr><td colspan="5" style="text-align: center;">You have not sent any messages.</td></tr>';
                    return;
                }
                messages.forEach(message => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>
                            <div><strong>To: ${message.recipient_name}</strong></div>
                            <div style="font-size: 0.8em; color: var(--gray);">${message.recipient_role}</div>
                        </td>
                        <td>${message.subject}</td>
                        <td>${new Date(message.created_at).toLocaleString()}</td>
                        <td><span class="status completed">Sent</span></td>
                        <td>
                            <button class="action-btn btn-primary" onclick="viewMessage(${message.id})"><i class="fas fa-eye"></i></button>
                            <button class="action-btn btn-danger" onclick="deleteMessage(${message.id})"><i class="fas fa-trash"></i></button>
                        </td>`;
                    messagesList.appendChild(row);
                });
            }).catch(error => console.error('Error loading sent messages:', error));
        }

        function loadRecipients(roleFilter = '') {
            const body = roleFilter ? `action=get_recipients&role_filter=${roleFilter}` : 'action=get_recipients';
            fetch('scout-dashboard.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body })
            .then(response => response.json())
            .then(recipients => {
                const recipientSelect = document.getElementById('recipientSelect');
                recipientSelect.innerHTML = '<option value="">Select recipient...</option>';
                if (recipients.length === 0) {
                    recipientSelect.innerHTML = '<option value="">No recipients found</option>';
                    return;
                }
                recipients.forEach(r => {
                    const option = new Option(`${r.username} (${r.role.toUpperCase()})`, r.id);
                    recipientSelect.add(option);
                });
            }).catch(error => console.error('Error loading recipients:', error));
        }

        // [NEW] Function to open message modal for a specific player
        function openMessageToPlayerModal(recipientId, recipientName) {
            const modal = document.getElementById('newMessageModal');
            const recipientSelect = document.getElementById('recipientSelect');
            const roleFilters = document.querySelector('.role-filter-buttons');
            const messageSubject = document.getElementById('messageSubject');

            document.getElementById('messageForm').reset();
            
            roleFilters.style.display = 'none';
            recipientSelect.innerHTML = `<option value="${recipientId}" selected>${recipientName} (Player)</option>`;
            recipientSelect.disabled = true;

            messageSubject.value = `A message regarding your football profile`;
            modal.style.display = 'block';
        }

        // [MODIFIED] Function to open message modal for professional users
        function openNewMessageModal() {
            const modal = document.getElementById('newMessageModal');
            const recipientSelect = document.getElementById('recipientSelect');
            const roleFilters = document.querySelector('.role-filter-buttons');
            
            document.getElementById('messageForm').reset();

            roleFilters.style.display = 'block';
            recipientSelect.disabled = false;
            
            currentRoleFilter = '';
            document.querySelectorAll('.role-filter-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.role === '') btn.classList.add('active');
            });
            
            loadRecipients();
            modal.style.display = 'block';
        }


        function viewMessage(messageId) {
            fetch('scout-dashboard.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `action=get_message&message_id=${messageId}`})
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const msg = data.message;
                    document.getElementById('messageViewTitle').textContent = msg.subject;
                    document.getElementById('messageViewContent').innerHTML = msg.message.replace(/\n/g, '<br>');
                    document.getElementById('messageViewMeta').innerHTML = `<strong>From:</strong> ${msg.sender_name}<br><strong>To:</strong> ${msg.recipient_name}<br><strong>Date:</strong> ${new Date(msg.created_at).toLocaleString()}`;
                    document.getElementById('viewMessageModal').style.display = 'block';
                    loadMessages(); // Refresh to update read status
                } else {
                    showNotification('Message not found', 'error');
                }
            }).catch(error => console.error('Error viewing message:', error));
        }

        function deleteMessage(messageId) {
            if (confirm('Are you sure you want to delete this message?')) {
                fetch('scout-dashboard.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `action=delete_message&message_id=${messageId}`})
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Message deleted successfully', 'success');
                        loadMessages();
                        loadSentMessages();
                    } else {
                        showNotification('Failed to delete message', 'error');
                    }
                }).catch(error => console.error('Error deleting message:', error));
            }
        }

        document.getElementById('messageForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const recipientId = document.getElementById('recipientSelect').value;
            const subject = document.getElementById('messageSubject').value.trim();
            const message = document.getElementById('messageContent').value.trim();
            
            if (!recipientId || !subject || !message) {
                showNotification('All fields are required', 'error'); return;
            }
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            submitBtn.disabled = true;
            
            const body = `action=send_message&recipient_id=${recipientId}&subject=${encodeURIComponent(subject)}&message=${encodeURIComponent(message)}`;
            
            fetch('scout-dashboard.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body})
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Message sent successfully!', 'success');
                    closeModal();
                } else {
                    showNotification(data.message || 'Failed to send message', 'error');
                }
            }).catch(error => showNotification('Error sending message.', 'error'))
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
        
        function toggleNotifications() {
            const dropdown = document.getElementById('notificationDropdown');
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
            if (dropdown.style.display === 'block') loadNotifications();
        }
        
        function loadNotifications() {
            fetch('scout-dashboard.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=get_notifications' })
            .then(r => r.json()).then(n => {
                const list = document.getElementById('notificationList');
                const badge = document.getElementById('notificationBadge');
                if (n.length > 0) {
                    badge.textContent = n.length;
                    badge.style.display = 'flex';
                    list.innerHTML = n.map(item => `<div class="notification-item unread" onclick="markNotificationRead(${item.id})"><div class="notification-title">${item.title}</div><div>${item.message}</div><div class="notification-time">${new Date(item.created_at).toLocaleString()}</div></div>`).join('');
                } else {
                    badge.style.display = 'none';
                    list.innerHTML = '<div class="notification-item">No new notifications</div>';
                }
            });
        }

        function markNotificationRead(id) {
            fetch('scout-dashboard.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `action=mark_notification_read&notification_id=${id}`})
            .then(r => r.json()).then(d => { if(d.success) loadNotifications(); });
        }

        function markAllNotificationsRead() {
            fetch('scout-dashboard.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=get_notifications' })
            .then(r => r.json()).then(() => loadNotifications());
        }
        
        function toggleProfileDropdown() { /* Implementation as before */ }
        function viewPlayerDetails(playerId) { /* Implementation as before */ }
        function editPlayerReport(playerId) { showNotification(`Opening report form for player ID: ${playerId}`, 'info'); }
        function viewReportDetails(reportId) { showNotification(`Viewing report ID: ${reportId}`, 'info'); }
        function editReport(reportId) { showNotification(`Editing report ID: ${reportId}`, 'info'); }
        function openPlayerModal() { showNotification('Opening new player prospect form', 'info'); }
        function openReportModal() { showNotification('Opening new scouting report form', 'info'); }

        // [MODIFIED] Close modal function to reset message modal
        function closeModal() {
            document.querySelectorAll('.modal').forEach(modal => {
                modal.style.display = 'none';
            });
            // Reset message modal to its default state
            const messageModal = document.getElementById('newMessageModal');
            if (messageModal) {
                const recipientSelect = document.getElementById('recipientSelect');
                document.querySelector('.role-filter-buttons').style.display = 'block';
                recipientSelect.disabled = false;
                document.getElementById('messageForm').reset();
            }
        }

        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.textContent = message;
            document.body.appendChild(notification);
            setTimeout(() => {
                notification.classList.add('fade-out');
                setTimeout(() => notification.remove(), 500);
            }, 3000);
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) closeModal();
        }
        
        // Match and Course Modal Functions
        function openAddMatchModal() { document.getElementById('addMatchModal').style.display = 'block'; }
        document.getElementById('addMatchForm').addEventListener('submit', function(e) {
            e.preventDefault();
            // Form submission logic as before
        });

        function openAddCourseModuleModal() { document.getElementById('addCourseModuleModal').style.display = 'block'; }
        document.getElementById('addCourseModuleForm').addEventListener('submit', function(e) {
            e.preventDefault();
            // Form submission logic as before
        });
    </script>
</body>
</html>
