<?php
// api.php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ===== بيانات قاعدة البيانات ===== */
$servername = "localhost";
$username   = "u105814917_schedule666";
$password   = "MyDb_Pass2025!77";
$dbname     = "u105814917_schedule666";

/* ===== التقويم الدراسي ===== */
date_default_timezone_set('Asia/Riyadh');
$ACADEMIC_START = '2025-08-17'; // أول أحد في السنة الدراسية
$WEEK_LENGTH_DAYS = 7;

/* ===== الاتصال ===== */
$conn = @new mysqli($servername, $username, $password, $dbname);
if ($conn && !$conn->connect_error) { $conn->set_charset("utf8mb4"); }
else {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'فشل الاتصال بقاعدة البيانات: '.($conn?$conn->connect_error:'اتصال غير معروف')]);
  exit;
}

/* ===== دوال مساعدة ===== */
function json_fail($msg,$code=400){ http_response_code($code); echo json_encode(['success'=>false,'message'=>$msg]); exit; }
function clean_str($v){ return trim((string)$v); }
function int_or_default($v,$d){ $n=filter_var($v,FILTER_VALIDATE_INT); return ($n!==false && $n>0)?$n:$d; }
function ensure_upload_dir($dir){ if(!is_dir($dir)) @mkdir($dir,0775,true); if(!is_writable($dir)) @chmod($dir,0775); return is_dir($dir)&&is_writable($dir); }

/* ===== حساب الأسبوع الحالي (ينتقل تلقائيًا يوم الجمعة) ===== */
function get_week_info($startYmd,$week_length_days=7){
  $tz = new DateTimeZone('Asia/Riyadh');
  $start = DateTime::createFromFormat('Y-m-d',$startYmd,$tz);
  if(!$start){
    return ['current_week'=>1,'week_start'=>date('Y-m-d'),'week_end'=>date('Y-m-d'),'week_number'=>1];
  }
  $today = new DateTime('today',$tz);
  $cut   = clone $today;           // نقطع الأسبوع صباح الجمعة
  $cut->modify('+2 days');         // الجمعة تُعتبر بداية الأسبوع التالي
  $diffDays = (int)$start->diff($cut)->format('%r%a');
  $weekNum  = (int)floor($diffDays/$week_length_days)+1;
  if($weekNum<1) $weekNum=1;

  $currentWeekStart = clone $start;
  $currentWeekStart->modify('+'.(($weekNum-1)*$week_length_days).' days');
  $currentWeekEnd = clone $currentWeekStart;
  $currentWeekEnd->modify('+4 days'); // أحد→خميس

  return [
    'current_week'=>$weekNum,
    'week_start'=>$currentWeekStart->format('Y-m-d'),
    'week_end'=>$currentWeekEnd->format('Y-m-d'),
    'week_number'=>$weekNum
  ];
}

/* ===== مسارات الرفع ===== */
$BASE_URL        = rtrim((isset($_SERVER['REQUEST_SCHEME'])?$_SERVER['REQUEST_SCHEME']:'https').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME']), '/\\');
$UPLOAD_DIR      = __DIR__.'/uploads';
$UPLOAD_BASE_URL = $BASE_URL.'/uploads';
ensure_upload_dir($UPLOAD_DIR);

/* ===== اختيار الإجراء ===== */
$action = $_GET['action'] ?? '';

/* ===== GET: استرجاع البيانات ===== */
if ($action === 'get_data' && $_SERVER['REQUEST_METHOD']==='GET'){
  $grade   = int_or_default($_GET['grade'] ?? 1, 1);
  $section = clean_str($_GET['section'] ?? 'أ');
  $requestedWeek = isset($_GET['week']) ? int_or_default($_GET['week'], null) : null;

  $winfo = get_week_info($ACADEMIC_START,$WEEK_LENGTH_DAYS);
  $currentWeek = $winfo['current_week'];
  $week_to_fetch = $requestedWeek ?: $currentWeek;

  // الدروس
  $lessons=[];
  $stmt=$conn->prepare("SELECT day_name,period,subject_name,lesson_title,lesson_content,homework,notes
                        FROM lessons WHERE grade=? AND section=? AND week=?
                        ORDER BY FIELD(day_name,'sunday','monday','tuesday','wednesday','thursday'),period ASC");
  $stmt->bind_param("isi",$grade,$section,$week_to_fetch);
  $stmt->execute(); $res=$stmt->get_result();
  while($row=$res->fetch_assoc()){
    $lessons[$row['day_name']][(int)$row['period']] = [
      'subject_name'=>$row['subject_name'],
      'lesson_title'=>$row['lesson_title'],
      'lesson_content'=>$row['lesson_content'],
      'homework'=>$row['homework'],
      'notes'=>$row['notes']
    ];
  }
  $stmt->close();

  // الإعلانات + المرفقات
  $announcements=[];
  $q=$conn->query("SELECT id,title,content,purpose,week,DATE_FORMAT(created_at,'%d/%m/%Y') AS date
                   FROM announcements ORDER BY id DESC");
  while($row=$q->fetch_assoc()){
    $row['attachments']=[];
    $aid=(int)$row['id'];
    $qa=$conn->prepare("SELECT id,file_name,file_path,file_type,file_size
                        FROM announcement_attachments WHERE announcement_id=? ORDER BY id ASC");
    $qa->bind_param("i",$aid); $qa->execute(); $ra=$qa->get_result();
    while($ar=$ra->fetch_assoc()){
      $fp=$ar['file_path']; // في القاعدة نخزن "اسم الملف" فقط
      if(strpos($fp,'http://')!==0 && strpos($fp,'https://')!==0){
        $fp = $UPLOAD_BASE_URL.'/'.ltrim($fp,'/');
      }
      $row['attachments'][]=[
        'id'=>(int)$ar['id'],
        'file_name'=>$ar['file_name'],
        'file_path'=>$fp,
        'file_type'=>$ar['file_type'],
        'file_size'=>(int)$ar['file_size']
      ];
    }
    $qa->close();
    $announcements[]=$row;
  }

  echo json_encode([
    'success'=>true,
    'requested_week'=>$requestedWeek,
    'current_week'=>$currentWeek,
    'week_context'=>[
      'week_number'=>$winfo['week_number'],
      'week_start'=>$winfo['week_start'],
      'week_end'=>$winfo['week_end']
    ],
    'lessons'=>$lessons,
    'announcements'=>$announcements
  ]);
  exit;
}

/* ===== POST: حفظ حصة ===== */
if ($action==='save_lesson' && $_SERVER['REQUEST_METHOD']==='POST'){
  $data=json_decode(file_get_contents('php://input'),true);
  foreach(['grade','section','week','day','period','subject','title'] as $k){
    if(!isset($data[$k])||$data[$k]===''){ json_fail("حقل {$k} مطلوب.",400); }
  }

  $grade=(int)$data['grade'];
  $section=clean_str($data['section']);
  $week=(int)$data['week'];
  $day=clean_str($data['day']);
  $period=(int)$data['period'];
  $subject=clean_str($data['subject']);
  $title=clean_str($data['title']);
  $content=isset($data['content'])?clean_str($data['content']):'';
  $homework=isset($data['homework'])?clean_str($data['homework']):'';
  $notes=isset($data['notes'])?clean_str($data['notes']):'';

  $sql="INSERT INTO lessons (grade,section,week,day_name,period,subject_name,lesson_title,lesson_content,homework,notes)
        VALUES (?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          subject_name=VALUES(subject_name),
          lesson_title=VALUES(lesson_title),
          lesson_content=VALUES(lesson_content),
          homework=VALUES(homework),
          notes=VALUES(notes)";
  $stmt=$conn->prepare($sql);
  $stmt->bind_param("isisisssss",$grade,$section,$week,$day,$period,$subject,$title,$content,$homework,$notes);
  if($stmt->execute()){ echo json_encode(['success'=>true,'message'=>'تم حفظ الحصة بنجاح']); }
  else { json_fail('خطأ في الحفظ: '.$stmt->error,500); }
  $stmt->close(); exit;
}

/* ===== POST: حذف حصة محددة ===== */
if ($action==='delete_lesson' && $_SERVER['REQUEST_METHOD']==='POST'){
  $data=json_decode(file_get_contents('php://input'),true);
  foreach(['grade','section','week','day','period'] as $k){
    if(!isset($data[$k])||$data[$k]===''){ json_fail("حقل {$k} مطلوب.",400); }
  }
  $grade=(int)$data['grade'];
  $section=clean_str($data['section']);
  $week=(int)$data['week'];
  $day=clean_str($data['day']);
  $period=(int)$data['period'];

  $stmt=$conn->prepare("DELETE FROM lessons
                        WHERE grade=? AND section=? AND week=? AND day_name=? AND period=? LIMIT 1");
  $stmt->bind_param("isisi",$grade,$section,$week,$day,$period);
  if($stmt->execute()){ echo json_encode(['success'=>true,'message'=>'تم حذف الحصة']); }
  else { json_fail('خطأ في حذف الحصة: '.$stmt->error,500); }
  $stmt->close(); exit;
}

/* ===== POST: حفظ إعلان + مرفقات + الغرض ===== */
if ($action==='save_announcement' && $_SERVER['REQUEST_METHOD']==='POST'){
  $title   = isset($_POST['title'])?clean_str($_POST['title']):'';
  $content = isset($_POST['content'])?clean_str($_POST['content']):'';
  $purpose = isset($_POST['purpose'])?clean_str($_POST['purpose']):'';
  $grade   = isset($_POST['grade'])?clean_str($_POST['grade']):null;
  $section = isset($_POST['section'])?clean_str($_POST['section']):null;
  $week    = isset($_POST['week'])?int_or_default($_POST['week'],null):null;

  if($title===''||$content===''){ json_fail('العنوان والمحتوى مطلوبان.',400); }
  if(!$week){ $week=get_week_info($ACADEMIC_START,$WEEK_LENGTH_DAYS)['current_week']; }

  $stmt=$conn->prepare("INSERT INTO announcements (title,content,purpose,week,grade,section)
                        VALUES (?,?,?,?,?,?)");
  $stmt->bind_param("ssssss",$title,$content,$purpose,$week,$grade,$section);
  if(!$stmt->execute()){ json_fail('خطأ في إضافة الإعلان: '.$stmt->error,500); }
  $announcement_id=$conn->insert_id; $stmt->close();

  // رفع الملفات
  $allowedMime=[
    'image/jpeg','image/jpg','image/png','image/gif','image/webp','application/pdf',
    'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain','application/zip','application/x-zip-compressed'
  ];
  $maxSizeBytes=20*1024*1024;
  $warnings=[]; $savedAttachments=[];

  if(!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])){
    if(!ensure_upload_dir($UPLOAD_DIR)){ $warnings[]='تعذّر إنشاء مجلد الرفع على الخادم.'; }
    else{
      $count=count($_FILES['attachments']['name']);
      for($i=0;$i<$count;$i++){
        $name=$_FILES['attachments']['name'][$i];
        $type=$_FILES['attachments']['type'][$i];
        $tmp=$_FILES['attachments']['tmp_name'][$i];
        $err=$_FILES['attachments']['error'][$i];
        $size=(int)$_FILES['attachments']['size'][$i];

        if($err!==UPLOAD_ERR_OK){ $warnings[]="تعذّر رفع الملف: {$name} (رمز {$err})"; continue; }
        if($size>$maxSizeBytes){ $warnings[]="حجم كبير: {$name}"; continue; }

        if($type && !in_array($type,$allowedMime)){
          $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
          $wl=['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','txt','zip'];
          if(!in_array($ext,$wl)){ $warnings[]="نوع غير مدعوم: {$name}"; continue; }
        }

        $ext=pathinfo($name,PATHINFO_EXTENSION);
        $safeBase=preg_replace('/[^A-Za-z0-9_\-\.]+/','-', pathinfo($name,PATHINFO_FILENAME));
        $newFile=$safeBase.'-'.$announcement_id.'-'.uniqid().($ext?('.'.$ext):'');
        $destPath=$UPLOAD_DIR.'/'.$newFile;

        if(!@move_uploaded_file($tmp,$destPath)){ $warnings[]="تعذّر حفظ الملف: {$name}"; continue; }

        $relPath=$newFile; // نخزن اسم الملف فقط
        $mimeToSave=$type ?: (function($p){ $finfo=finfo_open(FILEINFO_MIME_TYPE); $m=finfo_file($finfo,$p); finfo_close($finfo); return $m?:'application/octet-stream'; })($destPath);

        $ins=$conn->prepare("INSERT INTO announcement_attachments (announcement_id,file_name,file_path,file_type,file_size)
                             VALUES (?,?,?,?,?)");
        $ins->bind_param("isssi",$announcement_id,$name,$relPath,$mimeToSave,$size);
        if($ins->execute()){
          $savedAttachments[]=[
            'id'=>$conn->insert_id,
            'file_name'=>$name,
            'file_path'=>$UPLOAD_BASE_URL.'/'.$newFile,
            'file_type'=>$mimeToSave,
            'file_size'=>$size
          ];
        } else { $warnings[]="فشل حفظ بيانات الملف: {$name}"; @unlink($destPath); }
        $ins->close();
      }
    }
  }

  echo json_encode([
    'success'=>true,
    'id'=>$announcement_id,
    'date'=>date('d/m/Y'),
    'week'=>$week,
    'attachments'=>$savedAttachments,
    'attachment_warnings'=>$warnings
  ]);
  exit;
}

/* ===== POST: حذف إعلان ===== */
if ($action==='delete_announcement' && $_SERVER['REQUEST_METHOD']==='POST'){
  $data=json_decode(file_get_contents('php://input'),true);
  if(empty($data['id'])){ json_fail('معرّف الإعلان مطلوب.',400); }
  $id=(int)$data['id'];

  $sel=$conn->prepare("SELECT file_path FROM announcement_attachments WHERE announcement_id=?");
  $sel->bind_param("i",$id); $sel->execute(); $rs=$sel->get_result(); $paths=[];
  while($r=$rs->fetch_assoc()){ $paths[]=$r['file_path']; }
  $sel->close();

  $delA=$conn->prepare("DELETE FROM announcement_attachments WHERE announcement_id=?");
  $delA->bind_param("i",$id); $delA->execute(); $delA->close();

  foreach($paths as $rel){
    $full=$UPLOAD_DIR.'/'.ltrim($rel,'/');
    if(is_file($full)) @unlink($full);
  }

  $stmt=$conn->prepare("DELETE FROM announcements WHERE id=?");
  $stmt->bind_param("i",$id);
  if($stmt->execute()){ echo json_encode(['success'=>true,'message'=>'تم حذف الإعلان']); }
  else { json_fail('خطأ في حذف الإعلان: '.$stmt->error,500); }
  $stmt->close(); exit;
}

/* ===== طلب غير معروف ===== */
http_response_code(404);
echo json_encode(['success'=>false,'message'=>'طلب غير معروف']);
$conn->close();
