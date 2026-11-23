<?php
/* ============================================================================
   VSM SCHOLARS — LEAD RECEIVER
   Upload next to index.html on cPanel. Every demo request (form + chatbot)
   lands here. It does four things:
     1. emails YOU the lead
     2. emails the STUDENT a confirmation of what happens next
     3. saves a CSV copy outside the website folder
     4. optionally pushes to a Google Sheet and WhatsApp Cloud API
   Only this file holds credentials — nothing sensitive sits in the HTML.
   ============================================================================ */

/* ----------------------------------------------------------------------------
   HOW LEADS GO OUT — three switches, nothing else to think about:

     1. EMAIL TO YOU          always on, goes to 'to' below
     2. EMAIL TO THE STUDENT  'reply_to_student' => true / false
                              false = your team does all the outreach manually,
                              and the website text stops promising a confirmation
     3. WHATSAPP              manual by default. You message the student from
                              your own phone (their WhatsApp thread opens for
                              them too). Automatic alerts only switch on if you
                              fill 'wa_token' + 'wa_phone_id'.

   A CSV copy is kept as a safety net in case an email ever bounces.
   Set 'csv' => '' to turn that off as well.
   ---------------------------------------------------------------------------- */

$CONFIG = [
  // ---- WHERE YOUR COPY GOES -------------------------------------------------
  'to'            => ['info@vsmgrowth.com'],          // add more: ['a@x.com','b@x.com']
  'subject'       => 'New demo request — VSM Scholars',

  /* The "from" address MUST be a real mailbox on your own domain, or Gmail and
     Outlook will bin the mail. In cPanel > Email Accounts create this address,
     then leave it here. Do NOT put a gmail.com address in 'from'. */
  'from'          => 'leads@vsmscholars.com',   // or 'leads@vsmgrowth.com' — whichever
  'from_name'     => 'VSM Scholars',            // domain's mail is live on this server

  // ---- STUDENT CONFIRMATION -------------------------------------------------
  /* true  = the student instantly gets the confirmation email (address, timings,
             what happens in the demo class, reference number)
     false = nothing automatic goes to the student; your team emails and messages
             them manually. The website text adapts on its own either way. */
  'reply_to_student' => true,
  'student_subject'  => 'Your free demo class at VSM Scholars — what happens next',

  // ---- YOUR DETAILS (used in both emails) -----------------------------------
  'phone'         => '+91 82795 74755',
  'phone_raw'     => '918279574755',
  'address'       => 'Aakansha Tower, 3rd Floor, Mangal Pandey Nagar, Meerut 250001',
  'hours'         => 'Mon–Sat, 10am–7pm',
  'map'           => 'https://share.google/tWNXKK3oV4GFiah31',
  'site'          => 'https://vsmscholars.com',

  // CSV backup. Default sits one folder ABOVE the website, so nobody can open it.
  'csv'           => __DIR__ . '/../vsm-scholars-leads.csv',

  // ---- META CONVERSIONS API (server-side, survives iOS and ad blockers) ------
  /* Events Manager > your pixel > Settings > Conversions API > Generate token.
     Use the SAME pixel ID as VSM_MKT.pixel in index.html — the page and this
     file send the same event_id, so Meta counts the lead once, not twice. */
  'capi_pixel'    => '',
  'capi_token'    => '',
  'capi_test'     => '',   // test_event_code while you verify, then blank it

  // ---- OPTIONAL, ALL OFF: blank means you handle it manually -----------------
  'sheet_webhook' => '',   // Google Apps Script web-app URL -> appends a row to a Sheet
  'wa_token'      => '',   // WhatsApp Cloud API token — blank = you message manually
  'wa_phone_id'   => '',   // WhatsApp Cloud API phone number ID
  'wa_notify'     => ['918279574755'],
  'wa_template'   => '',

  // ---- GUARDS ---------------------------------------------------------------
  'max_per_hour'  => 20,   // per IP
];

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($ok, $msg = '', $extra = []) {
  echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra));
  exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); out(false, 'POST only'); }

/* ---------- read the payload (JSON or form-encoded) ---------- */
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

function clean($v, $len = 300) {
  $v = is_string($v) ? $v : '';
  $v = preg_replace('/[\r\n\t]+/', ' ', strip_tags($v));   // kills header injection
  return trim(mb_substr($v, 0, $len));
}
function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if (clean($data['company'] ?? '') !== '') out(true, 'ok');          // honeypot

$name   = clean($data['name'] ?? '', 60);
$email  = clean($data['email'] ?? '', 120);
$phone  = preg_replace('/\D/', '', (string)($data['phone'] ?? ''));
if (strlen($phone) > 10) $phone = substr($phone, -10);
$course = clean($data['course'] ?? '', 60);
$eventId  = clean($data['event_id'] ?? '', 64);
$eventUrl = clean($data['event_source_url'] ?? '', 300);
$fbp      = clean($data['fbp'] ?? '', 120);
$fbc      = clean($data['fbc'] ?? '', 200);
$batch  = clean($data['batch']  ?? '', 40);
$stage  = clean($data['stage']  ?? '', 60);
$source = clean($data['source'] ?? 'website', 60);

if (mb_strlen($name) < 2)                   out(false, 'Please enter your name');
if (!preg_match('/^[6-9]\d{9}$/', $phone))  out(false, 'Enter a valid 10-digit mobile number');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) out(false, 'Enter a valid email address');

/* ---------- rate limit ---------- */
$ip   = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$lock = sys_get_temp_dir() . '/vsm_rate_' . md5($ip) . '.txt';
$hits = array_values(array_filter(
  file_exists($lock) ? explode(',', (string)file_get_contents($lock)) : [],
  function ($t) { return (int)$t > time() - 3600; }
));
if (count($hits) >= $CONFIG['max_per_hour']) { http_response_code(429); out(false, 'Too many requests. Please call us instead.'); }
$hits[] = time();
@file_put_contents($lock, implode(',', $hits), LOCK_EX);

/* ---------- attribution ---------- */
$attr = [];
foreach (['utm_source','utm_medium','utm_campaign','utm_content','utm_term','gclid','fbclid','wbraid','referrer','landing_page'] as $k) {
  $attr[$k] = clean($data[$k] ?? '', 300);
}
$when = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('d M Y, g:i A');
$ref  = 'VSMS-' . date('ymd') . '-' . strtoupper(substr(md5($phone . microtime()), 0, 4));
$phonePretty = '+91 ' . substr($phone, 0, 5) . ' ' . substr($phone, 5);

/* ---------- 1. CSV backup ---------- */
$row = [$ref, $when, $name, '+91' . $phone, $email, $course, $batch, $stage, $source,
        $attr['utm_source'], $attr['utm_campaign'], $attr['gclid'], $attr['fbclid'],
        $attr['landing_page'], $attr['referrer'], $ip];
$new = $CONFIG['csv'] && !file_exists($CONFIG['csv']);
if ($CONFIG['csv'] && ($fh = @fopen($CONFIG['csv'], 'a'))) {
  if (flock($fh, LOCK_EX)) {
    if ($new) fputcsv($fh, ['Ref','Received','Name','Phone','Email','Course','Batch','Status','Source',
                            'UTM source','Campaign','gclid','fbclid','Landing page','Referrer','IP']);
    fputcsv($fh, $row);
    flock($fh, LOCK_UN);
  }
  fclose($fh);
}

/* ---------- mail helper (multipart, so it lands in the inbox) ---------- */
function send_mail($to, $subject, $html, $text, $cfg, $replyTo = '', $replyName = '') {
  $bnd  = 'vsm_' . md5(uniqid('', true));
  $from = '=?UTF-8?B?' . base64_encode($cfg['from_name']) . '?= <' . $cfg['from'] . '>';
  $rt   = $replyTo
        ? ($replyName ? '=?UTF-8?B?' . base64_encode($replyName) . '?= <' . $replyTo . '>' : $replyTo)
        : $from;

  $h  = 'MIME-Version: 1.0' . "\r\n";
  $h .= 'From: ' . $from . "\r\n";
  $h .= 'Reply-To: ' . $rt . "\r\n";
  $h .= 'Return-Path: ' . $cfg['from'] . "\r\n";
  $h .= 'X-Mailer: VSM Scholars site' . "\r\n";
  $h .= 'Content-Type: multipart/alternative; boundary="' . $bnd . '"';

  $body  = "--$bnd\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $text . "\r\n\r\n";
  $body .= "--$bnd\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $html . "\r\n\r\n";
  $body .= "--$bnd--";

  $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  return @mail($to, $subj, $body, $h, '-f' . $cfg['from']);
}

function shell_html($inner, $cfg, $kicker) {
  return '<!DOCTYPE html><html><body style="margin:0;background:#f1ede3">'
    . '<div style="font-family:Arial,Helvetica,sans-serif;padding:24px 14px">'
    . '<div style="max-width:580px;margin:auto;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e6e2d8">'
    . '<div style="background:#0A0A0B;padding:20px 24px">'
    . '<div style="font-size:18px;font-weight:800;color:#FF5A24;letter-spacing:-.4px">VSM SCHOLARS</div>'
    . '<div style="font-size:10px;letter-spacing:3px;color:#A8A49A;margin-top:4px">THE DIGITAL SCHOOL</div>'
    . '<div style="font-size:11px;letter-spacing:2px;color:#FF7A45;margin-top:12px">' . esc($kicker) . '</div>'
    . '</div>' . $inner
    . '<div style="padding:16px 24px;background:#0A0A0B;color:#8C877D;font-size:11px;line-height:1.6">'
    . 'VSM Scholars, a VSM Marketing initiative<br>' . esc($cfg['address']) . '<br>'
    . esc($cfg['phone']) . ' &middot; ' . esc($cfg['hours'])
    . '</div></div></div></body></html>';
}

/* ---------- 2. your copy of the lead ---------- */
$waStudent = 'https://wa.me/91' . $phone . '?text=' . rawurlencode(
  "Hi $name, this is VSM Scholars, Meerut. Thanks for requesting a free demo class"
  . ($course ? " for $course" : "") . ". When would you like to come in?");

$fields = [
  'Name' => $name, 'WhatsApp' => $phonePretty, 'Email' => $email ?: '—',
  'Course' => $course ?: '—', 'Batch' => $batch ?: '—', 'Currently' => $stage ?: '—',
  'Came from' => $source, 'Campaign' => $attr['utm_campaign'] ?: '—',
  'Source / medium' => trim($attr['utm_source'] . ' / ' . $attr['utm_medium'], ' /') ?: 'direct',
  'Landing page' => $attr['landing_page'] ?: '—', 'Received' => $when, 'Ref' => $ref,
];
$rows = '';
foreach ($fields as $k => $v) {
  $rows .= '<tr><td style="padding:9px 24px;border-bottom:1px solid #eee;color:#666;font-size:13px;white-space:nowrap">'
         . esc($k) . '</td><td style="padding:9px 24px;border-bottom:1px solid #eee;font-weight:600;font-size:14px">'
         . esc($v) . '</td></tr>';
}
$teamHtml = shell_html(
  '<table style="width:100%;border-collapse:collapse">' . $rows . '</table>'
  . '<div style="padding:18px 24px;background:#faf9f6">'
  . '<a href="tel:+91' . $phone . '" style="display:inline-block;background:#FF4D17;color:#fff;text-decoration:none;padding:12px 20px;border-radius:99px;font-weight:700;font-size:14px;margin:0 8px 8px 0">Call ' . esc($name) . '</a>'
  . '<a href="' . esc($waStudent) . '" style="display:inline-block;background:#1FA855;color:#fff;text-decoration:none;padding:12px 20px;border-radius:99px;font-weight:700;font-size:14px;margin:0 8px 8px 0">WhatsApp</a>'
  . ($email ? '<a href="mailto:' . esc($email) . '" style="display:inline-block;background:#0A0A0B;color:#fff;text-decoration:none;padding:12px 20px;border-radius:99px;font-weight:700;font-size:14px;margin:0 0 8px 0">Email</a>' : '')
  . '<p style="margin:12px 0 0;font-size:12px;color:#888">Reply within 30 minutes during working hours.</p>'
  . '</div>', $CONFIG, 'NEW DEMO REQUEST');

$teamText = "New demo request\n\n";
foreach ($fields as $k => $v) $teamText .= "$k: $v\n";
$teamText .= "\nCall: +91$phone\nWhatsApp: $waStudent\n";

$sent = false;
foreach ($CONFIG['to'] as $to) {
  if (send_mail($to, $CONFIG['subject'] . ' — ' . $name . ' (' . ($course ?: 'course TBD') . ')',
                $teamHtml, $teamText, $CONFIG, $email ?: '', $email ? $name : '')) $sent = true;
}

/* ---------- 3. the student's confirmation ---------- */
$studentSent = false;
if ($email && $CONFIG['reply_to_student']) {
  $first = preg_split('/\s+/', $name)[0];
  $waUs  = 'https://wa.me/' . $CONFIG['phone_raw'] . '?text=' . rawurlencode(
           "Hi VSM Scholars, I have booked a free demo class. My reference is $ref.");

  $sRows = '';
  foreach (['Course' => $course ?: 'We will help you pick',
            'Batch preference' => $batch ?: 'Flexible',
            'Your reference' => $ref] as $k => $v) {
    $sRows .= '<tr><td style="padding:8px 0;color:#666;font-size:13px;white-space:nowrap">' . esc($k)
            . '</td><td style="padding:8px 0 8px 18px;font-weight:700;font-size:14px">' . esc($v) . '</td></tr>';
  }

  $li = function ($t) { return '<tr><td style="vertical-align:top;padding:5px 10px 5px 0;color:#FF4D17;font-weight:800">&#10003;</td>'
                             . '<td style="padding:5px 0;font-size:14.5px;line-height:1.55;color:#2E2B25">' . $t . '</td></tr>'; };

  $studentHtml = shell_html(
      '<div style="padding:24px 24px 6px">'
    . '<p style="margin:0 0 10px;font-size:19px;font-weight:700;color:#0A0A0B">Hi ' . esc($first) . ', your demo class request is in.</p>'
    . '<p style="margin:0 0 18px;font-size:14.5px;line-height:1.6;color:#555">Our team will call you on <b>' . esc($phonePretty)
    . '</b> within 30 minutes during working hours (' . esc($CONFIG['hours']) . ') to fix the day and time. Nothing to pay, nothing to sign.</p>'
    . '<table style="width:100%;border-collapse:collapse;background:#faf9f6;border:1px solid #eee;border-radius:10px">'
    . '<tr><td style="padding:14px 18px"><table style="width:100%;border-collapse:collapse">' . $sRows . '</table></td></tr></table>'
    . '<p style="margin:22px 0 8px;font-size:11px;letter-spacing:2px;color:#999;font-weight:700">IN THE DEMO CLASS</p>'
    . '<table style="width:100%;border-collapse:collapse">'
    . $li('You sit through <b>one full live class</b> — listen and build along, not a sales pitch.')
    . $li('You meet the <b>trainer who actually runs client work</b>, and see the batch and the campus.')
    . $li('You take home a <b>module-wise roadmap</b> for your course.')
    . $li('Only then do you get the <b>fee structure and payment plan in writing</b>.')
    . '</table>'
    . '<p style="margin:22px 0 8px;font-size:11px;letter-spacing:2px;color:#999;font-weight:700">WHERE TO COME</p>'
    . '<p style="margin:0 0 6px;font-size:14.5px;line-height:1.6;color:#2E2B25">' . esc($CONFIG['address']) . '<br>'
    . '<span style="color:#666">' . esc($CONFIG['hours']) . '</span></p>'
    . '<p style="margin:0 0 18px"><a href="' . esc($CONFIG['map']) . '" style="color:#FF4D17;font-size:14px;font-weight:700;text-decoration:none">Open in Google Maps &rarr;</a></p>'
    . '<p style="margin:0 0 18px;font-size:13.5px;line-height:1.6;color:#666;background:#f6f4ef;border-left:3px solid #FF4D17;padding:12px 14px">'
    . 'Bring your own laptop if you have one — you will be doing the work yourself. '
    . 'And to be straight with you: we do not promise a job. We give you live client projects, a portfolio, '
    . 'interview preparation and referrals. The work is what gets you hired.</p>'
    . '</div>'
    . '<div style="padding:4px 24px 22px">'
    . '<a href="' . esc($waUs) . '" style="display:inline-block;background:#1FA855;color:#fff;text-decoration:none;padding:13px 22px;border-radius:99px;font-weight:700;font-size:14px;margin:0 8px 8px 0">WhatsApp us</a>'
    . '<a href="tel:' . esc($CONFIG['phone_raw']) . '" style="display:inline-block;background:#0A0A0B;color:#fff;text-decoration:none;padding:13px 22px;border-radius:99px;font-weight:700;font-size:14px;margin:0 0 8px 0">Call ' . esc($CONFIG['phone']) . '</a>'
    . '<p style="margin:12px 0 0;font-size:11.5px;color:#999">You are getting this because you requested a free demo class at vsmscholars.com. '
    . 'If that was not you, just ignore this email.</p>'
    . '</div>', $CONFIG, 'DEMO CLASS REQUESTED');

  $studentText = "Hi $first, your demo class request is in.\n\n"
    . "Our team will call you on $phonePretty within 30 minutes during working hours ({$CONFIG['hours']}) to fix the day and time.\n\n"
    . "Course: " . ($course ?: 'We will help you pick') . "\n"
    . "Batch preference: " . ($batch ?: 'Flexible') . "\n"
    . "Your reference: $ref\n\n"
    . "IN THE DEMO CLASS\n"
    . "- One full live class: listen and build along, not a sales pitch.\n"
    . "- Meet the trainer who actually runs client work; see the batch and the campus.\n"
    . "- Take home a module-wise roadmap for your course.\n"
    . "- Only then do you get the fee structure and payment plan in writing.\n\n"
    . "WHERE TO COME\n{$CONFIG['address']}\n{$CONFIG['hours']}\nMap: {$CONFIG['map']}\n\n"
    . "Bring your own laptop if you have one. And to be straight with you: we do not promise a job. "
    . "We give you live client projects, a portfolio, interview preparation and referrals.\n\n"
    . "WhatsApp: $waUs\nCall: {$CONFIG['phone']}\n\n"
    . "You are getting this because you requested a free demo class at vsmscholars.com.\n";

  $studentSent = send_mail($email, $CONFIG['student_subject'], $studentHtml, $studentText, $CONFIG);
}

/* ---------- 4. Google Sheet (optional) ---------- */
function post_json($url, $payload, $headers = []) {
  if (!$url || !function_exists('curl_init')) return false;
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_FOLLOWLOCATION => true,
  ]);
  $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return $code >= 200 && $code < 300 ? $res : false;
}
if ($CONFIG['sheet_webhook']) {
  post_json($CONFIG['sheet_webhook'], array_merge([
    'ref' => $ref, 'received' => $when, 'name' => $name, 'phone' => '+91' . $phone, 'email' => $email,
    'course' => $course, 'batch' => $batch, 'stage' => $stage, 'source' => $source,
  ], $attr));
}

/* ---------- 5. Meta Conversions API (optional) ---------- */
$capiSent = false;
if ($CONFIG['capi_pixel'] && $CONFIG['capi_token']) {
  $h = function ($v) { return $v === '' ? null : hash('sha256', strtolower(trim($v))); };
  $parts = preg_split('/\s+/', $name);
  $user = array_filter([
    'em'  => $email ? [$h($email)] : null,
    'ph'  => [$h('91' . $phone)],
    'fn'  => [$h($parts[0] ?? '')],
    'ln'  => count($parts) > 1 ? [$h(end($parts))] : null,
    'ct'  => [$h('meerut')],
    'st'  => [$h('up')],
    'country' => [$h('in')],
    'client_ip_address' => $ip,
    'client_user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 400),
    'fbp' => $fbp ?: null,
    'fbc' => $fbc ?: null,
  ]);
  $event = [
    'event_name'       => 'Lead',
    'event_time'       => time(),
    'event_id'         => $eventId ?: $ref,
    'event_source_url' => $eventUrl ?: ($attr['landing_page'] ?: 'https://vsmscholars.com/'),
    'action_source'    => 'website',
    'user_data'        => $user,
    'custom_data'      => array_filter([
      'content_name'  => $course ?: 'Demo class',
      'content_category' => 'Course enquiry',
      'value'         => 1,
      'currency'      => 'INR',
      'lead_source'   => $source,
    ]),
  ];
  $payload = ['data' => [$event]];
  if ($CONFIG['capi_test']) $payload['test_event_code'] = $CONFIG['capi_test'];
  $capiSent = (bool) post_json(
    'https://graph.facebook.com/v21.0/' . $CONFIG['capi_pixel'] . '/events?access_token=' . urlencode($CONFIG['capi_token']),
    $payload);
}

/* ---------- 6. WhatsApp alert to the team (optional) ---------- */
$waSent = false;
if ($CONFIG['wa_token'] && $CONFIG['wa_phone_id']) {
  $text = "*New demo request*\n\nName: $name\nWhatsApp: +91$phone\nEmail: " . ($email ?: '—')
        . "\nCourse: " . ($course ?: '—') . "\nBatch: " . ($batch ?: '—')
        . "\nCampaign: " . ($attr['utm_campaign'] ?: 'direct') . "\nRef: $ref";
  foreach ($CONFIG['wa_notify'] as $num) {
    $body = $CONFIG['wa_template']
      ? ['messaging_product'=>'whatsapp','to'=>$num,'type'=>'template',
         'template'=>['name'=>$CONFIG['wa_template'],'language'=>['code'=>'en'],
           'components'=>[['type'=>'body','parameters'=>[
              ['type'=>'text','text'=>$name],['type'=>'text','text'=>'+91'.$phone],
              ['type'=>'text','text'=>$course ?: 'not chosen']]]]]]
      : ['messaging_product'=>'whatsapp','to'=>$num,'type'=>'text','text'=>['preview_url'=>false,'body'=>$text]];
    if (post_json('https://graph.facebook.com/v21.0/' . $CONFIG['wa_phone_id'] . '/messages', $body,
                  ['Authorization: Bearer ' . $CONFIG['wa_token']])) $waSent = true;
  }
}

out(true, 'Lead received', ['ref' => $ref, 'emailed' => $sent, 'confirmation' => $studentSent,
                            'whatsapp' => $waSent, 'capi' => $capiSent]);
