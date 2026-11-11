<?php

$EmailFrom = "info@artiroof.co.uk";
$EmailTo = "cpo@artiroof.co.uk";
$Subject = "Contact form on artiroof.co.uk";

// Limits shared with the maxlength attributes in index.html.
const MAX_FIELD = 200;
const MAX_EMAIL = 254;
const MAX_MESSAGE = 5000;
// Per visitor IP: at most RATE_LIMIT sends in RATE_WINDOW seconds.
const RATE_LIMIT = 5;
const RATE_WINDOW = 3600;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header('Location: index.html');
  exit;
}

function redirect($page) {
  header("Location: $page", true, 303);
  exit;
}

// Only plain strings are accepted; arrays (Name[]=...) count as empty.
function input($name) {
  $value = $_POST[$name] ?? '';
  return is_string($value) ? trim($value) : '';
}

// Single-line fields: strip CR/LF so nothing can be smuggled into mail headers.
function line($name) {
  return str_replace(["\r", "\n"], ' ', input($name));
}

// Records this send and reports whether the IP is still under the limit.
// State lives in PHP-FPM's private temp dir, keyed by a hash of the IP.
function within_rate_limit($ip) {
  $file = sys_get_temp_dir() . '/artiroof-contact-rate.json';
  $fh = fopen($file, 'c+');
  if ($fh === false) {
    return true; // fail open: never block real enquiries because of a temp-file problem
  }
  flock($fh, LOCK_EX);
  $data = json_decode(stream_get_contents($fh), true) ?: [];
  $now = time();
  foreach ($data as $key => $times) {
    $data[$key] = array_values(array_filter($times, fn($t) => $t > $now - RATE_WINDOW));
    if (!$data[$key]) unset($data[$key]);
  }
  $key = hash('sha256', $ip);
  $allowed = count($data[$key] ?? []) < RATE_LIMIT;
  if ($allowed) {
    $data[$key][] = $now;
  }
  ftruncate($fh, 0);
  rewind($fh);
  fwrite($fh, json_encode($data));
  flock($fh, LOCK_UN);
  fclose($fh);
  return $allowed;
}

function log_event($level, $event, $fields) {
  openlog('artiroof-contact', LOG_PID, LOG_MAIL);
  syslog($level, $event . ' ' . json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  closelog();
}

// Honeypot: real visitors never see or fill the "website" field.
if (input('website') !== '') {
  redirect('contactthanks.php');
}

$Name = line('Name');
$City = line('City');
$Tel = line('Tel');
$Fax = line('Fax');
$Email = line('Email');
$Message = input('Message');

$valid = $Name !== '' && $Message !== ''
  && filter_var($Email, FILTER_VALIDATE_EMAIL)
  && strlen($Email) <= MAX_EMAIL
  && mb_strlen($Message) <= MAX_MESSAGE
  && max(array_map('mb_strlen', [$Name, $City, $Tel, $Fax])) <= MAX_FIELD;

if (!$valid) {
  redirect('error.htm');
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!within_rate_limit($ip)) {
  log_event(LOG_WARNING, 'rate_limited', ['ip' => $ip]);
  redirect('error.htm');
}

$Body = "Name: $Name\n"
      . "City: $City\n"
      . "Tel: $Tel\n"
      . "Fax: $Fax\n"
      . "Email: $Email\n"
      . "Message: $Message\n";

$headers = "From: Artisan Roofing website <$EmailFrom>\r\n"
         . "Reply-To: $Email\r\n"
         . "Content-Type: text/plain; charset=UTF-8";

if (mail($EmailTo, $Subject, $Body, $headers, "-f$EmailFrom")) {
  redirect('contactthanks.php');
}

// Keep the full submission so a failed send can be recovered from the journal.
log_event(LOG_ERR, 'send_failed', [
  'name' => $Name, 'city' => $City, 'tel' => $Tel, 'fax' => $Fax,
  'email' => $Email, 'message' => $Message, 'ip' => $ip,
]);
redirect('error.htm');
