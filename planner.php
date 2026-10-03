<?php
session_start();
require_once __DIR__ . "/config.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION["csrf_token"];

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function redirect_to(string $view = "dashboard"): void
{
    header("Location: index.php?view=" . urlencode($view));
    exit;
}

function save_attachment(string $field, ?string $current = null, bool $remove = false): ?string
{
    if ($remove && $current && is_file(__DIR__ . "/" . $current)) {
        unlink(__DIR__ . "/" . $current);
        $current = null;
    }
    if (empty($_FILES[$field]["name"])) {
        return $current;
    }
    if ($_FILES[$field]["error"] !== UPLOAD_ERR_OK || $_FILES[$field]["size"] > 2 * 1024 * 1024) {
        throw new RuntimeException("Choose an image smaller than 2 MB.");
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($_FILES[$field]["tmp_name"]);
    $extensions = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException("Only JPG, PNG, and WEBP images are allowed.");
    }
    $folder = __DIR__ . "/uploads";
    if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
        throw new RuntimeException("The uploads folder could not be created.");
    }
    $filename = bin2hex(random_bytes(16)) . "." . $extensions[$mime];
    if (!move_uploaded_file($_FILES[$field]["tmp_name"], $folder . "/" . $filename)) {
        throw new RuntimeException("The image could not be saved.");
    }
    if ($current && is_file(__DIR__ . "/" . $current)) {
        unlink(__DIR__ . "/" . $current);
    }
    return "uploads/" . $filename;
}

function post_value(string $key): string
{
    return trim((string) ($_POST[$key] ?? ""));
}

$view = $_GET["view"] ?? "dashboard";
$allowedViews = ["dashboard", "tasks", "subjects", "calendar", "schedule", "notes", "progress", "settings"];
if (!in_array($view, $allowedViews, true)) {
    $view = "dashboard";
}
$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        if (!hash_equals($csrf, (string) ($_POST["csrf_token"] ?? ""))) {
            throw new RuntimeException("Your session expired. Refresh and try again.");
        }
        $action = post_value("action");
        if ($action === "save_task") {
            $id = (int) ($_POST["id"] ?? 0);
            $title = post_value("title");
            $due = str_replace("T", " ", post_value("due_at"));
            if ($title === "" || $due === "") throw new RuntimeException("Task title and deadline are required.");
            $subjectId = (int) ($_POST["subject_id"] ?? 0) ?: null;
            $description = post_value("description");
            $type = post_value("type") ?: "Assignment";
            $priority = post_value("priority") ?: "Medium";
            $status = post_value("status") ?: "Pending";
            $existing = null;
            if ($id) {
                $lookup = $conn->prepare("SELECT attachment FROM tasks WHERE id = ?");
                $lookup->bind_param("i", $id); $lookup->execute(); $existing = $lookup->get_result()->fetch_assoc();
            }
            $attachment = save_attachment("attachment", $existing["attachment"] ?? null, isset($_POST["remove_attachment"]));
            if ($id) {
                $stmt = $conn->prepare("UPDATE tasks SET subject_id=?, title=?, description=?, task_type=?, priority=?, due_at=?, status=?, attachment=? WHERE id=?");
                $stmt->bind_param("isssssssi", $subjectId, $title, $description, $type, $priority, $due, $status, $attachment, $id);
            } else {
                $stmt = $conn->prepare("INSERT INTO tasks (subject_id,title,description,task_type,priority,due_at,status,attachment) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->bind_param("isssssss", $subjectId, $title, $description, $type, $priority, $due, $status, $attachment);
            }
            $stmt->execute(); redirect_to("tasks");
        }
        if ($action === "task_status") {
            $id = (int) ($_POST["id"] ?? 0); $status = post_value("status");
            $stmt = $conn->prepare("UPDATE tasks SET status=? WHERE id=?"); $stmt->bind_param("si", $status, $id); $stmt->execute(); redirect_to("tasks");
        }
        if ($action === "delete_task") {
            $id = (int) ($_POST["id"] ?? 0); $stmt = $conn->prepare("SELECT attachment FROM tasks WHERE id=?"); $stmt->bind_param("i", $id); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc();
            $stmt = $conn->prepare("DELETE FROM tasks WHERE id=?"); $stmt->bind_param("i", $id); $stmt->execute();
            if (!empty($row["attachment"]) && is_file(__DIR__ . "/" . $row["attachment"])) unlink(__DIR__ . "/" . $row["attachment"]);
            redirect_to("tasks");
        }
        if ($action === "save_subject") {
            $id = (int) ($_POST["id"] ?? 0); $name = post_value("name"); $code = post_value("code");
            if ($name === "" || $code === "") throw new RuntimeException("Subject name and code are required.");
            $instructor = post_value("instructor"); $scheduleText = post_value("schedule_text"); $color = post_value("color") ?: "#bf7184"; $description = post_value("description");
            if ($id) { $stmt = $conn->prepare("UPDATE subjects SET name=?,code=?,instructor=?,schedule_text=?,color=?,description=? WHERE id=?"); $stmt->bind_param("ssssssi", $name,$code,$instructor,$scheduleText,$color,$description,$id); }
            else { $stmt = $conn->prepare("INSERT INTO subjects (name,code,instructor,schedule_text,color,description) VALUES (?,?,?,?,?,?)"); $stmt->bind_param("ssssss", $name,$code,$instructor,$scheduleText,$color,$description); }
            $stmt->execute(); redirect_to("subjects");
        }
        if ($action === "delete_subject") { $id=(int)($_POST["id"]??0); $stmt=$conn->prepare("DELETE FROM subjects WHERE id=?"); $stmt->bind_param("i",$id); $stmt->execute(); redirect_to("subjects"); }
        if ($action === "save_session") {
            $id=(int)($_POST["id"]??0); $subjectId=(int)($_POST["subject_id"]??0) ?: null; $topic=post_value("topic"); $date=post_value("study_date"); $start=post_value("start_time"); $end=post_value("end_time");
            if ($topic==="" || $date==="" || $start==="" || $end==="" || $end <= $start) throw new RuntimeException("Add a topic and a valid start/end time.");
            $startAt="$date $start:00"; $endAt="$date $end:00"; $goal=post_value("goal"); $notesText=post_value("notes");
            if ($id) { $stmt=$conn->prepare("UPDATE study_sessions SET subject_id=?,topic=?,start_at=?,end_at=?,goal=?,notes=? WHERE id=?"); $stmt->bind_param("isssssi",$subjectId,$topic,$startAt,$endAt,$goal,$notesText,$id); }
            else { $stmt=$conn->prepare("INSERT INTO study_sessions (subject_id,topic,start_at,end_at,goal,notes) VALUES (?,?,?,?,?,?)"); $stmt->bind_param("isssss",$subjectId,$topic,$startAt,$endAt,$goal,$notesText); }
            $stmt->execute(); redirect_to("schedule");
        }
        if ($action === "session_status") { $id=(int)($_POST["id"]??0); $stmt=$conn->prepare("UPDATE study_sessions SET completed=1 WHERE id=?"); $stmt->bind_param("i",$id); $stmt->execute(); redirect_to("schedule"); }
        if ($action === "delete_session") { $id=(int)($_POST["id"]??0); $stmt=$conn->prepare("DELETE FROM study_sessions WHERE id=?"); $stmt->bind_param("i",$id); $stmt->execute(); redirect_to("schedule"); }
        if ($action === "save_note") {
            $id=(int)($_POST["id"]??0); $title=post_value("title"); $body=post_value("body"); $subjectId=(int)($_POST["subject_id"]??0) ?: null;
            if ($title==="" || $body==="") throw new RuntimeException("A note title and note content are required.");
            $existing=null; if($id){$lookup=$conn->prepare("SELECT attachment FROM notes WHERE id=?");$lookup->bind_param("i",$id);$lookup->execute();$existing=$lookup->get_result()->fetch_assoc();}
            $attachment=save_attachment("attachment",$existing["attachment"]??null,isset($_POST["remove_attachment"]));
            if($id){$stmt=$conn->prepare("UPDATE notes SET subject_id=?,title=?,body=?,attachment=? WHERE id=?");$stmt->bind_param("isssi",$subjectId,$title,$body,$attachment,$id);}
            else{$stmt=$conn->prepare("INSERT INTO notes (subject_id,title,body,attachment) VALUES (?,?,?,?)");$stmt->bind_param("isss",$subjectId,$title,$body,$attachment);}
            $stmt->execute();redirect_to("notes");
        }
        if ($action === "delete_note") { $id=(int)($_POST["id"]??0);$stmt=$conn->prepare("SELECT attachment FROM notes WHERE id=?");$stmt->bind_param("i",$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt=$conn->prepare("DELETE FROM notes WHERE id=?");$stmt->bind_param("i",$id);$stmt->execute();if(!empty($row["attachment"])&&is_file(__DIR__ . "/".$row["attachment"]))unlink(__DIR__ . "/".$row["attachment"]);redirect_to("notes"); }
        if ($action === "save_settings") { $name=post_value("student_name")?:"Student";$theme=post_value("theme")==="dark"?"dark":"light";$focus=max(1,min(180,(int)($_POST["focus_minutes"]??25)));$short=max(1,min(60,(int)($_POST["short_minutes"]??5)));$long=max(1,min(90,(int)($_POST["long_minutes"]??15)));$stmt=$conn->prepare("UPDATE planner_settings SET student_name=?,theme=?,focus_minutes=?,short_minutes=?,long_minutes=? WHERE id=1");$stmt->bind_param("ssiii",$name,$theme,$focus,$short,$long);$stmt->execute();redirect_to("settings"); }
        if ($action === "focus_complete") { $subjectId=(int)($_POST["subject_id"]??0)?:null;$minutes=max(1,min(180,(int)($_POST["minutes"]??25)));$start=date("Y-m-d H:i:s",time()-$minutes*60);$end=date("Y-m-d H:i:s");$topic="Pomodoro focus session";$goal="Completed focus timer";$stmt=$conn->prepare("INSERT INTO study_sessions(subject_id,topic,start_at,end_at,goal,completed,is_timer) VALUES (?,?,?,?,?,1,1)");$stmt->bind_param("issss",$subjectId,$topic,$start,$end,$goal);$stmt->execute();http_response_code(204);exit; }
        throw new RuntimeException("That action is not available.");
    } catch (Throwable $error) {
        $message = $error->getMessage();
    }
}

$settings = $conn->query("SELECT * FROM planner_settings WHERE id=1")->fetch_assoc() ?: ["student_name"=>"Student","theme"=>"light","focus_minutes"=>25,"short_minutes"=>5,"long_minutes"=>15];
$subjects = $conn->query("SELECT * FROM subjects ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$tasks = $conn->query("SELECT t.*, s.name AS subject_name, s.code AS subject_code, s.color AS subject_color FROM tasks t LEFT JOIN subjects s ON s.id=t.subject_id ORDER BY t.due_at ASC")->fetch_all(MYSQLI_ASSOC);
$sessions = $conn->query("SELECT ss.*, s.name AS subject_name, s.code AS subject_code, s.color AS subject_color FROM study_sessions ss LEFT JOIN subjects s ON s.id=ss.subject_id ORDER BY ss.start_at ASC")->fetch_all(MYSQLI_ASSOC);
$notes = $conn->query("SELECT n.*, s.name AS subject_name, s.code AS subject_code, s.color AS subject_color FROM notes n LEFT JOIN subjects s ON s.id=n.subject_id ORDER BY n.updated_at DESC")->fetch_all(MYSQLI_ASSOC);

$today = date("Y-m-d");
$now = date("Y-m-d H:i:s");
$totalTasks=count($tasks);$completedTasks=0;$pendingTasks=0;$overdueTasks=0;$upcomingTasks=[];
foreach($tasks as $task){if($task["status"]==="Completed")$completedTasks++;else{$pendingTasks++;if(substr($task["due_at"],0,10)<$today)$overdueTasks++;}if($task["status"]!=="Completed"&&$task["due_at"]>=date("Y-m-d H:i:s"))$upcomingTasks[]=$task;}
$completion=$totalTasks?(int)round($completedTasks/$totalTasks*100):0;
$todaySessions=array_values(array_filter($sessions,fn($session)=>substr($session["start_at"],0,10)===$today));
$upcomingSessions=array_values(array_filter($sessions,fn($session)=>(int)$session["completed"]===0&&$session["start_at"]>=$now));
$completedSessions=count(array_filter($sessions,fn($session)=>(int)$session["completed"]===1));
$completedFocusSessions=count(array_filter($sessions,fn($session)=>(int)$session["completed"]===1&&(int)$session["is_timer"]===1));
$totalStudyMinutes=0;foreach($sessions as $session){if((int)$session["completed"]===1)$totalStudyMinutes+=max(0,(strtotime($session["end_at"])-strtotime($session["start_at"]))/60);}
$nowLabel=date("l, F j, Y");
$calendarItems=[];foreach($tasks as $task){$calendarItems[]=["id"=>(int)$task["id"],"title"=>$task["title"],"date"=>substr($task["due_at"],0,10),"time"=>substr($task["due_at"],11,5),"kind"=>strtolower($task["task_type"]),"subject"=>$task["subject_name"]??"General","color"=>$task["subject_color"]??"#bf7184","status"=>$task["status"]];}foreach($sessions as $session){$calendarItems[]=["id"=>(int)$session["id"],"title"=>$session["topic"],"date"=>substr($session["start_at"],0,10),"time"=>substr($session["start_at"],11,5),"kind"=>"study", "subject"=>$session["subject_name"]??"Study", "color"=>$session["subject_color"]??"#668478", "status"=>(int)$session["completed"]?"Completed":"Scheduled"];} 
?>
<!doctype html>
<html lang="en" data-theme="<?= h($settings["theme"]) ?>">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Studyroom | Study Planner</title><link rel="stylesheet" href="planner.css">
    <script>window.PLANNER_DATA=<?= json_encode(["subjects"=>$subjects,"tasks"=>$tasks,"sessions"=>$sessions,"notes"=>$notes,"calendar"=>$calendarItems,"settings"=>$settings,"csrf"=>$csrf], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;</script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="index.php"><span class="brand-mark">S</span><span><b>studyroom</b><small>YOUR SEMESTER, IN ORDER</small></span></a>
        <div class="nav-label">WORKSPACE</div>
        <nav class="navigation" aria-label="Main navigation">
            <a href="?view=dashboard" data-view="dashboard"><span>⌂</span>Overview</a><a href="?view=tasks" data-view="tasks"><span>▤</span>Tasks <i><?= $pendingTasks ?: "" ?></i></a><a href="?view=subjects" data-view="subjects"><span>◈</span>Subjects</a><a href="?view=calendar" data-view="calendar"><span>▦</span>Calendar</a><a href="?view=schedule" data-view="schedule"><span>◷</span>Study plan</a><a href="?view=notes" data-view="notes"><span>▧</span>Notes</a><a href="?view=progress" data-view="progress"><span>↗</span>Progress</a>
        </nav>
        <div class="sidebar-bottom"><div class="storage-note"><span class="live-dot"></span><div><b>MySQL connected</b><small>Planner data is saved</small></div></div><a class="settings-link" href="?view=settings" data-view="settings"><span>⚙</span> Settings</a></div>
    </aside>
    <main class="main-content">
        <header class="topbar"><button class="menu-toggle" id="menuToggle" aria-label="Open navigation">☰</button><div class="crumb"><span>MY PLANNER</span><b>/</b><strong id="pageName">Overview</strong></div><div class="topbar-right"><span class="date-chip">◷ <?= h($nowLabel) ?></span><span class="user-avatar"><?= h(strtoupper(substr($settings["student_name"],0,1))) ?></span></div></header>
        <?php if($message): ?><div class="notice" role="alert"><?= h($message) ?></div><?php endif; ?>
        <div class="page-heading"><div><p class="eyebrow">YOUR PERSONAL STUDY SPACE</p><h1 id="headingTitle">Overview</h1></div><div class="heading-actions"><button class="button button-quiet" data-open="sessionDialog">＋ Study session</button><button class="button button-primary" data-open="taskDialog">＋ Add task</button></div></div>

        <section class="view-section" data-section="dashboard">
            <div class="welcome-banner"><div><p class="eyebrow"><?= h(strtoupper(date("l"))) ?>, <?= h(date("F j")) ?></p><h2>Hi, <?= h($settings["student_name"]) ?>. <span>Let's make today count.</span></h2><p>A little progress, consistently, adds up.</p></div><div class="banner-mark" aria-hidden="true">✳</div></div>
            <div class="stats-grid">
                <article class="stat-card"><div class="stat-head"><span>Tasks in play</span><i class="stat-symbol rose">▤</i></div><strong><?= $totalTasks ?></strong><small><?= $pendingTasks ?> still to do</small></article>
                <article class="stat-card"><div class="stat-head"><span>Completed</span><i class="stat-symbol sage">✓</i></div><strong><?= $completedTasks ?></strong><small>Across all subjects</small></article>
                <article class="stat-card"><div class="stat-head"><span>Study time</span><i class="stat-symbol ochre">◷</i></div><strong><?= floor($totalStudyMinutes/60) ?><em>h</em> <?= $totalStudyMinutes%60 ?><em>m</em></strong><small>Recorded completed sessions</small></article>
                <article class="stat-card"><div class="stat-head"><span>Completion</span><i class="stat-symbol lilac">↗</i></div><strong><?= $completion ?><em>%</em></strong><div class="progress-track"><span style="width:<?= $completion ?>%"></span></div></article>
            </div>
            <div class="dashboard-grid">
                <article class="surface focus-surface"><div class="section-top"><div><p class="eyebrow">UP NEXT</p><h2>Coming deadlines</h2></div><a href="?view=tasks" data-view="tasks" class="text-link">All tasks →</a></div>
                    <?php if($upcomingTasks): foreach(array_slice($upcomingTasks,0,4) as $task): ?><div class="deadline-row"><span class="subject-dot" style="--subject:<?= h($task["subject_color"]??"#bf7184") ?>"></span><div class="deadline-copy"><b><?= h($task["title"]) ?></b><small><?= h($task["subject_code"]??"General") ?> · <?= h($task["task_type"]) ?></small></div><span class="deadline-date"><?= h(date("M j",strtotime($task["due_at"]))) ?></span></div><?php endforeach; else: ?><div class="empty-state"><span>✳</span><b>Your calendar has breathing room.</b><small>Add an assignment or exam to keep it here.</small></div><?php endif; ?>
                </article>
                <article class="surface"><div class="section-top"><div><p class="eyebrow">TODAY</p><h2>Study schedule</h2></div><a href="?view=schedule" data-view="schedule" class="text-link">Open plan →</a></div>
                    <?php if($todaySessions): foreach($todaySessions as $session): ?><div class="today-session"><span class="session-time"><?= h(date("g:i A",strtotime($session["start_at"]))) ?></span><span class="session-line" style="--subject:<?= h($session["subject_color"]??"#668478") ?>"></span><div><b><?= h($session["topic"]) ?></b><small><?= h($session["subject_name"]??"Independent study") ?></small></div></div><?php endforeach; else: ?><div class="empty-state compact"><span>◷</span><b>No sessions on the calendar.</b><small>Set aside a focused block for yourself.</small><button class="text-link" data-open="sessionDialog">Schedule a session +</button></div><?php endif; ?>
                </article>
            </div>
            <article class="surface reminders-surface"><div class="section-top"><div><p class="eyebrow">KEEP AN EYE ON</p><h2>Reminders</h2></div><span class="reminder-count"><?= $overdueTasks ?> overdue</span></div><div class="reminder-list">
                <?php if($overdueTasks): ?><div class="reminder-item urgent"><span>!</span><div><b><?= $overdueTasks ?> overdue task<?= $overdueTasks>1?"s":"" ?></b><small>Review your task list and update the dates or status.</small></div><a href="?view=tasks" data-view="tasks">Review</a></div><?php endif; ?>
                <?php foreach(array_slice($upcomingTasks,0,3) as $task): $days=(int)floor((strtotime(substr($task["due_at"],0,10))-strtotime($today))/86400); ?><div class="reminder-item"><span>◷</span><div><b><?= h($task["title"]) ?></b><small><?= $days===0?"Due today":($days===1?"Due tomorrow":"Due in $days days") ?> · <?= h($task["subject_code"]??"General") ?></small></div><span class="priority-label <?= strtolower(h($task["priority"])) ?>"><?= h($task["priority"]) ?></span></div><?php endforeach; ?>
                <?php foreach(array_slice($upcomingSessions,0,2) as $session): ?><div class="reminder-item"><span>◷</span><div><b><?= h($session["topic"]) ?></b><small>Study session · <?= h(date("M j, g:i A",strtotime($session["start_at"]))) ?></small></div><a href="?view=schedule" data-view="schedule">Plan</a></div><?php endforeach; ?>
                <?php if(!$overdueTasks&&!$upcomingTasks&&!$upcomingSessions): ?><div class="empty-inline">No upcoming deadlines or sessions. You're all caught up.</div><?php endif; ?></div><p class="disclaimer">In-app reminders appear while this planner is open. Browser notifications are not scheduled when the website is closed.</p></article>
        </section>

        <section class="view-section" data-section="tasks" hidden>
            <div class="toolbar"><label class="search-box"><span>⌕</span><input id="taskSearch" type="search" placeholder="Search tasks"></label><select id="taskSubject"><option value="">All subjects</option><?php foreach($subjects as $subject): ?><option value="<?= h($subject["name"]) ?>"><?= h($subject["name"]) ?></option><?php endforeach; ?></select><select id="taskStatus"><option value="">Any status</option><option>Pending</option><option>In Progress</option><option>Completed</option></select><select id="taskPriority"><option value="">Any priority</option><option>High</option><option>Medium</option><option>Low</option></select><input id="taskDue" type="date" aria-label="Filter tasks by due date"><select id="taskSort"><option value="deadline">Soonest first</option><option value="priority">Priority first</option></select></div>
            <article class="surface table-surface"><div class="section-top"><div><p class="eyebrow">SEMESTER CHECKLIST</p><h2>Academic tasks <span class="count-pill"><?= $totalTasks ?></span></h2></div><button class="button button-primary" data-open="taskDialog">＋ New task</button></div><div class="task-list" id="taskList">
                <?php foreach($tasks as $task): $overdue=$task["status"]!=="Completed"&&substr($task["due_at"],0,10)<$today; ?><article class="task-row <?= $overdue?"is-overdue":"" ?>" data-title="<?= h(strtolower($task["title"]." ".$task["description"])) ?>" data-subject="<?= h($task["subject_name"]??"") ?>" data-status="<?= h($task["status"]) ?>" data-priority="<?= h($task["priority"]) ?>" data-deadline="<?= h($task["due_at"]) ?>">
                    <div class="task-check <?= $task["status"]==="Completed"?"checked":"" ?>"><?php if($task["status"]!=="Completed"): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="task_status"><input type="hidden" name="id" value="<?= (int)$task["id"] ?>"><input type="hidden" name="status" value="Completed"><button aria-label="Mark <?= h($task["title"]) ?> complete">✓</button></form><?php else: ?>✓<?php endif; ?></div>
                    <span class="subject-dot" style="--subject:<?= h($task["subject_color"]??"#bf7184") ?>"></span><div class="task-main"><b><?= h($task["title"]) ?></b><small><?= h($task["subject_code"]??"General") ?> · <?= h($task["task_type"]) ?><?php if($task["attachment"]): ?> · <a href="<?= h($task["attachment"]) ?>" target="_blank">Photo</a><?php endif; ?></small></div><span class="priority-label <?= strtolower(h($task["priority"])) ?>"><?= h($task["priority"]) ?></span><span class="task-due <?= $overdue?"urgent-text":"" ?>"><?= $overdue?"Overdue · ":"" ?><?= h(date("M j, g:i A",strtotime($task["due_at"]))) ?></span><span class="status-label <?= strtolower(str_replace(" ","-",h($task["status"]))) ?>"><?= h($task["status"]) ?></span><div class="row-actions"><button class="icon-button edit-task" data-id="<?= (int)$task["id"] ?>" title="Edit task">✎</button><form method="post" onsubmit="return confirm('Delete this task?')"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_task"><input type="hidden" name="id" value="<?= (int)$task["id"] ?>"><button class="icon-button danger" title="Delete task">×</button></form></div>
                </article><?php endforeach; ?><div class="empty-inline" id="noTasks" <?= $tasks?"hidden":"" ?>>No matching tasks. Try a different filter or add a task.</div>
            </div></article>
        </section>

        <section class="view-section" data-section="subjects" hidden><div class="section-top"><div><p class="eyebrow">YOUR CLASSES</p><h2>Subjects</h2></div><button class="button button-primary" data-open="subjectDialog">＋ Add subject</button></div><div class="subject-grid">
            <?php foreach($subjects as $subject): $subjectTasks=array_filter($tasks,fn($task)=>(int)$task["subject_id"]===(int)$subject["id"]); ?><article class="subject-card" style="--subject:<?= h($subject["color"]) ?>"><div class="subject-card-top"><span class="subject-code"><?= h($subject["code"]) ?></span><div class="row-actions"><button class="icon-button edit-subject" data-id="<?= (int)$subject["id"] ?>" title="Edit subject">✎</button><form method="post" onsubmit="return confirm('Delete subject? Related records will remain without a subject.')"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_subject"><input type="hidden" name="id" value="<?= (int)$subject["id"] ?>"><button class="icon-button danger" title="Delete subject">×</button></form></div></div><h3><?= h($subject["name"]) ?></h3><p><?= h($subject["instructor"]?:"Instructor not set") ?></p><div class="subject-meta"><span><?= count($subjectTasks) ?> tasks</span><span><?= h($subject["schedule_text"]?:"Schedule not set") ?></span></div><?php if($subject["description"]): ?><small class="subject-description"><?= h($subject["description"]) ?></small><?php endif; ?></article><?php endforeach; ?>
            <?php if(!$subjects): ?><div class="empty-state"><span>◈</span><b>No subjects yet.</b><small>Add a class to connect tasks, notes, and study time.</small></div><?php endif; ?></div></section>

        <section class="view-section" data-section="calendar" hidden><article class="surface calendar-surface"><div class="calendar-toolbar"><div><p class="eyebrow">DEADLINES & STUDY TIME</p><h2 id="calendarTitle">Calendar</h2></div><div class="calendar-controls"><button class="icon-button" id="calendarPrev" aria-label="Previous">‹</button><button class="button button-quiet" id="calendarToday">Today</button><button class="icon-button" id="calendarNext" aria-label="Next">›</button><div class="segmented"><button class="active" data-calendar-mode="month">Month</button><button data-calendar-mode="week">Week</button></div></div></div><div class="calendar-grid" id="calendarGrid"></div><div class="calendar-legend"><span><i class="legend-task"></i>Academic task</span><span><i class="legend-study"></i>Study session</span></div><div class="selected-day"><div class="section-top"><div><p class="eyebrow">SELECTED DAY</p><h3 id="selectedDateLabel">Choose a date</h3></div><div class="heading-actions"><button class="button button-quiet" id="addSessionOnDate">＋ Study session</button><button class="button button-quiet" id="addOnDate">＋ Add task</button></div></div><div id="selectedEvents" class="selected-events"></div></div></article></section>

        <section class="view-section" data-section="schedule" hidden><div class="section-top"><div><p class="eyebrow">MAKE ROOM TO LEARN</p><h2>Study plan</h2><p class="subtle">Overlaps are flagged before you save.</p></div><button class="button button-primary" data-open="sessionDialog">＋ Schedule session</button></div><div class="schedule-list">
            <?php foreach($sessions as $session): ?><article class="schedule-row" data-session-id="<?= (int)$session["id"] ?>"><div class="schedule-date"><b><?= h(date("d",strtotime($session["start_at"]))) ?></b><small><?= h(date("M",strtotime($session["start_at"]))) ?></small></div><div class="schedule-time"><?= h(date("g:i A",strtotime($session["start_at"]))) ?><small>to <?= h(date("g:i A",strtotime($session["end_at"]))) ?></small></div><span class="subject-dot" style="--subject:<?= h($session["subject_color"]??"#668478") ?>"></span><div class="schedule-copy"><b><?= h($session["topic"]) ?></b><small><?= h($session["subject_name"]??"Independent study") ?><?= $session["goal"]?" · ".h($session["goal"]):"" ?></small></div><?php if((int)$session["completed"]): ?><span class="status-label completed">Done</span><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="session_status"><input type="hidden" name="id" value="<?= (int)$session["id"] ?>"><button class="button button-quiet">Mark done</button></form><?php endif; ?><div class="row-actions"><button class="icon-button edit-session" data-id="<?= (int)$session["id"] ?>" title="Edit session">✎</button><form method="post" onsubmit="return confirm('Delete this study session?')"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_session"><input type="hidden" name="id" value="<?= (int)$session["id"] ?>"><button class="icon-button danger" title="Delete session">×</button></form></div></article><?php endforeach; ?>
            <?php if(!$sessions): ?><div class="empty-state"><span>◷</span><b>Your study plan is open.</b><small>Choose a time and give one topic your attention.</small></div><?php endif; ?></div><article class="surface timer-card"><div class="timer-copy"><p class="eyebrow">POMODORO</p><h2>Focus, then breathe.</h2><p>Choose a subject, start a focus block, and take a real break when it ends.</p><label class="field-label" for="timerSubject">Studying</label><select id="timerSubject"><option value="">Choose subject</option><?php foreach($subjects as $subject): ?><option value="<?= (int)$subject["id"] ?>"><?= h($subject["name"]) ?></option><?php endforeach; ?></select><div class="timer-modes"><button class="active" data-timer-mode="focus">Focus</button><button data-timer-mode="short">Short break</button><button data-timer-mode="long">Long break</button></div><div class="timer-settings"><label>Focus <input id="focusSetting" type="number" min="1" max="180" value="<?= (int)$settings["focus_minutes"] ?>"> min</label><label>Short <input id="shortSetting" type="number" min="1" max="60" value="<?= (int)$settings["short_minutes"] ?>"> min</label><label>Long <input id="longSetting" type="number" min="1" max="90" value="<?= (int)$settings["long_minutes"] ?>"> min</label></div></div><div class="timer-face"><div class="timer-ring"><span id="timerDisplay">25:00</span><small id="timerLabel">READY TO FOCUS</small></div><div class="timer-buttons"><button class="button button-primary" id="timerStart">Start</button><button class="button button-quiet" id="timerReset">Reset</button></div><small class="timer-count"><b id="focusCount"><?= $completedFocusSessions ?></b> recorded focus sessions</small></div></article></section>

        <section class="view-section" data-section="notes" hidden><div class="toolbar"><label class="search-box"><span>⌕</span><input id="noteSearch" type="search" placeholder="Search notes"></label><select id="noteSubject"><option value="">All subjects</option><?php foreach($subjects as $subject): ?><option value="<?= h($subject["name"]) ?>"><?= h($subject["name"]) ?></option><?php endforeach; ?></select><button class="button button-primary" data-open="noteDialog">＋ New note</button></div><div class="notes-grid" id="notesGrid">
            <?php foreach($notes as $note): ?><article class="note-card" data-title="<?= h(strtolower($note["title"]." ".$note["body"])) ?>" data-subject="<?= h($note["subject_name"]??"") ?>"><div class="note-top"><span class="subject-code" style="--subject:<?= h($note["subject_color"]??"#bf7184") ?>"><?= h($note["subject_code"]??"GENERAL") ?></span><div class="row-actions"><button class="icon-button edit-note" data-id="<?= (int)$note["id"] ?>" title="Edit note">✎</button><form method="post" onsubmit="return confirm('Delete this note?')"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_note"><input type="hidden" name="id" value="<?= (int)$note["id"] ?>"><button class="icon-button danger" title="Delete note">×</button></form></div></div><h3><?= h($note["title"]) ?></h3><p><?= h($note["body"]) ?></p><?php if($note["attachment"]): ?><a class="note-image" href="<?= h($note["attachment"]) ?>" target="_blank"><img src="<?= h($note["attachment"]) ?>" alt="Attached study note image"></a><?php endif; ?><small class="note-date">Updated <?= h(date("M j, Y",strtotime($note["updated_at"]))) ?></small></article><?php endforeach; ?><div class="empty-state" id="noNotes" <?= $notes?"hidden":"" ?>><span>▧</span><b>No notes found.</b></div></div><p class="storage-footnote">Images are stored in this project's uploads folder, with a 2 MB limit per image. Keep the folder private when deploying.</p></section>

        <section class="view-section" data-section="progress" hidden><div class="stats-grid"><article class="stat-card"><div class="stat-head"><span>Task completion</span><i class="stat-symbol rose">✓</i></div><strong><?= $completedTasks ?><em>/<?= $totalTasks ?></em></strong><div class="progress-track"><span style="width:<?= $completion ?>%"></span></div><small><?= $completion ?>% complete</small></article><article class="stat-card"><div class="stat-head"><span>Focus sessions</span><i class="stat-symbol sage">◷</i></div><strong><?= $completedSessions ?></strong><small>Completed study blocks</small></article><article class="stat-card"><div class="stat-head"><span>Recorded study</span><i class="stat-symbol ochre">⌁</i></div><strong><?= floor($totalStudyMinutes/60) ?><em>h</em> <?= $totalStudyMinutes%60 ?><em>m</em></strong><small>Completed schedule + timer</small></article></div><div class="progress-grid"><article class="surface"><div class="section-top"><div><p class="eyebrow">BY CLASS</p><h2>Subject progress</h2></div></div><?php foreach($subjects as $subject): $related=array_values(array_filter($tasks,fn($task)=>(int)$task["subject_id"]===(int)$subject["id"]));$done=count(array_filter($related,fn($task)=>$task["status"]==="Completed"));$percent=count($related)?round($done/count($related)*100):0; ?><div class="subject-progress"><div><b><?= h($subject["name"]) ?></b><small><?= $done ?> of <?= count($related) ?> tasks</small></div><strong><?= $percent ?>%</strong><div class="progress-track"><span style="width:<?= $percent ?>%;--progress-color:<?= h($subject["color"]) ?>"></span></div></div><?php endforeach; ?><?php if(!$subjects): ?><div class="empty-inline">Add subjects to track progress by class.</div><?php endif; ?></article><article class="surface"><div class="section-top"><div><p class="eyebrow">WEEK AT A GLANCE</p><h2>Study activity</h2></div></div><div class="weekly-chart" id="weeklyChart"></div><p class="disclaimer">Bars show completed study minutes from the last seven days.</p></article></div></section>

        <section class="view-section" data-section="settings" hidden><article class="surface settings-surface"><div class="section-top"><div><p class="eyebrow">MAKE IT YOURS</p><h2>Planner settings</h2></div></div><form method="post" class="settings-form"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_settings"><label class="form-field">Your name<input name="student_name" maxlength="80" value="<?= h($settings["student_name"]) ?>" required></label><label class="form-field">Appearance<select name="theme"><option value="light" <?= $settings["theme"]==="light"?"selected":"" ?>>Light mode</option><option value="dark" <?= $settings["theme"]==="dark"?"selected":"" ?>>Dark mode</option></select></label><div class="settings-divider"><div><b>Pomodoro durations</b><small>Saved defaults for your focus timer.</small></div></div><label class="form-field">Focus minutes<input type="number" name="focus_minutes" min="1" max="180" value="<?= (int)$settings["focus_minutes"] ?>" required></label><label class="form-field">Short break minutes<input type="number" name="short_minutes" min="1" max="60" value="<?= (int)$settings["short_minutes"] ?>" required></label><label class="form-field">Long break minutes<input type="number" name="long_minutes" min="1" max="90" value="<?= (int)$settings["long_minutes"] ?>" required></label><div class="settings-actions"><button class="button button-primary">Save preferences</button></div></form></article><p class="disclaimer settings-disclaimer">Planner records are stored in MySQL on this server. Browser reminders are only visible while the app is open; add a notification service for scheduled alerts.</p></section>
        <section class="view-section" data-section="progress" hidden><article class="surface"><div class="section-top"><div><p class="eyebrow">NEXT MILESTONES</p><h2>Upcoming study goals</h2></div><a href="?view=schedule" data-view="schedule" class="text-link">Study plan →</a></div><?php $goalSessions=array_values(array_filter($upcomingSessions,fn($session)=>trim($session["goal"])!=="")); ?><?php if($goalSessions): foreach(array_slice($goalSessions,0,5) as $session): ?><div class="reminder-item"><span>↗</span><div><b><?= h($session["goal"]) ?></b><small><?= h($session["topic"]) ?> · <?= h($session["subject_name"]??"Independent study") ?></small></div><small><?= h(date("M j",strtotime($session["start_at"]))) ?></small></div><?php endforeach; else: ?><div class="empty-inline">Add a study goal to a scheduled session to track your next milestones.</div><?php endif; ?></article></section>
    </main>
</div>

<dialog class="form-dialog" id="taskDialog"><form method="post" enctype="multipart/form-data" id="taskForm"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_task"><input type="hidden" name="id" value=""><div class="dialog-head"><div><p class="eyebrow">TASK DETAILS</p><h2 id="taskDialogTitle">Add a task</h2></div><button type="button" class="icon-button dialog-close" data-close>×</button></div><div class="form-grid"><label class="form-field wide">Task title<input name="title" maxlength="180" required placeholder="e.g. Draft research proposal"></label><label class="form-field">Subject<select name="subject_id"><option value="">General / no subject</option><?php foreach($subjects as $subject): ?><option value="<?= (int)$subject["id"] ?>"><?= h($subject["name"]) ?></option><?php endforeach; ?></select></label><label class="form-field">Task type<select name="type"><option>Assignment</option><option>Project</option><option>Quiz</option><option>Exam</option><option>Reading</option><option>Other</option></select></label><label class="form-field">Priority<select name="priority"><option>Low</option><option selected>Medium</option><option>High</option></select></label><label class="form-field">Status<select name="status"><option>Pending</option><option>In Progress</option><option>Completed</option></select></label><label class="form-field wide">Due date &amp; time<input name="due_at" type="datetime-local" required></label><label class="form-field wide">Description<textarea name="description" rows="3" placeholder="What needs to get done?"></textarea></label><label class="form-field wide">Attach photo <input class="file-input" name="attachment" type="file" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG, or WEBP · up to 2 MB</small><span class="file-name"></span><img class="image-preview" alt="Selected attachment preview" hidden></label><label class="check-field wide"><input name="remove_attachment" type="checkbox" value="1"> Remove current attachment</label></div><p class="form-error" aria-live="polite"></p><div class="dialog-actions"><button class="button button-quiet" type="button" data-close>Cancel</button><button class="button button-primary">Save task</button></div></form></dialog>
<dialog class="form-dialog" id="subjectDialog"><form method="post"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_subject"><input type="hidden" name="id" value=""><div class="dialog-head"><div><p class="eyebrow">CLASS DETAILS</p><h2>Add a subject</h2></div><button type="button" class="icon-button dialog-close" data-close>×</button></div><div class="form-grid"><label class="form-field">Subject name<input name="name" required maxlength="100"></label><label class="form-field">Subject code<input name="code" required maxlength="30" placeholder="e.g. CS 201"></label><label class="form-field">Instructor<input name="instructor" maxlength="100"></label><label class="form-field">Schedule<input name="schedule_text" maxlength="120" placeholder="Tue / Thu · 9:00 AM"></label><label class="form-field">Color label<input name="color" type="color" value="#bf7184"></label><label class="form-field wide">Description<textarea name="description" rows="3"></textarea></label></div><div class="dialog-actions"><button class="button button-quiet" type="button" data-close>Cancel</button><button class="button button-primary">Save subject</button></div></form></dialog>
<dialog class="form-dialog" id="sessionDialog"><form method="post" id="sessionForm"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_session"><input type="hidden" name="id" value=""><div class="dialog-head"><div><p class="eyebrow">PROTECT YOUR STUDY TIME</p><h2>Schedule a session</h2></div><button type="button" class="icon-button dialog-close" data-close>×</button></div><div class="form-grid"><label class="form-field wide">Study topic<input name="topic" maxlength="180" required placeholder="e.g. Review chapter 4"></label><label class="form-field wide">Subject<select name="subject_id"><option value="">Independent study</option><?php foreach($subjects as $subject): ?><option value="<?= (int)$subject["id"] ?>"><?= h($subject["name"]) ?></option><?php endforeach; ?></select></label><label class="form-field">Date<input type="date" name="study_date" required></label><label class="form-field">Study goal<input name="goal" maxlength="160" placeholder="Finish practice problems"></label><label class="form-field">Start<input type="time" name="start_time" required></label><label class="form-field">End<input type="time" name="end_time" required></label><label class="form-field wide">Notes<textarea name="notes" rows="2"></textarea></label></div><p class="overlap-warning" id="overlapWarning" hidden>That time overlaps another scheduled study session. You can adjust the time before saving.</p><div class="dialog-actions"><button class="button button-quiet" type="button" data-close>Cancel</button><button class="button button-primary">Save session</button></div></form></dialog>
<dialog class="form-dialog" id="noteDialog"><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_note"><input type="hidden" name="id" value=""><div class="dialog-head"><div><p class="eyebrow">KEEP WHAT YOU LEARN</p><h2>Add a study note</h2></div><button type="button" class="icon-button dialog-close" data-close>×</button></div><div class="form-grid"><label class="form-field wide">Title<input name="title" maxlength="180" required></label><label class="form-field wide">Subject<select name="subject_id"><option value="">General</option><?php foreach($subjects as $subject): ?><option value="<?= (int)$subject["id"] ?>"><?= h($subject["name"]) ?></option><?php endforeach; ?></select></label><label class="form-field wide">Notes<textarea name="body" rows="7" required placeholder="Write your notes or reviewer here..."></textarea></label><label class="form-field wide">Attach photo <input class="file-input" name="attachment" type="file" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG, or WEBP · up to 2 MB</small><span class="file-name"></span><img class="image-preview" alt="Selected attachment preview" hidden></label><label class="check-field wide"><input name="remove_attachment" type="checkbox" value="1"> Remove current attachment</label></div><div class="dialog-actions"><button class="button button-quiet" type="button" data-close>Cancel</button><button class="button button-primary">Save note</button></div></form></dialog>
<script src="planner.js" defer></script>
</body></html>
