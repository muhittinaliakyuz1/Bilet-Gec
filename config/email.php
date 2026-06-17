<?php
// SMTP / E-posta yapılandırması
// Eğer yerel geliştirme ortamında doğrudan PHP `mail()` çalışmıyorsa,
// buraya SMTP bilgilerini girerek Windows üzerinde PHP'nin
// `SMTP` ve `smtp_port` ayarlarını kullandırabilirsiniz.
$env = function (string $key, $default = null) {
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }
    return $_ENV[$key] ?? $default;
};

return [
    'use_smtp' => filter_var($env('MAIL_USE_SMTP', true), FILTER_VALIDATE_BOOLEAN),
    'smtp_host' => $env('MAIL_SMTP_HOST', 'smtp.gmail.com'),
    'smtp_port' => (int) $env('MAIL_SMTP_PORT', 587),
    'smtp_encryption' => $env('MAIL_SMTP_ENCRYPTION', 'tls'),
    'smtp_auth' => filter_var($env('MAIL_SMTP_AUTH', true), FILTER_VALIDATE_BOOLEAN),
    'smtp_user' => $env('MAIL_SMTP_USER', 'krallari02@gmail.com'),
    'smtp_pass' => $env('MAIL_SMTP_PASS', 'lfhsjtkxmycxpwtn'),
    'sendmail_from' => $env('MAIL_SENDFROM', 'noreply@biletgec.com'),
    'sendmail_name' => $env('MAIL_SENDNAME', 'Bilet-Geç'),
];
