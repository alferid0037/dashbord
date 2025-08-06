<?php
require_once 'config.php';

// Check if session is not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if coach is logged in
if (!isset($_SESSION['professional_logged_in']) || $_SESSION['professional_role'] !== 'coach') {
    header('Location: coach-login.php');
    exit();
}

// Ensure the user is a coach
if ($_SESSION['professional_role'] !== 'coach') {
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
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}

// Get coach data
$pdo = getPDO();
$stmt = $pdo->prepare("SELECT * FROM professional_users WHERE id = ?");
$stmt->execute([$_SESSION['professional_id']]);
$coach = $stmt->fetch();

// Update last login
$stmt = $pdo->prepare("UPDATE professional_users SET last_login = NOW() WHERE id = ?");
$stmt->execute([$_SESSION['professional_id']]);


class MessagingSystem {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getMessages($userId, $box = 'inbox') {
        // This query is simplified and only shows messages to/from other professional staff.
        // A more complex query would be needed to union results from the users (players) table.
        if ($box === 'sent') {
            $sql = "SELECT m.id, m.subject, m.created_at, m.is_read, 
                           CONCAT(u.first_name, ' ', u.last_name) as recipient_name, u.role as recipient_role,
                           'professional' as recipient_type
                    FROM messages m
                    LEFT JOIN professional_users u ON m.recipient_id = u.id AND m.recipient_type = 'professional'
                    WHERE m.sender_id = ? AND m.sender_type = 'professional'
                    ORDER BY m.created_at DESC";
        } else { // inbox
            $sql = "SELECT m.id, m.subject, m.created_at, m.is_read, 
                           CONCAT(u.first_name, ' ', u.last_name) as sender_name, u.role as sender_role,
                           'professional' as sender_type
                    FROM messages m
                    LEFT JOIN professional_users u ON m.sender_id = u.id AND m.sender_type = 'professional'
                    WHERE m.recipient_id = ? AND m.recipient_type = 'professional'
                    ORDER BY m.created_at DESC";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    public function sendMessage($senderId, $senderType, $recipientId, $recipientType, $subject, $message) {
        try {
            // Note: Assumes your 'messages' table has sender_type and recipient_type columns
            $sql = "INSERT INTO messages (sender_id, sender_type, recipient_id, recipient_type, subject, message) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$senderId, $senderType, $recipientId, $recipientType, $subject, $message]);
            return ['success' => true];
        } catch (PDOException $e) {
            // This will no longer throw the 'column not found' error after the fix
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    public function getMessage($messageId, $userId) {
        $sql = "SELECT m.*, 
                       CONCAT(sender_prof.first_name, ' ', sender_prof.last_name) as sender_name_prof,
                       sender_player.username as sender_name_player,
                       CONCAT(recipient_prof.first_name, ' ', recipient_prof.last_name) as recipient_name_prof,
                       recipient_player.username as recipient_name_player
                FROM messages m
                LEFT JOIN professional_users sender_prof ON m.sender_id = sender_prof.id AND m.sender_type = 'professional'
                LEFT JOIN users sender_player ON m.sender_id = sender_player.id AND m.sender_type = 'player'
                LEFT JOIN professional_users recipient_prof ON m.recipient_id = recipient_prof.id AND m.recipient_type = 'professional'
                LEFT JOIN users recipient_player ON m.recipient_id = recipient_player.id AND m.recipient_type = 'player'
                WHERE m.id = ? AND ((m.recipient_id = ? AND m.recipient_type = 'professional') OR (m.sender_id = ? AND m.sender_type = 'professional'))";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$messageId, $userId, $userId]);
        $message = $stmt->fetch();
        if ($message) {
            $message['sender_name'] = $message['sender_name_prof'] ?? $message['sender_name_player'] ?? 'Unknown Sender';
            $message['recipient_name'] = $message['recipient_name_prof'] ?? $message['recipient_name_player'] ?? 'Unknown Recipient';
        }
        return $message;
    }

    public function markAsRead($messageId, $userId) {
        $sql = "UPDATE messages SET is_read = 1 WHERE id = ? AND recipient_id = ? AND recipient_type = 'professional'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$messageId, $userId]);
        return $stmt->rowCount() > 0;
    }
    
    public function deleteMessage($messageId, $userId) {
        $sql = "DELETE FROM messages WHERE id = ? AND ((sender_id = ? AND sender_type = 'professional') OR (recipient_id = ? AND recipient_type = 'professional'))";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$messageId, $userId, $userId]);
        return $stmt->rowCount() > 0;
    }
    
    public function getAvailableRecipients($currentUserId) {
        $sql = "SELECT id, username, first_name, last_name, role, organization 
                FROM professional_users 
                WHERE id != ? AND status = 'active'
                ORDER BY role, username";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$currentUserId]);
        return $stmt->fetchAll();
    }

    public function searchMessages($userId, $searchTerm) {
        $searchTerm = '%' . $searchTerm . '%';
        $sql = "SELECT m.id, m.subject, m.created_at, m.is_read,
                       CONCAT(sender.first_name, ' ', sender.last_name) as sender_name,
                       CONCAT(recipient.first_name, ' ', recipient.last_name) as recipient_name
                FROM messages m
                LEFT JOIN professional_users sender ON m.sender_id = sender.id AND m.sender_type = 'professional'
                LEFT JOIN professional_users recipient ON m.recipient_id = recipient.id AND m.recipient_type = 'professional'
                WHERE ((m.recipient_id = :userId AND m.recipient_type = 'professional') OR (m.sender_id = :userId AND m.sender_type = 'professional')) 
                  AND (m.subject LIKE :searchTerm OR m.message LIKE :searchTerm)
                ORDER BY m.created_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['userId' => $userId, 'searchTerm' => $searchTerm]);
        return $stmt->fetchAll();
    }
}


// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $pdo = getPDO();
    $messaging = new MessagingSystem($pdo);
    
    switch ($_POST['action']) {
        case 'get_messages':
            $box = isset($_POST['box']) ? $_POST['box'] : 'inbox';
            $messages = $messaging->getMessages($_SESSION['professional_id'], $box);
            echo json_encode($messages);
            exit();
            
        case 'send_message':
            $sender_id = (int)$_SESSION['professional_id'];
            $sender_type = 'professional';
            $recipient_id = (int)$_POST['recipient_id'];
            $recipient_type = $_POST['recipient_type'];
            $subject = trim($_POST['subject']);
            $message = trim($_POST['message']);
            
            if (empty($recipient_id) || empty($recipient_type) || empty($subject) || empty($message)) {
                echo json_encode(['success' => false, 'message' => 'All fields are required']);
                exit();
            }
            
            $result = $messaging->sendMessage($sender_id, $sender_type, $recipient_id, $recipient_type, $subject, $message);
            echo json_encode($result);
            exit();
            
        case 'get_message':
            $message_id = (int)$_POST['message_id'];
            $user_id = (int)$_SESSION['professional_id'];
            $message = $messaging->getMessage($message_id, $user_id);
            
            if ($message) {
                if ($message['recipient_id'] == $user_id && $message['recipient_type'] == 'professional') {
                    $messaging->markAsRead($message_id, $user_id);
                }
                echo json_encode(['success' => true, 'message' => $message]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Message not found']);
            }
            exit();
            
        case 'delete_message':
            $message_id = (int)$_POST['message_id'];
            $user_id = (int)$_SESSION['professional_id'];
            $result = $messaging->deleteMessage($message_id, $user_id);
            echo json_encode(['success' => $result]);
            exit();
            
        case 'get_recipients':
            $user_id = (int)$_SESSION['professional_id'];
            $recipients = $messaging->getAvailableRecipients($user_id);
            echo json_encode($recipients);
            exit();
            
        case 'search_messages':
            $user_id = (int)$_SESSION['professional_id'];
            $search_term = trim($_POST['search_term']);
            $messages = $messaging->searchMessages($user_id, $search_term);
            echo json_encode($messages);
            exit();
    }
}


// Get dashboard statistics
function getDashboardStats($coach_id) {
    $pdo = getPDO();
    $stats = [];
    
    // Total players
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM player_registrations");
    $stmt->execute();
    $stats['total_players'] = $stmt->fetch()['count'];
    
    // Approved players
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM player_registrations WHERE registration_status = 'approved'");
    $stmt->execute();
    $stats['approved_players'] = $stmt->fetch()['count'];
    
    // Pending players
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM player_registrations WHERE registration_status = 'pending'");
    $stmt->execute();
    $stats['pending_players'] = $stmt->fetch()['count'];
    
    // Training sessions
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM training_sessions WHERE coach_id = ?");
    $stmt->execute([$coach_id]);
    $stats['training_sessions'] = $stmt->fetch()['count'];
    
    // Recent training sessions
    $stmt = $pdo->prepare("SELECT * FROM training_sessions WHERE coach_id = ? ORDER BY session_date DESC LIMIT 5");
    $stmt->execute([$coach_id]);
    $stats['recent_trainings'] = $stmt->fetchAll();
    
    return $stats;
}

// Get players (including user_id for messaging)
function getPlayers() {
    $pdo = getPDO();
    $stmt = $pdo->query("SELECT pr.*, 
                         CONCAT(FLOOR(DATEDIFF(NOW(), 
                         STR_TO_DATE(CONCAT(pr.birth_year, '-', pr.birth_month, '-', pr.birth_day), '%Y-%m-%d'))/365.25), 
                         '-', 
                         FLOOR(DATEDIFF(NOW(), 
                         STR_TO_DATE(CONCAT(pr.birth_year, '-', pr.birth_month, '-', pr.birth_day), '%Y-%m-%d'))/365.25)+2) 
                         AS age_group_calc 
                         FROM player_registrations pr 
                         ORDER BY pr.first_name, pr.last_name");
    return $stmt->fetchAll();
}

// Get training schedule by age group
function getTrainingSchedule($coach_id) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT 
                          age_group,
                          session_date as date,
                          start_time as time,
                          focus_area as focus
                          FROM training_sessions
                          WHERE coach_id = ?
                          GROUP BY age_group, date, time, focus
                          ORDER BY age_group, date, time");
    $stmt->execute([$coach_id]);
    
    $schedule = [];
    while ($row = $stmt->fetch()) {
        $age_group = $row['age_group'];
        if (!isset($schedule[$age_group])) {
            $schedule[$age_group] = [];
        }
        $schedule[$age_group][] = $row;
    }
    
    return $schedule;
}

// Get match videos
function getMatchVideos($coach_id) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT 
                          id, 
                          match_name, 
                          match_date, 
                          age_group, 
                          opponent, 
                          location,
                          video_url
                          FROM match_videos
                          WHERE coach_id = ?
                          ORDER BY match_date DESC");
    
}

// Get course modules
function getCourseModules() {
    return [
        "1" => ["title" => "Module 1: Introduction to Football & Basic Rules", "6-8" => ["Understand what football is", "Learn the basic rules (no hands, out of bounds, goals)", "Simple games to practice ball control", "Quiz"], "9-11" => ["Detailed rules overview", "Importance of teamwork", "Basic positions on the field", "Quiz"], "12-14" => ["Understanding offside rule", "Fouls and penalties explained", "Referee signals and game flow", "Quiz"], "15-18" => ["In-depth study of game strategies", "Role of different positions tactically", "Analysis of professional matches for rules", "Quiz"]],
        "2" => ["title" => "Module 2: Ball Control & Dribbling", "6-8" => ["Basic dribbling with feet", "Simple ball stops and starts", "Fun obstacle dribbling drills", "Quiz"], "9-11" => ["Using different parts of the foot", "Changing direction while dribbling", "Shielding the ball from opponents", "Quiz"], "12-14" => ["Advanced dribbling moves (step-overs, feints)", "Dribbling under pressure", "One-on-one dribbling drills", "Quiz"], "15-18" => ["Creative dribbling techniques", "Dribbling in tight spaces", "Integrating dribbling into team play", "Quiz"]],
        "3" => ["title" => "Module 3: Passing & Receiving", "6-8" => ["Simple short passes with inside foot", "Basic receiving and controlling the ball", "Passing games in pairs", "Quiz"], "9-11" => ["Passing accuracy drills", "Receiving with different body parts (chest, thigh)", "Introduction to long passes", "Quiz"], "12-14" => ["Passing under pressure", "One-touch passing drills", "Communication during passing", "Quiz"], "15-18" => ["Tactical passing (through balls, switches)", "Quick combination plays", "Analyzing passing in real matches", "Quiz"]],
        "4" => ["title" => "Module 4: Shooting & Scoring", "6-8" => ["Basic shooting techniques with inside foot", "Target practice (shooting at goals)", "Fun shooting games", "Quiz"], "9-11" => ["Shooting with laces for power", "Accuracy and placement drills", "Shooting on the move", "Quiz"], "12-14" => ["Shooting under pressure", "Volley and half-volley shooting", "Penalty kick basics", "Quiz"], "15-18" => ["Finishing techniques in different scenarios", "Shooting with both feet", "Advanced penalty and free kick techniques", "Quiz"]],
        "5" => ["title" => "Module 5: Fitness & Team Play", "6-8" => ["Basic warm-ups and stretches", "Fun fitness games to improve stamina", "Introduction to playing as a team", "Final quiz"], "9-11" => ["Endurance and speed drills", "Understanding positions in team play", "Basic tactical awareness", "Final quiz"], "12-14" => ["Position-specific fitness", "Team formations and roles", "Communication on the field", "Final quiz"], "15-18" => ["Advanced conditioning and recovery", "In-depth tactical formations", "Leadership and decision making", "Final quiz"]]
    ];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    try {
        $pdo = getPDO();
        
        switch ($_POST['form_action']) {
            case 'upload_video':
                if (isset($_FILES['match_video']) && $_FILES['match_video']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = 'uploads/videos/';
                    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
                    
                    $fileName = uniqid() . '_' . basename($_FILES['match_video']['name']);
                    $filePath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($_FILES['match_video']['tmp_name'], $filePath)) {
                        $stmt = $pdo->prepare("INSERT INTO match_videos (coach_id, match_name, video_url, match_date, age_group, opponent, location) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$_SESSION['professional_id'], htmlspecialchars(trim($_POST['match_title'])), $filePath, $_POST['match_date'], htmlspecialchars(trim($_POST['match_age_group'])), htmlspecialchars(trim($_POST['opponent_name'])), htmlspecialchars(trim($_POST['match_location']))]);
                        $_SESSION['success_message'] = 'Video uploaded successfully!';
                    } else {
                        throw new Exception("Failed to move uploaded file");
                    }
                } else {
                    throw new Exception("File upload error: " . ($_FILES['match_video']['error'] ?? 'Unknown Error'));
                }
                break;
                
            case 'update_coach_profile':
                $firstName = htmlspecialchars(trim($_POST['first_name']));
                $lastName = htmlspecialchars(trim($_POST['last_name']));
                $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
                
                if (!$email) { throw new Exception("Invalid email address"); }
                
                $stmt = $pdo->prepare("UPDATE professional_users SET first_name = ?, last_name = ?, email = ? WHERE id = ?");
                $stmt->execute([$firstName, $lastName, $email, $_SESSION['professional_id']]);
                
                $_SESSION['success_message'] = 'Profile updated successfully!';
                break;
                
            case 'change_coach_password':
                $currentPassword = $_POST['current_password'];
                $newPassword = $_POST['new_password'];
                $confirmPassword = $_POST['confirm_new_password'];
                
                if ($newPassword !== $confirmPassword) { throw new Exception("New passwords don't match"); }
                
                $stmt = $pdo->prepare("SELECT password FROM professional_users WHERE id = ?");
                $stmt->execute([$_SESSION['professional_id']]);
                $user = $stmt->fetch();
                
                if (!password_verify($currentPassword, $user['password'])) { throw new Exception("Current password is incorrect"); }
                
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE professional_users SET password = ? WHERE id = ?");
                $stmt->execute([$hashedPassword, $_SESSION['professional_id']]);
                
                $_SESSION['success_message'] = 'Password changed successfully!';
                break;
        }
        
        header("Location: coach-dashboard.php");
        exit();
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
        header("Location: coach-dashboard.php");
        exit();
    }
}

// Get data for dashboard
$stats = getDashboardStats($_SESSION['professional_id']);
$players = getPlayers();
$match_videos = getMatchVideos($_SESSION['professional_id']);
$course_modules = getCourseModules();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coach Dashboard | Vertwal Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #1e3c72;
            --secondary: #2a5298;
            --accent: #f8d458;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --light: #f8f9fa;
            --dark: #343a40;
            --gray: #6c757d;
            --white: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            height: 100vh;
            background: var(--primary);
            color: white;
            transition: all 0.3s ease;
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
        }

        .sidebar.collapsed {
            width: 70px;
        }

        .sidebar-header {
            padding: 20px;
            background: rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .sidebar-header h3 {
            color: white;
            font-size: 1.2rem;
            white-space: nowrap;
        }

        #sidebarToggle {
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        #sidebarToggle {
            transition: transform 0.3s ease;
        }
        .sidebar.collapsed #sidebarToggle {
            transform: rotate(180deg);
        }

        #sidebarToggle:hover {
            color: var(--accent);
        }

        .sidebar-menu {
            padding: 20px 0;
            flex-grow: 1;
            overflow-y: auto;
        }

        .menu-item {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
            color: rgba(255, 255, 255, 0.8);
        }

        .menu-item:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .menu-item.active {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border-left: 3px solid var(--accent);
        }

        .menu-item i {
            margin-right: 10px;
            font-size: 1.1rem;
            width: 24px;
            text-align: center;
        }

        .menu-item span {
            white-space: nowrap;
        }

        .sidebar.collapsed .sidebar-header h3,
        .sidebar.collapsed .menu-item span {
            display: none;
        }

        .sidebar.collapsed .menu-item i {
            margin-right: 0;
            font-size: 1.3rem;
        }
        .sidebar.collapsed .sidebar-header {
            justify-content: center;
        }

        .main-content {
            margin-left: 250px;
            transition: all 0.3s ease;
            min-height: 100vh;
        }

        .main-content.expanded {
            margin-left: 70px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            background: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header h2 {
            color: var(--primary);
            font-size: 1.5rem;
            margin-bottom: 5px;
        }

        .header p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .user-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-profile {
            position: relative;
            display: flex;
            align-items: center;
            cursor: pointer;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 10px;
        }

        .user-dropdown {
            display: none;
            position: absolute;
            right: 0;
            top: 50px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            width: 180px;
            z-index: 1000;
        }

        .user-dropdown a {
            display: block;
            padding: 10px 15px;
            text-decoration: none;
            color: var(--dark);
            transition: all 0.3s ease;
        }

        .user-dropdown a:hover {
            background: #f5f7fa;
            color: var(--primary);
        }

        .content-section {
            padding: 20px;
            display: none;
        }

        .content-section.active {
            display: block;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .dashboard-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        .dashboard-card .card-content {
            flex-grow: 1;
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .card-header h3 {
            font-size: 1.2rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-bottom: 15px;
        }

        .icon.total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .icon.approved { background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%); }
        .icon.pending { background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); }
        .icon.rejected { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); }

        .value {
            font-size: 2rem;
            font-weight: bold;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .data-table th, .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .data-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--dark);
        }

        .data-table tr:hover {
            background: #f5f7fa;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status.pending, .status.draft { background: #fff3cd; color: #856404; }
        .status.approved, .status.completed, .status.read { background: #d4edda; color: #155724; }
        .status.rejected { background: #f8d7da; color: #721c24; }
        .status.unread { background: var(--warning); color: var(--dark); }

        .action-btn {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--secondary); transform: translateY(-2px); box-shadow: 0 3px 10px rgba(30, 60, 114, 0.2); }
        .btn-danger { background-color: var(--danger); color: white; }
        .btn-danger:hover { background-color: #c82333; }
        .btn-info { background-color: var(--info); color: white; }
        .btn-info:hover { background-color: #138496; }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #218838; }

        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(30, 60, 114, 0.1);
        }

        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--dark); }
        .form-row { display: flex; gap: 20px; margin-bottom: 20px; }
        .form-row .form-group { flex: 1; }

        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0s 0.3s;
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
            transition: opacity 0.3s ease;
        }

        .modal-content {
            background: white;
            border-radius: 10px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 5px 30px rgba(0, 0, 0, 0.2);
            transform: translateY(-20px);
            transition: transform 0.3s ease;
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 20px; border-bottom: 1px solid #eee; }
        .modal-title { font-size: 1.3rem; font-weight: 600; color: var(--primary); }
        .modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--gray); transition: all 0.3s ease; }
        .modal-close:hover { color: var(--danger); }
        .modal-body { padding: 20px; }
        #messageViewContent { white-space: pre-wrap; background-color: #f8f9fa; padding: 15px; border-radius: 5px; border: 1px solid #eee; min-height: 100px; }
        #messageViewMeta { margin-top: 20px; font-size: 0.9em; color: var(--gray); line-height: 1.5; padding-bottom: 15px; border-bottom: 1px solid #eee; margin-bottom: 15px; }
        #messageViewMeta strong { color: var(--dark); }
        .modal-footer { padding: 15px 20px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 10px; }
        .footer { background: white; padding: 20px; margin-top: 30px; border-top: 1px solid #eee; }
        
        .notification-toast { position: fixed; bottom: 20px; right: 20px; background-color: var(--dark); color: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.2); z-index: 9999; opacity: 0; transform: translateY(20px); transition: all 0.3s ease-in-out; }
        .notification-toast.show { opacity: 1; transform: translateY(0); }
        .notification-toast.success { background-color: var(--success); }
        .notification-toast.error { background-color: var(--danger); }
        
        .messages-header { display: flex; justify-content: space-between; align-items: center; padding: 15px; background-color: #f8f9fa; border-bottom: 1px solid #eee; }
        #messageBoxTitle { font-size: 1.2rem; font-weight: 600; color: var(--primary); }
        .message-tabs { display: flex; gap: 5px; }
        .message-tab { padding: 8px 15px; border: 1px solid #ddd; background-color: white; border-radius: 5px; cursor: pointer; }
        .message-tab.active { background-color: var(--primary); color: white; border-color: var(--primary); }
        .message-search-container { padding: 15px; }
        .message-actions { display: flex; gap: 10px; align-items: center; }
        
        .course-age-groups { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; }
        .age-group-column h4 { color: var(--secondary); border-bottom: 2px solid var(--secondary); padding-bottom: 5px; margin-bottom: 10px; font-size: 1rem; }
        .age-group-column ul { list-style-type: none; padding-left: 0; }
        .age-group-column li { padding: 5px 0 5px 20px; position: relative; color: var(--dark); font-size: 0.9rem; }
        .age-group-column li::before { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; color: var(--success); position: absolute; left: 0; top: 9px; font-size: 0.8rem; }
        
        @media (max-width: 992px) {
            .sidebar { width: 70px; }
            .sidebar .sidebar-header h3, .sidebar .menu-item span { display: none; }
            .sidebar .menu-item i { margin-right: 0; font-size: 1.3rem; }
            .sidebar .sidebar-header { justify-content: center; }
            .main-content { margin-left: 70px; }
        }
    </style>
</head>
<body>
    <!-- Sidebar Navigation -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3>Coach System</h3>
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
            <div class="menu-item" data-section="courses">
                <i class="fas fa-book"></i>
                <span>Course List</span>
            </div>
            <div class="menu-item" data-section="videos">
                <i class="fas fa-video"></i>
                <span>Match Videos</span>
            </div>
            <div class="menu-item" data-section="messages">
                <i class="fas fa-envelope"></i>
                <span>Messages</span>
            </div>
            <div class="menu-item" data-section="settings">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </div>
            <div class="menu-item">
                 <a href="logout.php" style="color: inherit; text-decoration: none; display: flex; align-items: center; width:100%;">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="main-content" id="mainContent">
        <!-- Header with User Profile -->
        <div class="header">
            <div>
                <h2>Ethiopian Online Football Scouting</h2>
                <p>Vertwal Academy</p>
            </div>
            <div class="user-actions">
                <div class="user-profile">
                    <div class="user-avatar"><?php echo strtoupper(substr($coach['first_name'] ?? $coach['username'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($coach['first_name'] ?? $coach['username']); ?></span>
                    <div class="user-dropdown" style="display: none;">
                        <a href="logout.php">Logout</a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Dashboard Content -->
        <div class="content-section active" data-content="dashboard">
            <h2 style="margin-bottom: 20px;">Coach Dashboard Overview</h2>

            <section class="cards">
                <div class="dashboard-card">
                    <div class="icon total"><i class="fas fa-users"></i></div>
                    <div class="card-content">
                        <div class="value"><?php echo $stats['total_players']; ?></div>
                        <div class="label">Total Players</div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="icon approved"><i class="fas fa-user-check"></i></div>
                    <div class="card-content">
                        <div class="value"><?php echo $stats['approved_players']; ?></div>
                        <div class="label">Approved Players</div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="icon pending"><i class="fas fa-user-clock"></i></div>
                    <div class="card-content">
                        <div class="value"><?php echo $stats['pending_players']; ?></div>
                        <div class="label">Pending Players</div>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="icon rejected"><i class="fas fa-running"></i></div>
                    <div class="card-content">
                        <div class="value"><?php echo $stats['training_sessions']; ?></div>
                        <div class="label">Training Sessions</div>
                    </div>
                </div>
            </section>

             <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; margin-top: 20px;">
                <div class="dashboard-card">
                    <div class="card-header"><h3><i class="fas fa-running"></i> Recent Training Sessions</h3></div>
                    <div class="card-body">
                        <table class="data-table">
                            <thead><tr><th>Date</th><th>Age Group</th><th>Focus Area</th></tr></thead>
                            <tbody>
                                <?php if (empty($stats['recent_trainings'])): ?>
                                    <tr><td colspan="3" style="text-align:center; padding:15px;">No recent sessions found.</td></tr>
                                <?php else: foreach ($stats['recent_trainings'] as $training): ?>
                                    <tr><td><?php echo htmlspecialchars($training['session_date']); ?></td><td><?php echo htmlspecialchars($training['age_group']); ?></td><td><?php echo htmlspecialchars($training['focus_area']); ?></td></tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="dashboard-card">
                    <div class="card-header"><h3><i class="fas fa-futbol"></i> My Match Videos</h3></div>
                    <div class="card-body">
                        <table class="data-table">
                            <thead><tr><th>Date</th><th>Opponent</th><th>Age Group</th></tr></thead>
                            <tbody>
                                <?php if (empty($match_videos)): ?>
                                     <tr><td colspan="3" style="text-align:center; padding:15px;">No videos uploaded yet.</td></tr>
                                <?php else: foreach (array_slice($match_videos, 0, 5) as $match): ?>
                                    <tr><td><?php echo htmlspecialchars($match['match_date']); ?></td><td><?php echo htmlspecialchars($match['opponent']); ?></td><td><?php echo htmlspecialchars($match['age_group']); ?></td></tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Player Management Content -->
        <div class="content-section" data-content="players">
             <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2>Player Management</h2>
            </div>
            <table class="data-table" id="playersTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Player Name</th>
                        <th>Age</th>
                        <th>Age Group</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody class="players-data">
                    <?php foreach ($players as $player): 
                        $birthDate = "{$player['birth_year']}-{$player['birth_month']}-{$player['birth_day']}";
                        $age = date_diff(date_create($birthDate), date_create('today'))->y;
                    ?>
                    <tr>
                        <td>#PL-<?php echo str_pad($player['id'], 3, '0', STR_PAD_LEFT); ?></td>
                        <td><?php echo htmlspecialchars($player['first_name'] . ' ' . $player['last_name']); ?></td>
                        <td><?php echo $age; ?></td>
                        <td><?php echo htmlspecialchars($player['age_group_calc']); ?></td>
                        <td><span class="status <?php echo htmlspecialchars($player['registration_status']); ?>"><?php echo htmlspecialchars($player['registration_status']); ?></span></td>
                        <td>
                            <button class="action-btn btn-info message-player-btn"
                                    data-recipient-id="<?php echo htmlspecialchars($player['user_id']); ?>"
                                    data-recipient-name="<?php echo htmlspecialchars($player['first_name'] . ' ' . $player['last_name']); ?>"
                                    data-recipient-type="player">
                                <i class="fas fa-paper-plane"></i> Message
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Course List Content -->
        <div class="content-section" data-content="courses">
            <h2 style="margin-bottom: 20px;">Training Course Modules</h2>
            <?php foreach ($course_modules as $module_id => $module): ?>
            <div class="dashboard-card" style="margin-bottom:20px;">
                <div class="card-header"><h3 class="module-title"><?php echo htmlspecialchars($module['title']); ?></h3></div>
                <div class="card-body">
                    <div class="course-age-groups">
                        <?php foreach (['6-8', '9-11', '12-14', '15-18'] as $age_group_key): if (isset($module[$age_group_key])): ?>
                            <div class="age-group-column">
                                <h4><?php echo $age_group_key; ?> Years</h4>
                                <ul>
                                    <?php foreach ($module[$age_group_key] as $topic): ?>
                                        <li><?php echo htmlspecialchars($topic); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Match Videos Content -->
        <div class="content-section" data-content="videos">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2>Match Videos</h2>
                <button class="action-btn btn-primary" id="uploadVideoBtn"><i class="fas fa-upload"></i> Upload Match Video</button>
            </div>
            <div class="upload-container dashboard-card" id="uploadContainer" style="display: none;">
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="form_action" value="upload_video">
                    <div class="form-group"><label class="form-label" for="matchTitle">Match Title</label><input type="text" id="matchTitle" name="match_title" class="form-control" placeholder="E.g., Vertwal Academy vs Addis United" required></div>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label" for="matchDate">Date</label><input type="date" id="matchDate" name="match_date" class="form-control" required></div>
                        <div class="form-group"><label class="form-label" for="matchAgeGroup">Age Group</label><select id="matchAgeGroup" name="match_age_group" class="form-control" required><option>6-8 Years</option><option>9-11 Years</option><option>12-14 Years</option><option>15-18 Years</option></select></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label" for="opponentName">Opponent</label><input type="text" id="opponentName" name="opponent_name" class="form-control" placeholder="Opponent team name" required></div>
                        <div class="form-group"><label class="form-label" for="matchLocation">Location</label><input type="text" id="matchLocation" name="match_location" class="form-control" required></div>
                    </div>
                    <div class="form-group"><label class="form-label" for="matchVideoInput">Video File</label><input type="file" name="match_video" id="matchVideoInput" class="form-control" accept="video/*" required></div>
                    <div style="text-align: right; margin-top: 20px;"><button type="button" class="action-btn btn-secondary" id="cancelUploadBtn">Cancel</button><button type="submit" class="action-btn btn-primary">Upload Video</button></div>
                </form>
            </div>
            <table class="data-table" id="videosTable">
                <thead><tr><th>Title</th><th>Date</th><th>Age Group</th><th>Opponent</th><th>Actions</th></tr></thead>
                <tbody>
                     <?php if (empty($match_videos)): ?>
                         <tr><td colspan="5" style="text-align:center; padding:15px;">No videos uploaded yet.</td></tr>
                    <?php else: foreach ($match_videos as $video): ?>
                        <tr><td><?php echo htmlspecialchars($video['match_name']); ?></td><td><?php echo htmlspecialchars($video['match_date']); ?></td><td><?php echo htmlspecialchars($video['age_group']); ?></td><td><?php echo htmlspecialchars($video['opponent']); ?></td><td><button class="action-btn btn-primary view-video-btn" data-video-path="<?php echo htmlspecialchars($video['video_url']); ?>"><i class="fas fa-play"></i> Watch</button></td></tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Messages Section -->
        <div class="content-section" data-content="messages">
            <div class="data-table">
                <div class="messages-header">
                    <h3 id="messageBoxTitle">Inbox</h3>
                     <div class="message-actions">
                        <div class="message-tabs">
                            <button id="inboxBtn" class="message-tab active">Inbox</button>
                            <button id="sentBtn" class="message-tab">Sent</button>
                        </div>
                        <button id="newMessageBtn" class="action-btn btn-primary"><i class="fas fa-plus"></i> New Message</button>
                    </div>
                </div>
                <div class="message-search-container"><input type="text" id="messageSearch" class="form-control" placeholder="Search messages by subject, content or user..."></div>
                <div>
                    <table style="width:100%;">
                        <thead><tr><th id="message-from-to-header">From</th><th>Subject</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody id="messagesList"><!-- Messages will be loaded here via AJAX --></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Settings Content -->
        <div class="content-section" data-content="settings">
            <h2 style="margin-bottom: 20px;">Coach Settings</h2>
            <div class="dashboard-card">
                <div class="card-header"><h3><i class="fas fa-user-cog"></i> Profile Settings</h3></div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="form_action" value="update_coach_profile">
                        <div class="form-group"><label class="form-label" for="coachFirstName">First Name</label><input type="text" id="coachFirstName" name="first_name" class="form-control" value="<?php echo htmlspecialchars($coach['first_name'] ?? ''); ?>" required></div>
                        <div class="form-group"><label class="form-label" for="coachLastName">Last Name</label><input type="text" id="coachLastName" name="last_name" class="form-control" value="<?php echo htmlspecialchars($coach['last_name'] ?? ''); ?>" required></div>
                        <div class="form-group"><label class="form-label" for="coachEmail">Email</label><input type="email" id="coachEmail" name="email" class="form-control" value="<?php echo htmlspecialchars($coach['email'] ?? ''); ?>" required></div>
                        <div style="text-align: right; margin-top: 20px;"><button type="submit" class="action-btn btn-primary">Update Profile</button></div>
                    </form>
                </div>
            </div>
            <div class="dashboard-card" style="margin-top: 20px;">
                <div class="card-header"><h3><i class="fas fa-lock"></i> Change Password</h3></div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="form_action" value="change_coach_password">
                        <div class="form-group"><label class="form-label" for="currentPassword">Current Password</label><input type="password" id="currentPassword" name="current_password" class="form-control" required></div>
                        <div class="form-group"><label class="form-label" for="newPassword">New Password</label><input type="password" id="newPassword" name="new_password" class="form-control" required></div>
                        <div class="form-group"><label class="form-label" for="confirmNewPassword">Confirm New Password</label><input type="password" id="confirmNewPassword" name="confirm_new_password" class="form-control" required></div>
                        <div style="text-align: right; margin-top: 20px;"><button type="submit" class="action-btn btn-primary">Change Password</button></div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p style="text-align: center; color: var(--gray); margin-top: 10px;">&copy; <?php echo date('Y'); ?> Vertwal Football Academy. All rights reserved.</p>
        </div>
    </div>

    <!-- Modals -->
    <div id="newMessageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">New Message</h2>
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="messageForm">
                    <div id="specificRecipientInfo" style="display:none;">
                         <div class="form-group">
                            <label class="form-label">Recipient</label>
                            <input type="text" id="specificRecipientName" class="form-control" readonly style="background-color: #e9ecef;">
                         </div>
                    </div>
                    
                    <div id="generalRecipientSelect" class="form-group">
                        <label class="form-label">Recipient</label>
                        <select id="recipientSelect" class="form-control">
                            <option value="">Loading recipients...</option>
                        </select>
                    </div>
                    
                    <input type="hidden" id="recipientIdInput" name="recipient_id">
                    <input type="hidden" id="recipientTypeInput" name="recipient_type">

                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <input type="text" id="messageSubject" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Message</label>
                        <textarea id="messageContent" class="form-control" rows="5" required></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="action-btn btn-secondary modal-close">Cancel</button>
                <button type="submit" form="messageForm" class="action-btn btn-primary"><i class="fas fa-paper-plane"></i> Send Message</button>
            </div>
        </div>
    </div>

    <div id="viewMessageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header"><h2 id="messageViewTitle" class="modal-title"></h2><button class="modal-close">&times;</button></div>
            <div class="modal-body"><div id="messageViewMeta"></div><div id="messageViewContent"></div></div>
            <div class="modal-footer"><button type="button" class="action-btn btn-secondary modal-close">Close</button></div>
        </div>
    </div>
    
    <div class="modal" id="videoPlayerModal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header"><div class="modal-title">Match Video</div><button class="modal-close" id="closeVideoModal">&times;</button></div>
            <div class="modal-body" style="padding:0;"><video id="matchVideoPlayer" controls style="width: 100%;"><source src="" type="video/mp4"></video></div>
        </div>
    </div>
    
    <div id="notification-toast" class="notification-toast"></div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        
        const sidebarToggle = document.getElementById('sidebarToggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                document.getElementById('sidebar').classList.toggle('collapsed');
                document.getElementById('mainContent').classList.toggle('expanded');
            });
        }
        
        document.querySelectorAll('.menu-item').forEach(item => {
            if(item.querySelector('a')) return;
            item.addEventListener('click', function() {
                const sectionId = this.getAttribute('data-section');
                if (!sectionId) return;

                document.querySelectorAll('.menu-item.active').forEach(i => i.classList.remove('active'));
                this.classList.add('active');
                
                document.querySelectorAll('.content-section.active').forEach(s => s.classList.remove('active'));
                document.querySelector(`.content-section[data-content="${sectionId}"]`)?.classList.add('active');
                
                if (sectionId === 'messages') {
                    loadMessages('inbox');
                }
            });
        });

        const userProfile = document.querySelector('.user-profile');
        if (userProfile) {
            userProfile.addEventListener('click', (e) => {
                e.stopPropagation();
                const dropdown = userProfile.querySelector('.user-dropdown');
                if (dropdown) dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
            });
        }
        document.addEventListener('click', () => {
            document.querySelector('.user-dropdown')?.style.setProperty('display', 'none', 'important');
        });

        const openModal = (selector) => document.querySelector(selector)?.classList.add('show');
        const closeModal = (selector) => document.querySelector(selector)?.classList.remove('show');
        
        document.querySelectorAll('.modal-close').forEach(btn => {
            btn.addEventListener('click', () => btn.closest('.modal')?.classList.remove('show'));
        });

        document.getElementById('uploadVideoBtn')?.addEventListener('click', () => {
            document.getElementById('uploadContainer').style.display = 'block';
        });
        document.getElementById('cancelUploadBtn')?.addEventListener('click', () => {
            document.getElementById('uploadContainer').style.display = 'none';
        });

        document.querySelectorAll('.view-video-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const videoPlayer = document.getElementById('matchVideoPlayer');
                if (videoPlayer) {
                    videoPlayer.querySelector('source').src = this.dataset.videoPath;
                    videoPlayer.load();
                }
                openModal('#videoPlayerModal');
            });
        });

        document.getElementById('closeVideoModal')?.addEventListener('click', () => {
            document.getElementById('matchVideoPlayer')?.pause();
            closeModal('#videoPlayerModal');
        });
        
        const messagesList = document.getElementById('messagesList');
        const messageFromToHeader = document.getElementById('message-from-to-header');
        let currentBox = 'inbox';
        
        const showNotification = (message, type = 'info') => {
            const toast = document.getElementById('notification-toast');
            toast.textContent = message;
            toast.className = `notification-toast show ${type}`;
            setTimeout(() => { toast.classList.remove('show'); }, 3000);
        };
        
        const fetchAPI = async (body) => {
            try {
                const response = await fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: new URLSearchParams(body).toString() });
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                return await response.json();
            } catch (error) {
                console.error('API Fetch Error:', error);
                showNotification('An error occurred. Please check the console.', 'error');
                return null;
            }
        };

        const renderMessages = (messages) => {
            if (!messagesList) return;
            messagesList.innerHTML = '';
            if (!messages || messages.length === 0) {
                messagesList.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 20px;">No messages found.</td></tr>';
                return;
            }
            messages.forEach(msg => {
                const isUnread = currentBox === 'inbox' && !msg.is_read;
                const row = document.createElement('tr');
                row.style.fontWeight = isUnread ? 'bold' : 'normal';
                const senderRecipient = currentBox === 'inbox' ? (msg.sender_name || 'Player/Other') : (msg.recipient_name || 'Player/Other');
                row.innerHTML = `<td>${senderRecipient}</td><td>${msg.subject}</td><td>${new Date(msg.created_at).toLocaleString()}</td><td><span class="status ${isUnread ? 'unread' : 'read'}">${isUnread ? 'Unread' : 'Read'}</span></td><td><button class="action-btn btn-primary" onclick="viewMessage(${msg.id})"><i class="fas fa-eye"></i></button><button class="action-btn btn-danger" onclick="deleteMessage(${msg.id})"><i class="fas fa-trash"></i></button></td>`;
                messagesList.appendChild(row);
            });
        };
        
        const loadMessages = async (box) => {
            currentBox = box;
            document.getElementById('messageBoxTitle').textContent = box.charAt(0).toUpperCase() + box.slice(1);
            if (messageFromToHeader) messageFromToHeader.textContent = box === 'inbox' ? 'From' : 'To';
            document.getElementById('inboxBtn')?.classList.toggle('active', box === 'inbox');
            document.getElementById('sentBtn')?.classList.toggle('active', box === 'sent');
            renderMessages(await fetchAPI({ action: 'get_messages', box: box }));
        };
        
        window.viewMessage = async (messageId) => {
            const data = await fetchAPI({ action: 'get_message', message_id: messageId });
            if (data && data.success) {
                const msg = data.message;
                document.getElementById('messageViewTitle').textContent = msg.subject;
                document.getElementById('messageViewContent').textContent = msg.message;
                document.getElementById('messageViewMeta').innerHTML = `<strong>From:</strong> ${msg.sender_name}<br><strong>To:</strong> ${msg.recipient_name}<br><strong>Date:</strong> ${new Date(msg.created_at).toLocaleString()}`;
                openModal('#viewMessageModal');
                if (currentBox === 'inbox') loadMessages('inbox');
            } else {
                showNotification(data.message || 'Could not load message.', 'error');
            }
        };

        window.deleteMessage = async (messageId) => {
            if (confirm('Are you sure you want to delete this message?')) {
                const data = await fetchAPI({ action: 'delete_message', message_id: messageId });
                if (data && data.success) {
                    showNotification('Message deleted successfully.', 'success');
                    loadMessages(currentBox);
                } else {
                    showNotification('Failed to delete message.', 'error');
                }
            }
        };

        document.getElementById('newMessageBtn')?.addEventListener('click', async () => {
            document.getElementById('generalRecipientSelect').style.display = 'block';
            document.getElementById('specificRecipientInfo').style.display = 'none';
            const recipientSelect = document.getElementById('recipientSelect');
            recipientSelect.required = true;
            recipientSelect.innerHTML = '<option value="">Loading...</option>';
            const recipients = await fetchAPI({ action: 'get_recipients' });
            recipientSelect.innerHTML = '<option value="">Select a recipient...</option>';
            if (recipients) {
                recipients.forEach(r => {
                    const option = document.createElement('option');
                    option.value = r.id;
                    let name = (r.first_name || r.last_name) ? `${r.first_name} ${r.last_name}`.trim() : r.username;
                    option.textContent = `${name} (${r.role}) - ${r.organization || 'N/A'}`;
                    recipientSelect.appendChild(option);
                });
            }
            openModal('#newMessageModal');
        });

        document.querySelectorAll('.message-player-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('generalRecipientSelect').style.display = 'none';
                document.getElementById('specificRecipientInfo').style.display = 'block';
                document.getElementById('recipientSelect').required = false;
                document.getElementById('specificRecipientName').value = this.dataset.recipientName + " (Player)";
                document.getElementById('recipientIdInput').value = this.dataset.recipientId;
                document.getElementById('recipientTypeInput').value = this.dataset.recipientType;
                openModal('#newMessageModal');
            });
        });
        
        document.getElementById('messageForm')?.addEventListener('submit', async function(e) {
            e.preventDefault();
            let recipientId, recipientType;
            if (document.getElementById('generalRecipientSelect').style.display === 'block') {
                recipientId = document.getElementById('recipientSelect').value;
                recipientType = 'professional';
            } else {
                recipientId = document.getElementById('recipientIdInput').value;
                recipientType = document.getElementById('recipientTypeInput').value;
            }
            if (!recipientId) { showNotification('Please select a recipient.', 'error'); return; }

            const data = await fetchAPI({ action: 'send_message', recipient_id: recipientId, recipient_type: recipientType, subject: document.getElementById('messageSubject').value, message: document.getElementById('messageContent').value });
            if (data && data.success) {
                showNotification('Message sent successfully!', 'success');
                closeModal('#newMessageModal');
                this.reset();
            } else {
                showNotification(data.message || 'Failed to send message.', 'error');
            }
        });
        
        document.getElementById('inboxBtn')?.addEventListener('click', () => loadMessages('inbox'));
        document.getElementById('sentBtn')?.addEventListener('click', () => loadMessages('sent'));

        let searchTimeout;
        document.getElementById('messageSearch')?.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(async () => {
                const searchTerm = this.value.trim();
                if (searchTerm.length > 2) {
                     renderMessages(await fetchAPI({ action: 'search_messages', search_term: searchTerm }));
                } else if (searchTerm.length === 0) {
                    loadMessages(currentBox);
                }
            }, 300);
        });
        
        <?php if (isset($_SESSION['success_message'])): ?>
            showNotification("<?php echo addslashes($_SESSION['success_message']); ?>", 'success');
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            showNotification("Error: <?php echo addslashes($_SESSION['error_message']); ?>", 'error');
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

    });
    </script>
</body>
</html>