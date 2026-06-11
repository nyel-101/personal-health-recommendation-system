<?php
session_start();

// --- 1. DATABASE CONNECTION ---
$host = 'localhost'; $dbname = 'phr_management'; $user = 'root'; $pass = '';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) { die("Database Connection Failed."); }

// --- 2. GLOBAL LOGIC ---
$msg = ""; $status = "";
$page = isset($_GET['page']) ? $_GET['page'] : 'overview';
$view = isset($_GET['view']) ? $_GET['view'] : 'login';
$chat_with = isset($_GET['chat_with']) ? $_GET['chat_with'] : null;

if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit(); }

// --- 3. AUTH & REGISTRATION LOGIC ---
if (isset($_POST['register'])) {
    $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
    $v_file = time()."_" . $_FILES['v_doc']['name'];
    if(!is_dir('verifications')) mkdir('verifications', 0777, true);
    move_uploaded_file($_FILES['v_doc']['tmp_name'], "verifications/".$v_file);
    try {
        $stmt = $pdo->prepare("INSERT INTO users (fullname, email, password, role, status, v_document) VALUES (?, ?, ?, 'patient', 0, ?)");
        $stmt->execute([$_POST['fullname'], $_POST['email'], $hash, $v_file]);
        header("Location: index.php?view=login&msg=REGISTERED! WAIT FOR DR. DANIEL APPROVAL."); exit();
    } catch (Exception $e) { $msg = "Email already exists."; }
}

if (isset($_POST['login'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$_POST['email']]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'], $user['password'])) {
        if($user['role'] == 'patient' && $user['status'] == 0) {
            $msg = "ACCOUNT PENDING APPROVAL FROM DR. DANIEL.";
        } else {
            $_SESSION['user_id'] = $user['id']; $_SESSION['role'] = $user['role']; $_SESSION['fullname'] = $user['fullname'];
            header("Location: index.php"); exit();
        }
    } else { $msg = "INVALID CREDENTIALS."; }
}

// APPROVE PATIENT (Doctor Side)
if (isset($_POST['approve_user'])) {
    $pdo->prepare("UPDATE users SET status = 1 WHERE id = ?")->execute([$_POST['u_id']]);
    header("Location: index.php?page=verify&msg=Patient Approved."); exit();
}

// SYNC VITALS (Patient Side)
if (isset($_POST['save_vitals'])) {
    $img = !empty($_FILES['history_img']['name']) ? time()."_".$_FILES['history_img']['name'] : "none.png";
    if($img != "none.png") move_uploaded_file($_FILES['history_img']['tmp_name'], "uploads/".$img);
    $warn = ($_POST['sugar'] > 140) ? 1 : 0;
    $stmt = $pdo->prepare("INSERT INTO health_logs (patient_id, sugar_level, blood_pressure, heart_rate, medical_history_img, status_warning) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $_POST['sugar'], $_POST['bp'], $_POST['hr'], $img, $warn]);
    $msg = "VITAL DATA SYNCED.";
}

// MESSAGING LOGIC (Messenger Style)
if (isset($_POST['send_msg'])) {
    $stmt = $pdo->prepare("INSERT INTO doctor_advice (doctor_id, patient_id, advice_text, is_read) VALUES (?, ?, ?, 0)");
    $sender_id = $_SESSION['role'] == 'doctor' ? $_SESSION['user_id'] : 1; 
    $p_id = $_SESSION['role'] == 'patient' ? $_SESSION['user_id'] : $_POST['patient_id'];
    $prefix = $_SESSION['role'] == 'patient' ? "REPLY: " : "";
    $stmt->execute([$sender_id, $p_id, $prefix . $_POST['message']]);
    $redir = $_SESSION['role'] == 'doctor' ? "index.php?chat_with=$p_id" : "index.php";
    header("Location: $redir"); exit();
}

// NOTIFICATIONS
$notifs = [];
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] == 'doctor') {
        $stmt = $pdo->query("SELECT * FROM users WHERE status = 0 AND role = 'patient'");
        $notifs = $stmt->fetchAll();
        $notif_title = "Pending Approvals";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM doctor_advice WHERE patient_id = ? AND advice_text NOT LIKE 'REPLY:%' AND is_read = 0 ORDER BY created_at DESC");
        $stmt->execute([$_SESSION['user_id']]);
        $notifs = $stmt->fetchAll();
        $notif_title = "Dr. Daniel's Inbox";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PHR</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #00f2fe; --secondary: #4facfe; --dark: #020617; --danger: #f43f5e; --glass: rgba(15, 23, 42, 0.8); }
        
        /* Updated Body for clear background image */
        body { 
            background: linear-gradient(rgba(1, 4, 9, 0.2), rgba(1, 4, 9, 0.2)), url('assets/phr.jpg'); 
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            background-repeat: no-repeat;
            color: #fff; 
            margin: 0; 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            overflow-x: hidden; 
        }

        .glass { background: var(--glass); backdrop-filter: blur(25px); border: 1px solid rgba(255,255,255,0.1); border-radius: 30px; }
        .nav { display: flex; justify-content: space-between; align-items: center; padding: 1.2rem 8%; border-bottom: 1px solid rgba(255,255,255,0.1); background: rgba(13, 17, 23, 0.9); }
        .logo { font-weight: 800; font-size: 1.6rem; color: var(--primary); text-decoration: none; }
        .bell-box { position: relative; cursor: pointer; font-size: 1.5rem; transition: 0.3s; margin-right: 15px; }
        .bell-ring { animation: shake 2.5s infinite; color: var(--primary); }
        @keyframes shake { 0%, 100% { transform: rotate(0); } 20% { transform: rotate(15deg); } 40% { transform: rotate(-15deg); } }
        .count { position: absolute; top: -5px; right: -5px; background: var(--danger); font-size: 0.65rem; padding: 2px 6px; border-radius: 50%; font-weight: 800; border: 2px solid #010409; }
        #notifModal { display: none; position: fixed; top: 80px; right: 8%; width: 300px; background: #0f172a; border: 1px solid var(--primary); border-radius: 20px; padding: 20px; z-index: 5000; }
        .layout { display: grid; grid-template-columns: 280px 1fr; gap: 2rem; padding: 2.5rem 8%; }
        .sidebar a { display: block; padding: 15px 20px; text-decoration: none; color: #94a3b8; border-radius: 15px; margin-bottom: 8px; font-weight: 600; }
        .sidebar a:hover, .active { background: var(--primary); color: #000; box-shadow: 0 0 20px var(--primary); }
        .widget { padding: 2.5rem; margin-bottom: 2rem; }
        input, .btn { width: 100%; padding: 14px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.05); color: #fff; margin: 8px 0; outline: none; }
        .btn { background: var(--primary); color: #000; font-weight: 800; border: none; cursor: pointer; }
        .messenger-box { display: grid; grid-template-columns: 200px 1fr; height: 350px; background: rgba(0,0,0,0.3); border-radius: 20px; overflow: hidden; border: 1px solid rgba(255,255,255,0.05); }
        .inbox-list { border-right: 1px solid rgba(255,255,255,0.05); overflow-y: auto; padding: 10px; }
        .inbox-item { padding: 10px; border-radius: 10px; font-size: 0.8rem; cursor: pointer; display: block; text-decoration: none; color: #fff; margin-bottom: 5px; }
        .inbox-item:hover, .chat-active { background: rgba(0, 242, 254, 0.15); color: var(--primary); }
        .chat-main { display: flex; flex-direction: column; padding: 15px; }
        .chat-history { flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; margin-bottom: 10px; }
        .bubble { max-width: 80%; padding: 10px 15px; border-radius: 15px; font-size: 0.8rem; }
        .bubble-dr { background: rgba(255,255,255,0.1); align-self: flex-start; }
        .bubble-me { background: var(--primary); color: #000; align-self: flex-end; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); }
    </style>
</head>
<body>

<div id="notifModal" class="glass">
    <h4 style="margin-top:0; color:var(--primary);"><?= $notif_title ?></h4>
    <div style="max-height:200px; overflow-y:auto;">
        <?php if(empty($notifs)): ?>
            <div style="font-size:0.8rem;">No new alerts.</div>
        <?php else: ?>
            <?php foreach($notifs as $n): ?>
                <div style="padding:8px; border-bottom:1px solid #1e293b; font-size:0.75rem;">
                    <?= $_SESSION['role'] == 'doctor' ? "Register: <b>".$n['fullname']."</b>" : "Advice: <i>".substr($n['advice_text'],0,20)."...</i>" ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <button onclick="toggleNotif()" class="btn" style="padding:5px; margin-top:10px; font-size:0.7rem;">CLOSE</button>
</div>

<nav class="nav">
    <a href="index.php" class="logo">PERSONAL HEALTH RECOMMENDATION SYSTEM</a>
    <?php if(isset($_SESSION['user_id'])): ?>
        <div style="display:flex; align-items:center;">
            <div class="bell-box <?= count($notifs) > 0 ? 'bell-ring' : '' ?>" onclick="toggleNotif()">
                🔔 <?= count($notifs) > 0 ? "<span class='count'>".count($notifs)."</span>" : "" ?>
            </div>
            <span style="font-size:0.85rem;">OPERATOR: <b><?= strtoupper($_SESSION['fullname']) ?></b> | <a href="index.php?logout=true" style="color:var(--danger); text-decoration:none;">LOGOUT</a></span>
        </div>
    <?php endif; ?>
</nav>

<?php if(!isset($_SESSION['user_id'])): ?>
    <div style="height:85vh; display:flex; align-items:center; justify-content:center;">
        <div class="glass widget" style="width:400px; text-align:center;">
            <?php if($view == 'login'): ?>
                <h2>PHRS</h2>
                <?php if($msg) echo "<p style='color:var(--danger); font-size:0.8rem;'>$msg</p>"; ?>
                <form method="POST">
                    <input type="email" name="email" placeholder="System ID" required>
                    <input type="password" name="password" placeholder="Passcode" required>
                    <button type="submit" name="login" class="btn">AUTHENTICATE</button>
                </form>
                <p style="font-size:0.8rem;"><a href="index.php?view=register" style="color:var(--primary)">Request Enrollment</a></p>
            <?php else: ?>
                <h2>ENROLLMENT</h2>
                <form method="POST" enctype="multipart/form-data">
                    <input type="text" name="fullname" placeholder="Full Name" required>
                    <input type="email" name="email" placeholder="Email" required>
                    <input type="password" name="password" placeholder="Password" required>
                    <label style="font-size:0.6rem; color:var(--primary); text-align:left; display:block;">KYC ID (School ID/Birth Cert):</label>
                    <input type="file" name="v_doc" required>
                    <button type="submit" name="register" class="btn">REQUEST ACCESS</button>
                </form>
                <a href="index.php?view=login" style="color:var(--primary); font-size:0.8rem;">Back to Login</a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="layout">
        <aside class="glass sidebar">
            <a href="index.php?page=overview" class="<?= $page=='overview'?'active':'' ?>">🏠 Overview</a>
            <?php if($_SESSION['role'] == 'doctor'): ?>
                <a href="index.php?page=verify" class="<?= $page=='verify'?'active':'' ?>">🛡️ Verification Hub</a>
            <?php endif; ?>
        </aside>

        <main>
            <?php if($page == 'overview'): ?>
                <div style="display:grid; grid-template-columns: 1fr 1.5fr; gap:2rem;">
                    <div class="glass widget">
                        <?php if($_SESSION['role'] == 'patient'): ?>
                            <h3 style="margin-top:0;">Sync Vitals</h3>
                            <form method="POST" enctype="multipart/form-data">
                                <input type="number" name="sugar" placeholder="Sugar" required>
                                <input type="text" name="bp" placeholder="BP" required>
                                <input type="number" name="hr" placeholder="HR" required>
                                <input type="file" name="history_img">
                                <button type="submit" name="save_vitals" class="btn">BROADCAST DATA</button>
                            </form>
                        <?php else: ?>
                            <h3 style="margin-top:0;">Live Monitor</h3>
                            <?php
                            $stmt = $pdo->query("SELECT u.fullname, h.* FROM health_logs h JOIN users u ON h.patient_id = u.id ORDER BY h.logged_at DESC LIMIT 5");
                            while($h = $stmt->fetch()): ?>
                                <div style="font-size:0.8rem; margin-bottom:10px; border-bottom:1px solid #1e293b;">
                                    <b><?= $h['fullname'] ?></b> (Sugar: <?= $h['sugar_level'] ?>)<br>
                                    <a href="uploads/<?= $h['medical_history_img'] ?>" target="_blank" style="color:var(--primary);">View History Record</a>
                                </div>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </div>

                    <div class="glass messenger-box">
                        <div class="inbox-list">
                            <small style="color:var(--primary); font-weight:800; display:block; padding-bottom:10px;">INBOX</small>
                            <?php if($_SESSION['role'] == 'doctor'): 
                                $pts = $pdo->query("SELECT id, fullname FROM users WHERE role='patient' AND status=1")->fetchAll();
                                foreach($pts as $p): ?>
                                    <a href="index.php?chat_with=<?= $p['id'] ?>" class="inbox-item <?= $chat_with==$p['id']?'chat-active':'' ?>"><?= $p['fullname'] ?></a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <a href="#" class="inbox-item chat-active">Dr. Daniel</a>
                            <?php endif; ?>
                        </div>
                        <div class="chat-main">
                            <div class="chat-history">
                                <?php
                                $target = $_SESSION['role'] == 'patient' ? $_SESSION['user_id'] : $chat_with;
                                if($target):
                                    $stmt = $pdo->prepare("SELECT * FROM doctor_advice WHERE patient_id = ? ORDER BY created_at ASC");
                                    $stmt->execute([$target]);
                                    while($c = $stmt->fetch()):
                                        $me = (strpos($c['advice_text'], 'REPLY:') !== false && $_SESSION['role'] == 'patient') || (strpos($c['advice_text'], 'REPLY:') === false && $_SESSION['role'] == 'doctor');
                                        echo "<div class='bubble ".($me?'bubble-me':'bubble-dr')."'>".str_replace('REPLY: ', '', $c['advice_text'])."</div>";
                                    endwhile;
                                endif; ?>
                            </div>
                            <?php if($target): ?>
                            <form method="POST" style="display:flex; gap:5px;">
                                <input type="hidden" name="patient_id" value="<?= $target ?>">
                                <input type="text" name="message" placeholder="Message..." required style="margin:0; padding:10px;">
                                <button type="submit" name="send_msg" class="btn" style="width:auto; margin:0; padding:0 15px;">SEND</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if($_SESSION['role'] == 'patient'): ?>
                    <script src="https://www.tuqlas.com/chatbot.js" data-key="tq_live_81471455c08ae08ce43e76da626f26b0281326f7" data-api="https://www.tuqlas.com" defer></script>
                <?php endif; ?>

            <?php elseif($page == 'verify' && $_SESSION['role'] == 'doctor'): ?>
                <div class="glass widget">
                    <h3>Account Verification (Dr. Daniel)</h3>
                    <table>
                        <thead><tr><th>Applicant</th><th>Document</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php
                            $stmt = $pdo->query("SELECT * FROM users WHERE status = 0 AND role = 'patient'");
                            while($u = $stmt->fetch()): ?>
                                <tr><td><?= $u['fullname'] ?></td><td><a href="verifications/<?= $u['v_document'] ?>" target="_blank" style="color:var(--primary);">View Proof</a></td>
                                    <td><form method="POST"><input type="hidden" name="u_id" value="<?= $u['id'] ?>"><button type="submit" name="approve_user" class="btn" style="padding:5px 10px; width:auto;">APPROVE</button></form></td></tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
    </div>
<?php endif; ?>

<script>
    function toggleNotif() {
        var modal = document.getElementById('notifModal');
        modal.style.display = (modal.style.display === 'block') ? 'none' : 'block';
    }
</script>
</body>
</html>