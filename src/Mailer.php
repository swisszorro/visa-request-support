<?php

declare(strict_types=1);

namespace BWC\Visa;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use Psr\Log\LoggerInterface;

/**
 * Sends the generated invitation letter (PDF/DOCX) as an email attachment via SMTP.
 * Configured through MAIL_* / SMTP_* env vars.
 */
final class Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $secure,      // 'tls' | 'ssl' | ''
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string[] $to
     * @param array{content:string,filename:string,mime:string}|null $attachment  null = no attachment
     */
    public function send(array $to, string $subject, string $body, ?array $attachment = null, bool $isHtml = false): void
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            $mail->SMTPAuth = $this->username !== '';
            if ($this->username !== '') {
                $mail->Username = $this->username;
                $mail->Password = $this->password;
            }
            if ($this->secure !== '') {
                $mail->SMTPSecure = $this->secure; // 'tls' or 'ssl'
            }
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($this->fromEmail, $this->fromName !== '' ? $this->fromName : $this->fromEmail);
            foreach ($to as $addr) {
                $addr = trim($addr);
                if ($addr !== '') {
                    $mail->addAddress($addr);
                }
            }
            $mail->Subject = $subject;
            if ($isHtml) {
                $mail->isHTML(true);
                $mail->Body = $body;
                $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $body)));
            } else {
                $mail->Body = $body;
            }
            if ($attachment !== null && ($attachment['content'] ?? '') !== '') {
                $mail->addStringAttachment($attachment['content'], $attachment['filename'], PHPMailer::ENCODING_BASE64, $attachment['mime']);
            }
            $mail->send();
        } catch (MailException $e) {
            $this->logger->error('Mail send failed', ['error' => $mail->ErrorInfo ?: $e->getMessage()]);
            throw new \RuntimeException('Mail send failed: ' . ($mail->ErrorInfo ?: $e->getMessage()), 0, $e);
        }
    }
}
