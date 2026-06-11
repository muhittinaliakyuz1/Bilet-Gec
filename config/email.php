<?php
// SMTP / E-posta yapılandırması
// Eğer yerel geliştirme ortamında doğrudan PHP `mail()` çalışmıyorsa,
// buraya SMTP bilgilerini girerek Windows üzerinde PHP'nin
// `SMTP` ve `smtp_port` ayarlarını kullandırabilirsiniz.
return [
    // Gmail (recommended): fill 'smtp_user' with your Gmail address and
    // 'smtp_pass' with the App Password you generate in Google Account settings.
    'use_smtp' => false,
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls',
    'smtp_auth' => true,
    'smtp_user' => 'krallari02@gmail.com',
    'smtp_pass' => 'lfhsjtkxmycxpwtn',
    'sendmail_from' => 'noreply@biletgec.com',
    'sendmail_name' => 'Bilet-Geç'
];
