
<?php
require_once 'config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if medical professional is logged in
if (!isset($_SESSION['professional_logged_in']) || $_SESSION['professional_role'] !== 'medical') {
    header('Location:login.php');
    exit();
}

// Database connection function
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

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $pdo = getPDO();
    
    try {
        switch ($_POST['action']) {
            case 'get_medical_records':
                $player_id = (int)$_POST['player_id'];
                $stmt = $pdo->prepare("SELECT mr.*, pr.first_name, pr.last_name 
                                     FROM medical_records mr
                                     JOIN player_registrations pr ON mr.player_id = pr.id
                                     WHERE mr.player_id = ?
                                     ORDER BY mr.record_date DESC");
                $stmt->execute([$player_id]);
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($records);
                exit();
                
            case 'add_medical_record':
                $player_id = (int)$_POST['player_id'];
                $record_type = htmlspecialchars($_POST['record_type']);
                $description = htmlspecialchars($_POST['description']);
                $diagnosis = htmlspecialchars($_POST['diagnosis']);
                $treatment = htmlspecialchars($_POST['treatment']);
                $status = htmlspecialchars($_POST['status']);
                
                $stmt = $pdo->prepare("INSERT INTO medical_records 
                                     (player_id, medical_staff_id, record_type, record_date, description, diagnosis, treatment, status) 
                                     VALUES (?, ?, ?, NOW(), ?, ?, ?, ?)");
                $result = $stmt->execute([
                    $player_id, 
                    $_SESSION['professional_id'], 
                    $record_type, 
                    $description, 
                    $diagnosis, 
                    $treatment, 
                    $status
                ]);
                
                echo json_encode(['success' => $result, 'record_id' => $pdo->lastInsertId()]);
                exit();
                
            case 'update_medical_record':
                $record_id = (int)$_POST['record_id'];
                $record_type = htmlspecialchars($_POST['record_type']);
                $description = htmlspecialchars($_POST['description']);
                $diagnosis = htmlspecialchars($_POST['diagnosis']);
                $treatment = htmlspecialchars($_POST['treatment']);
                $status = htmlspecialchars($_POST['status']);
                
                $stmt = $pdo->prepare("UPDATE medical_records SET 
                                     record_type = ?, description = ?, diagnosis = ?, treatment = ?, status = ?, updated_at = NOW() 
                                     WHERE id = ?");
                $result = $stmt->execute([$record_type, $description, $diagnosis, $treatment, $status, $record_id]);
                
                echo json_encode(['success' => $result]);
                exit();
                
            case 'get_players':
                $filter = isset($_POST['filter']) ? $_POST['filter'] : 'all';
                $query = "SELECT pr.id, pr.first_name, pr.last_name, pr.birth_day, pr.birth_month, pr.birth_year, pr.user_id 
                         FROM player_registrations pr
                         WHERE pr.registration_status = 'approved'";
                
                if ($filter === 'active') {
                    $query .= " AND EXISTS (SELECT 1 FROM medical_records mr WHERE mr.player_id = pr.id AND mr.status = 'active')";
                } elseif ($filter === 'resolved') {
                    $query .= " AND NOT EXISTS (SELECT 1 FROM medical_records mr WHERE mr.player_id = pr.id AND mr.status = 'active')";
                }
                
                $stmt = $pdo->prepare($query);
                $stmt->execute();
                $players = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($players as &$player) {
                    $player['age'] = calculateAge($player['birth_day'], $player['birth_month'], $player['birth_year']);
                    unset($player['birth_day'], $player['birth_month'], $player['birth_year']);
                }
                
                echo json_encode($players);
                exit();
                
            case 'get_record_details':
                $record_id = (int)$_POST['record_id'];
                $stmt = $pdo->prepare("SELECT mr.*, pr.first_name, pr.last_name 
                                     FROM medical_records mr
                                     JOIN player_registrations pr ON mr.player_id = pr.id
                                     WHERE mr.id = ?");
                $stmt->execute([$record_id]);
                $record = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode($record);
                exit();
                
            case 'get_recent_records':
                $stmt = $pdo->prepare("SELECT mr.*, pr.first_name, pr.last_name 
                                      FROM medical_records mr
                                      JOIN player_registrations pr ON mr.player_id = pr.id
                                      ORDER BY mr.record_date DESC LIMIT 5");
                $stmt->execute();
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($records);
                exit();
                
            case 'send_message':
                $recipient_id = (int)$_POST['recipient_id'];
                $message = htmlspecialchars($_POST['message']);
                $sender_id = $_SESSION['professional_id'];
                $sender_type = 'medical';
                
                $stmt = $pdo->prepare("INSERT INTO messages 
                                     (sender_id, sender_type, recipient_id, recipient_type, message, sent_at, is_read) 
                                     VALUES (?, ?, ?, ?, ?, NOW(), 0)");
                $result = $stmt->execute([
                    $sender_id,
                    $sender_type,
                    $recipient_id,
                    'player',
                    $message
                ]);
                
                echo json_encode(['success' => $result]);
                exit();
                
            case 'get_messages':
                $player_id = (int)$_POST['player_id'];
                $professional_id = $_SESSION['professional_id'];
                
                // Mark messages from this player as read upon fetching them
                $updateStmt = $pdo->prepare("UPDATE messages SET is_read = 1 
                                             WHERE recipient_id = ? AND recipient_type = 'medical' 
                                             AND sender_id = ? AND sender_type = 'player' AND is_read = 0");
                $updateStmt->execute([$professional_id, $player_id]);

                // Fetch the conversation
                $stmt = $pdo->prepare("SELECT m.*, 
                                      CASE 
                                        WHEN m.sender_type = 'medical' THEN ms.first_name
                                        ELSE pr.first_name
                                      END as sender_name,
                                      CASE 
                                        WHEN m.sender_type = 'medical' THEN ms.last_name
                                        ELSE pr.last_name
                                      END as sender_last_name
                                      FROM messages m
                                      LEFT JOIN medical_staff ms ON m.sender_id = ms.id AND m.sender_type = 'medical'
                                      LEFT JOIN player_registrations pr ON m.sender_id = pr.id AND m.sender_type = 'player'
                                      WHERE (m.sender_id = ? AND m.sender_type = 'medical' AND m.recipient_id = ? AND m.recipient_type = 'player')
                                      OR (m.sender_id = ? AND m.sender_type = 'player' AND m.recipient_id = ? AND m.recipient_type = 'medical')
                                      ORDER BY m.sent_at ASC");
                $stmt->execute([$professional_id, $player_id, $player_id, $professional_id]);
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($messages);
                exit();

            case 'get_conversations':
                $professional_id = $_SESSION['professional_id'];
                
                // Get distinct player IDs this professional has communicated with
                $stmt1 = $pdo->prepare("
                    SELECT DISTINCT
                        CASE
                            WHEN sender_id = :prof_id AND sender_type = 'medical' THEN recipient_id
                            ELSE sender_id
                        END AS player_id
                    FROM messages
                    WHERE (sender_id = :prof_id AND sender_type = 'medical') 
                       OR (recipient_id = :prof_id AND recipient_type = 'medical')
                ");
                $stmt1->execute(['prof_id' => $professional_id]);
                $player_ids = $stmt1->fetchAll(PDO::FETCH_COLUMN);

                if (empty($player_ids)) {
                    echo json_encode([]);
                    exit();
                }

                $conversations = [];
                // Prepare a statement to fetch details for each conversation
                $stmt2 = $pdo->prepare("
                    SELECT
                        pr.id as player_id,
                        pr.first_name,
                        pr.last_name,
                        (SELECT message FROM messages WHERE (sender_id = :player_id AND recipient_id = :prof_id) OR (sender_id = :prof_id AND recipient_id = :player_id) ORDER BY sent_at DESC LIMIT 1) as last_message,
                        (SELECT sent_at FROM messages WHERE (sender_id = :player_id AND recipient_id = :prof_id) OR (sender_id = :prof_id AND recipient_id = :player_id) ORDER BY sent_at DESC LIMIT 1) as last_message_time,
                        (SELECT COUNT(*) FROM messages WHERE recipient_id = :prof_id AND recipient_type = 'medical' AND sender_id = :player_id AND is_read = 0) as unread_count
                    FROM player_registrations pr
                    WHERE pr.id = :player_id
                ");

                foreach ($player_ids as $player_id) {
                    $stmt2->execute(['player_id' => $player_id, 'prof_id' => $professional_id]);
                    $convo = $stmt2->fetch(PDO::FETCH_ASSOC);
                    if ($convo) {
                        $conversations[] = $convo;
                    }
                }

                // Sort conversations by last message time descending
                usort($conversations, function($a, $b) {
                    return strtotime($b['last_message_time']) - strtotime($a['last_message_time']);
                });

                echo json_encode($conversations);
                exit();
                
            case 'get_unread_count':
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM messages 
                                      WHERE recipient_id = ? AND recipient_type = 'medical' AND is_read = 0");
                $stmt->execute([$_SESSION['professional_id']]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['count' => $result['count'] ?? 0]);
                exit();
        }
    } catch (PDOException $e) {
        error_log('AJAX Error: ' . $e->getMessage());
        echo json_encode(['error' => 'A server error occurred. Please try again later.']);
        exit();
    }
}

// Helper function to calculate age
function calculateAge($day, $month, $year) {
    if (!$day || !$month || !$year) return 'N/A';
    try {
        $birthDate = date_create("$year-$month-$day");
        $today = date_create("today");
        $diff = date_diff($birthDate, $today);
        return $diff->format('%y');
    } catch (Exception $e) {
        return 'N/A';
    }
}

// Get medical dashboard statistics
function getMedicalStats() {
    $pdo = getPDO();
    $stats = [
        'total_records' => 0,
        'active_issues' => 0,
        'recent_records' => [],
        'unread_messages' => 0
    ];
    
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM medical_records");
        $stats['total_records'] = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM medical_records WHERE status = 'active'");
        $stats['active_issues'] = $stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT mr.*, pr.first_name, pr.last_name 
                            FROM medical_records mr
                            JOIN player_registrations pr ON mr.player_id = pr.id
                            ORDER BY mr.record_date DESC LIMIT 5");
        $stats['recent_records'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (isset($_SESSION['professional_id'])) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM messages 
                                  WHERE recipient_id = ? AND recipient_type = 'medical' AND is_read = 0");
            $stmt->execute([$_SESSION['professional_id']]);
            $stats['unread_messages'] = $stmt->fetchColumn();
        }
        
    } catch (PDOException $e) {
        $stats['error'] = $e->getMessage();
    }
    
    return $stats;
}

$stats = getMedicalStats();
$activeSection = isset($_GET['section']) ? htmlspecialchars($_GET['section']) : 'dashboard';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Dashboard | Ethiopian Online Football Scouting</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        
        /* Sidebar Styles */
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
        
        .notification-badge {
            position: absolute;
            top: 5px;
            right: 15px;
            background-color: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }
        
        /* Main Content Styles */
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
        
        /* Header Styles */
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

        /* Dashboard cards */
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
            flex-direction: column;
            gap: 10px;
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

        .dashboard-card.with-icon {
             display: flex;
             align-items: center;
             gap: 20px;
        }

        .dashboard-card .icon {
            font-size: 2.5rem;
            padding: 15px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dashboard-card .icon.total { background-color: #eaf2fa; color: #3498db; }
        .dashboard-card .icon.active { background-color: #fbe9e7; color: #e74c3c; }
        .dashboard-card .icon.recent { background-color: #eafaf1; color: #2ecc71; }
        .dashboard-card .icon.messages { background-color: #f5e8fa; color: #9b59b6; }

        .dashboard-card .card-content .value {
            font-size: 2rem;
            font-weight: 700;
        }

        .dashboard-card .card-content .label {
            font-size: 1rem;
            color: var(--gray);
        }
        .dashboard-card .card-body { padding: 0; width: 100%; }

        /* Data Tables */
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
        
        .status.active { background-color: #f8d7da; color: #721c24; }
        .status.ongoing { background-color: #fff3cd; color: #856404; }
        .status.resolved { background-color: #d4edda; color: #155724; }
        
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
        .btn-success { background-color: var(--secondary); color: white; }
        .btn-warning { background-color: var(--warning); color: white; }

        /* Medical Record Card */
        .record-card {
            background-color: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary);
        }
        
        body.dark-mode .record-card {
            background-color: var(--dark-mode-card);
        }
        
        .record-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .record-type {
            font-weight: 600;
            color: var(--primary);
            text-transform: capitalize;
        }
        
        .record-date {
            color: var(--gray);
            font-size: 0.9rem;
        }
        
        .record-footer {
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background-color: white;
            border-radius: 10px;
            width: 500px;
            max-width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        body.dark-mode .modal-content {
            background-color: var(--dark-mode-card);
            color: var(--dark-mode-text);
        }
        
        .modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        body.dark-mode .modal-header {
            border-bottom-color: #444;
        }
        
        .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: inherit;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        body.dark-mode .modal-footer {
            border-top-color: #444;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #f5f5f5;
            color: #333;
        }
        
        body.dark-mode .form-group input,
        body.dark-mode .form-group select,
        body.dark-mode .form-group textarea {
            background-color: var(--dark-mode-bg);
            border-color: #444;
            color: var(--dark-mode-text);
        }
        
        .form-group textarea {
            min-height: 100px;
        }

        /* Filter Controls */
        .filter-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .filter-btn {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            background-color: #e0e0e0;
        }
        
        body.dark-mode .filter-btn {
            background-color: #444;
        }
        
        .filter-btn.active {
            background-color: var(--primary);
            color: white;
        }

        /* Message Modal Styles */
        .message-modal {
            width: 400px;
        }
        
        .message-container {
            height: 300px;
            overflow-y: auto;
            margin-bottom: 15px;
            border: 1px solid #eee;
            padding: 10px;
            border-radius: 5px;
            display: flex;
            flex-direction: column;
        }
        
        body.dark-mode .message-container {
            border-color: #444;
        }
        
        .message {
            margin-bottom: 10px;
            padding: 8px 12px;
            border-radius: 10px;
            max-width: 80%;
            line-height: 1.4;
        }
        
        .message.sent {
            background-color: #e3f2fd;
            align-self: flex-end;
        }
        
        body.dark-mode .message.sent {
            background-color: #1e3a8a;
        }
        
        .message.received {
            background-color: #f1f1f1;
            align-self: flex-start;
        }
        
        body.dark-mode .message.received {
            background-color: #333;
        }
        
        .message-sender {
            font-weight: bold;
            font-size: 0.8rem;
            margin-bottom: 3px;
        }
        
        .message-time {
            font-size: 0.75rem;
            color: #777;
            text-align: right;
            margin-top: 4px;
        }
        
        body.dark-mode .message-time {
            color: #aaa;
        }
        
        .message-input {
            display: flex;
            gap: 10px;
        }
        
        .message-input textarea {
            flex: 1;
            min-height: 40px;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            resize: vertical;
        }
        
        body.dark-mode .message-input textarea {
            border-color: #444;
            background-color: var(--dark-mode-bg);
            color: var(--dark-mode-text);
        }
        
        .message-input button {
            align-self: flex-end;
            height: 40px;
        }
        
        /* Section Styles */
        .content-section {
            display: none;
        }
        
        .content-section.active {
            display: block;
        }

        /* Notification Dropdown */
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

        body.dark-mode .notification-time {
            color: #aaa;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                overflow: hidden;
            }
            
            .sidebar-header h3, .menu-item span {
                display: none;
            }
            
            .menu-item {
                justify-content: center;
            }
            
            .menu-item i {
                margin-right: 0;
                font-size: 1.2rem;
            }
            
            .main-content {
                margin-left: 70px;
                width: calc(100% - 70px);
            }
            
            .search-bar {
                display: none;
            }
            
            .dashboard-grid {
                 grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar Navigation -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3>Medical System</h3>
            <i class="fas fa-bars" id="sidebarToggle"></i>
        </div>
        <div class="sidebar-menu">
            <div class="menu-item <?php echo $activeSection === 'dashboard' ? 'active' : ''; ?>" data-section="dashboard">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </div>
            <div class="menu-item <?php echo $activeSection === 'players' ? 'active' : ''; ?>" data-section="players">
                <i class="fas fa-users"></i>
                <span>Player List</span>
            </div>
            <div class="menu-item <?php echo $activeSection === 'appointments' ? 'active' : ''; ?>" data-section="appointments">
                <i class="fas fa-calendar-check"></i>
                <span>Appointments</span>
            </div>
            <div class="menu-item <?php echo $activeSection === 'reports' ? 'active' : ''; ?>" data-section="reports">
                <i class="fas fa-user-injured"></i>
                <span>Injury Reports</span>
            </div>
            <div class="menu-item <?php echo $activeSection === 'messages' ? 'active' : ''; ?>" data-section="messages" id="messagesMenuItem">
                <i class="fas fa-envelope"></i>
                <span>Messages</span>
                <?php if (isset($stats['unread_messages']) && $stats['unread_messages'] > 0): ?>
                <span class="notification-badge" id="globalUnreadBadge"><?php echo $stats['unread_messages']; ?></span>
                <?php endif; ?>
            </div>
            <div class="menu-item" data-section="logout">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </div>
        </div>
    </div>
    
    <!-- Main Content Area -->
    <div class="main-content" id="mainContent">
        <!-- Header with Search and User Profile -->
        <div class="header">
            <div>
                <h2 style="margin-bottom: 5px;">Ethiopian online football scouting</h2>
                <p style="color: var(--gray);">Medical Dashboard</p>
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
                    <div class="user-avatar"><?php echo isset($_SESSION['professional_name']) ? substr($_SESSION['professional_name'], 0, 1) : 'M'; ?></div>
                    <span><?php echo isset($_SESSION['professional_name']) ? htmlspecialchars($_SESSION['professional_name']) : 'Medical User'; ?></span>
                </div>
            </div>
        </div>
        
        <!-- Dashboard Content Section -->
        <section class="content-section <?php echo $activeSection === 'dashboard' ? 'active' : ''; ?>" id="dashboardSection">
            <h2 style="margin-bottom: 20px;">Medical Dashboard Overview</h2>
            
            <section class="cards">
                <div class="dashboard-card with-icon">
                    <div class="icon total"><i class="fas fa-file-medical"></i></div>
                    <div class="card-content">
                        <div class="value"><?php echo $stats['total_records']; ?></div>
                        <div class="label">Total Medical Records</div>
                    </div>
                </div>
                <div class="dashboard-card with-icon">
                    <div class="icon active"><i class="fas fa-user-injured"></i></div>
                    <div class="card-content">
                        <div class="value"><?php echo $stats['active_issues']; ?></div>
                        <div class="label">Active Medical Issues</div>
                    </div>
                </div>
                <div class="dashboard-card with-icon">
                    <div class="icon recent"><i class="fas fa-calendar-check"></i></div>
                    <div class="card-content">
                        <div class="value" id="dashboardRecentCount"><?php echo count($stats['recent_records']); ?></div>
                        <div class="label">Recent Records (Last 5)</div>
                    </div>
                </div>
                <div class="dashboard-card with-icon">
                    <div class="icon messages"><i class="fas fa-envelope"></i></div>
                    <div class="card-content">
                        <div class="value" id="dashboardUnreadCount"><?php echo $stats['unread_messages']; ?></div>
                        <div class="label">Unread Messages</div>
                    </div>
                </div>
            </section>
            
            <div class="dashboard-grid" style="margin-top: 20px;">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-file-medical"></i> Recent Medical Records</h3>
                    </div>
                    <div class="card-body">
                        <div id="recentRecordsList">
                            <?php if (empty($stats['recent_records'])): ?>
                                <p>No recent records.</p>
                            <?php else: ?>
                                <?php foreach ($stats['recent_records'] as $record): ?>
                                <div class="record-card">
                                    <div class="record-header">
                                        <span class="record-type"><?php echo $record['record_type']; ?></span>
                                        <span class="record-date"><?php echo date('M j, Y', strtotime($record['record_date'])); ?></span>
                                    </div>
                                    <div class="record-content">
                                        <p><strong>Player:</strong> <?php echo $record['first_name'] . ' ' . $record['last_name']; ?></p>
                                        <p><strong>Diagnosis:</strong> <?php echo htmlspecialchars($record['diagnosis'] ?: 'N/A'); ?></p>
                                    </div>
                                    <div class="record-footer">
                                        <span class="status <?php echo $record['status']; ?>"><?php echo $record['status']; ?></span>
                                        <div>
                                            <button class="action-btn btn-primary" onclick="viewRecordDetails(<?php echo $record['id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <button class="action-btn btn-secondary" onclick="openMessageModal(<?php echo $record['player_id']; ?>, '<?php echo addslashes($record['first_name'] . ' ' . $record['last_name']); ?>')">
                                                <i class="fas fa-envelope"></i> Message
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                             <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        
        <!-- Players List Section -->
        <section class="content-section <?php echo $activeSection === 'players' ? 'active' : ''; ?>" id="playersSection">
            <h2 style="margin-bottom: 20px;">Player List</h2>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <div class="filter-controls">
                        <button class="filter-btn active" onclick="filterPlayers('all', this)">All Players</button>
                        <button class="filter-btn" onclick="filterPlayers('active', this)">Active Issues</button>
                        <button class="filter-btn" onclick="filterPlayers('resolved', this)">Resolved Issues</button>
                    </div>
                </div>
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Player</th>
                                <th>Age</th>
                                <th>Last Record Date</th>
                                <th>Last Record Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="playersList">
                            <!-- Loaded via AJAX -->
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Appointments Section -->
        <section class="content-section <?php echo $activeSection === 'appointments' ? 'active' : ''; ?>" id="appointmentsSection">
            <h2 style="margin-bottom: 20px;">Appointments</h2>
            <div class="dashboard-card"><p>This section is under construction.</p></div>
        </section>

        <!-- Injury Reports Section -->
        <section class="content-section <?php echo $activeSection === 'reports' ? 'active' : ''; ?>" id="reportsSection">
            <h2 style="margin-bottom: 20px;">Injury Reports</h2>
            <div class="dashboard-card"><p>This section is under construction.</p></div>
        </section>
        
        <!-- Messages Section -->
        <section class="content-section <?php echo $activeSection === 'messages' ? 'active' : ''; ?>" id="messagesSection">
            <h2 style="margin-bottom: 20px;">Conversations</h2>
            <div class="dashboard-card">
                <div class="card-body" id="conversationsList">
                    <!-- Conversations will be loaded via AJAX -->
                </div>
            </div>
        </section>
        
        <!-- All Modals -->
        <!-- View Record Modal -->
        <div class="modal" id="viewRecordModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Medical Record Details</h5>
                    <button type="button" class="modal-close" onclick="closeModal('viewRecordModal')">&times;</button>
                </div>
                <div class="modal-body" id="recordDetailsBody">
                    <!-- Content loaded via AJAX -->
                </div>
                <div class="modal-footer" id="recordDetailsFooter">
                     <!-- Buttons loaded via AJAX -->
                </div>
            </div>
        </div>
        
        <!-- Add/Edit Record Modal -->
        <div class="modal" id="editRecordModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editRecordModalTitle">Add Medical Record</h5>
                    <button type="button" class="modal-close" onclick="closeModal('editRecordModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="medicalRecordForm">
                        <input type="hidden" id="recordId">
                        <input type="hidden" id="playerId">
                        <div class="form-group">
                            <label for="recordType">Record Type</label>
                            <select id="recordType" class="form-control" required>
                                <option value="">Select type</option>
                                <option value="injury">Injury</option>
                                <option value="illness">Illness</option>
                                <option value="checkup">Checkup</option>
                                <option value="vaccination">Vaccination</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" class="form-control" required></textarea>
                        </div>
                        <div class="form-group">
                            <label for="diagnosis">Diagnosis</label>
                            <textarea id="diagnosis" class="form-control" required></textarea>
                        </div>
                        <div class="form-group">
                            <label for="treatment">Treatment</label>
                            <textarea id="treatment" class="form-control" required></textarea>
                        </div>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" class="form-control" required>
                                <option value="active">Active</option>
                                <option value="ongoing">Ongoing</option>
                                <option value="resolved">Resolved</option>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="action-btn btn-secondary" onclick="closeModal('editRecordModal')">Cancel</button>
                    <button type="button" class="action-btn btn-primary" id="saveRecordBtn">Save Record</button>
                </div>
            </div>
        </div>

        <!-- Message Modal -->
        <div class="modal" id="messageModal">
            <div class="modal-content message-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="messageModalTitle">Message Player</h5>
                    <button type="button" class="modal-close" onclick="closeModal('messageModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="message-container" id="messageContainer">
                        <!-- Messages will be loaded here -->
                    </div>
                    <div class="message-input">
                        <textarea id="messageText" placeholder="Type your message here..."></textarea>
                        <button class="action-btn btn-primary" id="sendMessageBtn"><i class="fas fa-paper-plane"></i></button>
                    </div>
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
    </div>

    <script>
        let currentFilter = 'all';
        let currentPlayerId = null;
        let currentPlayerName = '';
        
        document.addEventListener('DOMContentLoaded', function() {
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
                    
                    const sectionToShow = document.getElementById(this.dataset.section + 'Section');
                    if (sectionToShow) sectionToShow.classList.add('active');

                    if (this.dataset.section === 'messages') {
                        loadConversations();
                    } else if (this.dataset.section === 'players') {
                        loadPlayers(currentFilter);
                    }
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
            
            // Determine active section from URL and load content
            const activeSectionId = document.querySelector('.content-section.active')?.id;
            if (activeSectionId === 'playersSection') {
                loadPlayers(currentFilter);
            } else if (activeSectionId === 'messagesSection') {
                loadConversations();
            }

            // Setup event listeners
            document.getElementById('saveRecordBtn').addEventListener('click', saveMedicalRecord);
            document.getElementById('sendMessageBtn').addEventListener('click', sendMessage);
        });

        function navigateTo(section, element) {
            document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
            document.getElementById(section + 'Section').classList.add('active');
            
            document.querySelectorAll('.menu-item').forEach(item => item.classList.remove('active'));
            element.classList.add('active');
            
            history.pushState(null, '', '?section=' + section);
            
            if (section === 'players') loadPlayers(currentFilter);
            if (section === 'messages') loadConversations();
        }
        
        function filterPlayers(filter, element) {
            currentFilter = filter;
            document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
            element.classList.add('active');
            loadPlayers(filter);
        }
        
        function loadPlayers(filter) {
            const tableBody = document.getElementById('playersList');
            tableBody.innerHTML = '<tr><td colspan="5" style="text-align: center;">Loading...</td></tr>';
            
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get_players&filter=${filter}`
            })
            .then(response => response.json())
            .then(players => {
                tableBody.innerHTML = '';
                if (players.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No players found for this filter.</td></tr>';
                    return;
                }
                players.forEach(player => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${player.first_name} ${player.last_name}</td>
                        <td>${player.age}</td>
                        <td data-player-id="${player.id}">Loading...</td>
                        <td data-player-id="${player.id}">Loading...</td>
                        <td>
                            <button class="action-btn btn-primary" onclick="viewPlayerRecords(${player.id}, '${escapeSingleQuotes(player.first_name)} ${escapeSingleQuotes(player.last_name)}')"><i class="fas fa-file-medical"></i> View</button>
                            <button class="action-btn btn-success" onclick="openAddRecordModal(${player.id}, '${escapeSingleQuotes(player.first_name)} ${escapeSingleQuotes(player.last_name)}')"><i class="fas fa-plus"></i> Add</button>
                            <button class="action-btn btn-secondary" onclick="openMessageToPlayerModal(${player.user_id}, '${escapeSingleQuotes(player.first_name)} ${escapeSingleQuotes(player.last_name)}')"><i class="fas fa-envelope"></i> Message</button>
                        </td>
                    `;
                    tableBody.appendChild(row);
                    loadPlayerLatestRecord(player.id);
                });
            })
            .catch(error => {
                console.error('Error loading players:', error);
                tableBody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: red;">Error loading data.</td></tr>';
            });
        }

        function loadPlayerLatestRecord(playerId) {
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get_medical_records&player_id=${playerId}`
            })
            .then(response => response.json())
            .then(records => {
                const dateCell = document.querySelector(`td[data-player-id='${playerId}']:nth-child(3)`);
                const statusCell = document.querySelector(`td[data-player-id='${playerId}']:nth-child(4)`);
                if (records.length > 0) {
                    const latest = records[0];
                    dateCell.textContent = new Date(latest.record_date).toLocaleDateString();
                    statusCell.innerHTML = `<span class="status ${latest.status}">${latest.status}</span>`;
                } else {
                    dateCell.textContent = 'N/A';
                    statusCell.textContent = 'No Records';
                }
            });
        }
        
        function viewRecordDetails(recordId) {
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get_record_details&record_id=${recordId}`
            })
            .then(response => response.json())
            .then(record => {
                if (record) {
                    document.getElementById('recordDetailsBody').innerHTML = `
                        <p><strong>Player:</strong> ${record.first_name} ${record.last_name}</p>
                        <p><strong>Date:</strong> ${new Date(record.record_date).toLocaleDateString()}</p>
                        <p><strong>Type:</strong> ${record.record_type}</p>
                        <p><strong>Description:</strong> ${record.description.replace(/\n/g, '<br>')}</p>
                        <p><strong>Diagnosis:</strong> ${record.diagnosis.replace(/\n/g, '<br>')}</p>
                        <p><strong>Treatment:</strong> ${record.treatment.replace(/\n/g, '<br>')}</p>
                        <p><strong>Status:</strong> <span class="status ${record.status}">${record.status}</span></p>
                    `;
                    
                    document.getElementById('recordDetailsFooter').innerHTML = `
                        <button class="action-btn btn-secondary" onclick="openEditRecordModal(${record.id})"><i class="fas fa-edit"></i> Edit</button>
                        <button class="action-btn btn-primary" onclick="openMessageModal(${record.player_id}, '${escapeSingleQuotes(record.first_name)} ${escapeSingleQuotes(record.last_name)}')"><i class="fas fa-envelope"></i> Message</button>
                    `;
                    
                    document.getElementById('viewRecordModal').classList.add('show');
                } else {
                    alert('Record not found');
                }
            })
            .catch(error => {
                console.error('Error loading record details:', error);
                alert('Error loading record details');
            });
        }
        
        function viewPlayerRecords(playerId, playerName) {
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get_medical_records&player_id=${playerId}`
            })
            .then(response => response.json())
            .then(records => {
                document.getElementById('recordDetailsBody').innerHTML = `
                    <h4>Medical Records for ${playerName}</h4>
                    ${records.length === 0 ? '<p>No medical records found for this player.</p>' : ''}
                    ${records.map(record => `
                        <div class="record-card">
                            <div class="record-header">
                                <span class="record-type">${record.record_type}</span>
                                <span class="record-date">${new Date(record.record_date).toLocaleDateString()}</span>
                            </div>
                            <div class="record-content">
                                <p><strong>Description:</strong> ${record.description.replace(/\n/g, '<br>')}</p>
                                <p><strong>Diagnosis:</strong> ${record.diagnosis.replace(/\n/g, '<br>')}</p>
                                <p><strong>Treatment:</strong> ${record.treatment.replace(/\n/g, '<br>')}</p>
                            </div>
                            <div class="record-footer">
                                <span class="status ${record.status}">${record.status}</span>
                                <div>
                                    <button class="action-btn btn-primary" onclick="viewRecordDetails(${record.id})"><i class="fas fa-eye"></i> View</button>
                                    <button class="action-btn btn-secondary" onclick="openEditRecordModal(${record.id})"><i class="fas fa-edit"></i> Edit</button>
                                </div>
                            </div>
                        </div>
                    `).join('')}
                `;
                
                document.getElementById('recordDetailsFooter').innerHTML = `
                    <button class="action-btn btn-secondary" onclick="closeModal('viewRecordModal')">Close</button>
                    <button class="action-btn btn-primary" onclick="openAddRecordModal(${playerId}, '${escapeSingleQuotes(playerName)}')"><i class="fas fa-plus"></i> Add New Record</button>
                `;
                
                document.getElementById('viewRecordModal').classList.add('show');
            })
            .catch(error => {
                console.error('Error loading player records:', error);
                alert('Error loading player records');
            });
        }
        
        function openAddRecordModal(playerId, playerName) {
            document.getElementById('editRecordModalTitle').textContent = `Add Medical Record for ${playerName}`;
            document.getElementById('playerId').value = playerId;
            document.getElementById('recordId').value = '';
            document.getElementById('medicalRecordForm').reset();
            document.getElementById('editRecordModal').classList.add('show');
        }
        
        function openEditRecordModal(recordId) {
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get_record_details&record_id=${recordId}`
            })
            .then(response => response.json())
            .then(record => {
                if (record) {
                    document.getElementById('editRecordModalTitle').textContent = `Edit Medical Record for ${record.first_name} ${record.last_name}`;
                    document.getElementById('recordId').value = record.id;
                    document.getElementById('playerId').value = record.player_id;
                    document.getElementById('recordType').value = record.record_type;
                    document.getElementById('description').value = record.description;
                    document.getElementById('diagnosis').value = record.diagnosis;
                    document.getElementById('treatment').value = record.treatment;
                    document.getElementById('status').value = record.status;
                    
                    document.getElementById('editRecordModal').classList.add('show');
                } else {
                    alert('Record not found');
                }
            })
            .catch(error => {
                console.error('Error loading record for edit:', error);
                alert('Error loading record for edit');
            });
        }
        
        function saveMedicalRecord() {
            const recordId = document.getElementById('recordId').value;
            const playerId = document.getElementById('playerId').value;
            const recordType = document.getElementById('recordType').value;
            const description = document.getElementById('description').value;
            const diagnosis = document.getElementById('diagnosis').value;
            const treatment = document.getElementById('treatment').value;
            const status = document.getElementById('status').value;
            
            if (!recordType || !description || !diagnosis || !treatment || !status) {
                alert('Please fill all required fields');
                return;
            }
            
            const action = recordId ? 'update_medical_record' : 'add_medical_record';
            const body = recordId ? 
                `action=${action}&record_id=${recordId}&record_type=${encodeURIComponent(recordType)}&description=${encodeURIComponent(description)}&diagnosis=${encodeURIComponent(diagnosis)}&treatment=${encodeURIComponent(treatment)}&status=${encodeURIComponent(status)}` :
                `action=${action}&player_id=${playerId}&record_type=${encodeURIComponent(recordType)}&description=${encodeURIComponent(description)}&diagnosis=${encodeURIComponent(diagnosis)}&treatment=${encodeURIComponent(treatment)}&status=${encodeURIComponent(status)}`;
            
            document.getElementById('saveRecordBtn').disabled = true;
            document.getElementById('saveRecordBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('Record saved successfully');
                    closeModal('editRecordModal');
                    if (document.getElementById('viewRecordModal').classList.contains('show')) {
                        closeModal('viewRecordModal');
                    }
                    loadPlayers(currentFilter);
                } else {
                    alert('Error saving record');
                }
            })
            .catch(error => {
                console.error('Error saving record:', error);
                alert('Error saving record');
            })
            .finally(() => {
                document.getElementById('saveRecordBtn').disabled = false;
                document.getElementById('saveRecordBtn').innerHTML = 'Save Record';
            });
        }
        
        function loadConversations() {
            const container = document.getElementById('conversationsList');
            container.innerHTML = '<p>Loading conversations...</p>';
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_conversations'
            })
            .then(response => response.json())
            .then(conversations => {
                if (conversations.error) {
                    container.innerHTML = `<p style="color: red;">${conversations.error}</p>`;
                    return;
                }
                if (conversations.length === 0) {
                    container.innerHTML = '<p>No conversations found.</p>';
                    return;
                }
                
                let html = '<table class="data-table"><thead><tr><th>Player</th><th>Last Message</th><th>Date</th><th></th></tr></thead><tbody>';
                conversations.forEach(convo => {
                    const unreadBadge = convo.unread_count > 0 ? ` <span class="notification-badge">${convo.unread_count}</span>` : '';
                    html += `
                        <tr style="cursor: pointer;" onclick="openMessageModal(${convo.player_id}, '${escapeSingleQuotes(convo.first_name)} ${escapeSingleQuotes(convo.last_name)}')">
                            <td>${convo.first_name} ${convo.last_name}${unreadBadge}</td>
                            <td><em>${convo.last_message ? convo.last_message.substring(0, 40) + '...' : 'No messages yet'}</em></td>
                            <td>${convo.last_message_time ? new Date(convo.last_message_time).toLocaleString() : 'N/A'}</td>
                            <td><button class="action-btn btn-primary"><i class="fas fa-reply"></i> Open</button></td>
                        </tr>`;
                });
                html += '</tbody></table>';
                container.innerHTML = html;
            })
            .catch(error => {
                 console.error('Error loading conversations:', error);
                 container.innerHTML = '<p style="color: red;">Error loading conversations.</p>';
            });
        }
        
        function openMessageModal(playerId, playerName) {
            currentPlayerId = playerId;
            currentPlayerName = playerName;
            
            document.getElementById('messageModalTitle').textContent = `Message with ${playerName}`;
            document.getElementById('messageContainer').innerHTML = '<p>Loading messages...</p>';
            document.getElementById('messageText').value = '';
            
            loadMessages();
            document.getElementById('messageModal').classList.add('show');
        }
        
        // [NEW] Function to open message modal for a specific player
        function openMessageToPlayerModal(recipientId, recipientName) {
            currentPlayerId = recipientId;
            currentPlayerName = recipientName;
            
            document.getElementById('messageModalTitle').textContent = `Message to ${recipientName}`;
            document.getElementById('messageContainer').innerHTML = '<p>Loading messages...</p>';
            document.getElementById('messageText').value = '';
            
            loadMessages();
            document.getElementById('messageModal').classList.add('show');
        }
        
        function loadMessages() {
            if (!currentPlayerId) return;

            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get_messages&player_id=${currentPlayerId}`
            })
            .then(response => response.json())
            .then(messages => {
                const container = document.getElementById('messageContainer');
                container.innerHTML = '';
                
                if (messages.length === 0) {
                    container.innerHTML = '<p style="text-align: center; color: #999;">No messages yet. Start the conversation!</p>';
                } else {
                    messages.forEach(msg => {
                        const isSent = msg.sender_type === 'medical';
                        const msgDiv = document.createElement('div');
                        msgDiv.className = `message ${isSent ? 'sent' : 'received'}`;
                        msgDiv.innerHTML = `
                            <div class="message-sender">${msg.sender_name} ${msg.sender_last_name}</div>
                            <div class="message-content">${msg.message.replace(/\n/g, '<br>')}</div>
                            <div class="message-time">${new Date(msg.sent_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</div>
                        `;
                        container.appendChild(msgDiv);
                    });
                }
                container.scrollTop = container.scrollHeight;
                
                // Since messages are now read, update counts
                updateMessagesCount();
                if (document.getElementById('messagesSection').classList.contains('active')) {
                    loadConversations(); // Refresh conversation list to remove unread badge
                }
            })
            .catch(error => {
                console.error('Error loading messages:', error);
                document.getElementById('messageContainer').innerHTML = '<p style="color: red;">Error loading messages.</p>';
            });
        }
        
        function sendMessage() {
            const messageText = document.getElementById('messageText').value.trim();
            if (!messageText || !currentPlayerId) return;
            
            document.getElementById('sendMessageBtn').disabled = true;

            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=send_message&recipient_id=${currentPlayerId}&message=${encodeURIComponent(messageText)}`
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    document.getElementById('messageText').value = '';
                    loadMessages(); // Reload messages to show the new one
                } else {
                    alert('Error sending message.');
                }
            })
            .catch(error => console.error('Error sending message:', error))
            .finally(() => {
                document.getElementById('sendMessageBtn').disabled = false;
            });
        }
        
        function updateMessagesCount() {
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_unread_count'
            })
            .then(response => response.json())
            .then(data => {
                const count = data.count || 0;
                document.getElementById('dashboardUnreadCount').textContent = count;
                
                const badge = document.getElementById('globalUnreadBadge');
                if (count > 0) {
                    if (badge) {
                        badge.textContent = count;
                        badge.style.display = 'flex';
                    } else {
                        const newBadge = document.createElement('span');
                        newBadge.id = 'globalUnreadBadge';
                        newBadge.className = 'notification-badge';
                        newBadge.textContent = count;
                        document.getElementById('messagesMenuItem').appendChild(newBadge);
                    }
                } else if (badge) {
                    badge.style.display = 'none';
                }
            });
        }
        
        function toggleNotifications() {
            const dropdown = document.getElementById('notificationDropdown');
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
            if (dropdown.style.display === 'block') loadNotifications();
        }
        
        function loadNotifications() {
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_notifications'
            })
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
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=mark_notification_read&notification_id=${id}`
            })
            .then(r => r.json()).then(d => { if(d.success) loadNotifications(); });
        }

        function markAllNotificationsRead() {
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_notifications'
            })
            .then(r => r.json()).then(() => loadNotifications());
        }
        
        function toggleProfileDropdown() {
            const existingDropdown = document.querySelector('.profile-dropdown');
            if (existingDropdown) {
                existingDropdown.remove();
                return;
            }
            
            const dropdown = document.createElement('div');
            dropdown.className = 'profile-dropdown';
            dropdown.style.position = 'absolute';
            dropdown.style.top = '100%';
            dropdown.style.right = '0';
            dropdown.style.backgroundColor = 'white';
            dropdown.style.borderRadius = '8px';
            dropdown.style.boxShadow = '0 5px 15px rgba(0,0,0,0.2)';
            dropdown.style.zIndex = '1000';
            dropdown.style.width = '200px';
            dropdown.style.overflow = 'hidden';
            
            dropdown.innerHTML = `
                <a href="#" style="display: block; padding: 10px 15px; color: #333; text-decoration: none; border-bottom: 1px solid #eee;" onclick="event.preventDefault(); showProfileSettings()">
                    <i class="fas fa-user" style="margin-right: 8px;"></i> Profile
                </a>
                <a href="#" style="display: block; padding: 10px 15px; color: #333; text-decoration: none;" onclick="event.preventDefault(); logout()">
                    <i class=" fas fa-sign-out-alt" style="margin-right: 8px;"></i> Logout
                    </a>
            `;
             if (document.body.classList.contains('dark-mode')) {
            dropdown.style.backgroundColor = '#16213e';
            dropdown.querySelectorAll('a').forEach(a => {
                a.style.color = '#f1f1f1';
                a.style.borderBottomColor = '#444';
            });
        }

        document.getElementById('userProfile').appendChild(dropdown);
    }

    function showProfileSettings() {
        alert('Profile settings functionality will be implemented here.');
        document.querySelector('.profile-dropdown').remove();
    }

    function logout() {
        if (confirm('Are you sure you want to logout?')) {
            window.location.href = 'logout.php';
        }
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('show');
    }

    function escapeSingleQuotes(str) {
        return str.replace(/'/g, "\\'");
    }

    // Handle message input with Enter key
    document.getElementById('messageText').addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            document.getElementById('sendMessageBtn').click();
        }
    });

    // Auto-refresh messages every 30 seconds when modal is open
    setInterval(() => {
        if (document.getElementById('messageModal').classList.contains('show')) {
            loadMessages();
        }
    }, 30000);

    // Auto-refresh unread message count every minute
    setInterval(updateMessagesCount, 60000);

    // Handle window resizing
    window.addEventListener('resize', function() {
        if (window.innerWidth < 768) {
            document.getElementById('sidebar').classList.add('collapsed');
            document.getElementById('mainContent').classList.add('expanded');
        }
    });

    // Initialize responsive behavior
    if (window.innerWidth < 768) {
        document.getElementById('sidebar').classList.add('collapsed');
        document.getElementById('mainContent').classList.add('expanded');
    }
</script>
</body> </html>