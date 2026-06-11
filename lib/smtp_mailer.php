<?php
/**
 * Simple SMTP mailer (single-file)
 * Supports AUTH LOGIN and optional STARTTLS/SSL.
 * Not a full replacement for PHPMailer but sufficient for basic SMTP AUTH sending.
 */
class SimpleSMTPMailer
{
    private $socket;
    private $error = '';

    public function getError(): string
    {
        return $this->error;
    }

    public function send(array $config, string $from, string $fromName, string $to, string $subject, string $html): bool
    {
        $host = $config['smtp_host'] ?? '';
        $port = $config['smtp_port'] ?? 587;
        $encryption = $config['smtp_encryption'] ?? '';
        $user = $config['smtp_user'] ?? '';
        $pass = $config['smtp_pass'] ?? '';

        if (empty($host) || empty($to)) {
            $this->error = 'Eksik SMTP host veya hedef adres.';
            return false;
        }

        $remote = (($encryption === 'ssl') ? 'ssl://' : '') . $host . ':' . $port;
        $this->socket = @stream_socket_client($remote, $errno, $errstr, 30);
        if (!$this->socket) {
            $this->error = "Bağlantı hatası: $errstr ($errno)";
            return false;
        }

        stream_set_timeout($this->socket, 30);
        $banner = $this->getLines();

        $this->sendCmd('EHLO ' . (getenv('HOSTNAME') ?: gethostname()));
        $this->getLines();

        if ($encryption === 'tls') {
            $this->sendCmd('STARTTLS');
            $res = $this->getLines();
            if (strpos($res, '220') === 0) {
                stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->sendCmd('EHLO ' . (getenv('HOSTNAME') ?: gethostname()));
                $this->getLines();
            }
        }

        if (!empty($user)) {
            $this->sendCmd('AUTH LOGIN');
            $this->getLines();
            $this->sendCmd(base64_encode($user));
            $this->getLines();
            $this->sendCmd(base64_encode($pass));
            $authRes = $this->getLines();
            if (strpos($authRes, '235') !== 0) {
                $this->error = 'SMTP kimlik doğrulaması başarısız: ' . trim($authRes);
                return false;
            }
        }

        $this->sendCmd('MAIL FROM:<' . $from . '>');
        $this->getLines();
        $this->sendCmd('RCPT TO:<' . $to . '>');
        $this->getLines();
        $this->sendCmd('DATA');
        $this->getLines();

        $headers = [];
        $headers[] = 'From: ' . $fromName . ' <' . $from . '>';
        $headers[] = 'To: ' . $to;
        $headers[] = 'Subject: ' . $subject;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';

        $data = implode("\r\n", $headers) . "\r\n\r\n" . $html . "\r\n.\r\n";
        $this->sendCmd($data);
        $this->getLines();

        $this->sendCmd('QUIT');
        $this->getLines();

        fclose($this->socket);
        return true;
    }

    private function sendCmd(string $cmd): void
    {
        fwrite($this->socket, $cmd . "\r\n");
    }

    private function getLines(): string
    {
        $data = '';
        while ($str = fgets($this->socket, 515)) {
            $data .= $str;
            // If the line does not have '-' at position 3, it's the last in the response
            if (isset($str[3]) && $str[3] === ' ') break;
        }
        return $data;
    }
}
