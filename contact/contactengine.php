<?php

$EmailFrom = "info@artiroof.co.uk";
$EmailTo = "cpo@artiroof.co.uk";
$Subject = "Contact form on artiroof.co.uk";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: index.html');
  exit;
}

function field($name) {
  // Strip CR/LF so nothing can be smuggled into the mail headers.
  return trim(str_replace(["\r", "\n"], ' ', $_POST[$name] ?? ''));
}

$Name = field('Name');
$City = field('City');
$Tel = field('Tel');
$Fax = field('Fax');
$Email = field('Email');
$Message = trim($_POST['Message'] ?? '');

// Honeypot: real visitors never see or fill the "website" field.
if (($_POST['website'] ?? '') !== '') {
  header('Location: contactthanks.php');
  exit;
}

if ($Name === '' || $Message === '' || !filter_var($Email, FILTER_VALIDATE_EMAIL)) {
  header('Location: error.htm');
  exit;
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

$success = mail($EmailTo, $Subject, $Body, $headers, "-f$EmailFrom");

header('Location: ' . ($success ? 'contactthanks.php' : 'error.htm'));
