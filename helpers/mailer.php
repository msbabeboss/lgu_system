<?php
/**
 * Mailer helper — PHPMailer via Composer autoloader
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

// Load Composer autoloader
require_once BASE_PATH . '/vendor/autoload.php';

function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    $mail = new PHPMailer(true);

    try {
        // ── Server ───────────────────────────────────────
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->Timeout    = 10;

        // ── From / To ────────────────────────────────────
        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        // ── Content ──────────────────────────────────────
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        $mail->send();
        return true;

    } catch (MailerException $e) {
        error_log('[Mailer] PHPMailer error: ' . $mail->ErrorInfo);
        return false;
    } catch (Throwable $e) {
        error_log('[Mailer] Exception: ' . $e->getMessage());
        return false;
    }
}

/**
 * Build the password reset email HTML
 */
function buildResetEmailHtml(string $name, string $resetUrl, string $expiryMinutes = '60'): string
{
    $appName = APP_NAME;
    $year    = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#e8f4fb;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#e8f4fb;padding:40px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);">
      <tr>
        <td style="background:linear-gradient(135deg,#1a4f7a,#3a7fa8);padding:32px 40px;text-align:center;">
          <div style="font-size:2rem;">🔐</div>
          <h1 style="color:#ffffff;font-size:1.3rem;margin:10px 0 4px;font-weight:700;">Password Reset Request</h1>
          <p style="color:rgba(255,255,255,0.75);font-size:0.85rem;margin:0;">{$appName}</p>
        </td>
      </tr>
      <tr>
        <td style="padding:36px 40px;">
          <p style="color:#1a2e1f;font-size:1rem;margin:0 0 16px;">Hello, <strong>{$name}</strong>,</p>
          <p style="color:#2a5070;font-size:0.9rem;line-height:1.7;margin:0 0 24px;">
            We received a request to reset the password for your account. Click the button below to set a new password.
            This link will expire in <strong>{$expiryMinutes} minutes</strong>.
          </p>
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:8px 0 28px;">
              <a href="{$resetUrl}"
                 style="display:inline-block;background:linear-gradient(135deg,#1a4f7a,#3a7fa8);color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:8px;font-size:0.95rem;font-weight:600;letter-spacing:0.3px;">
                Reset My Password
              </a>
            </td></tr>
          </table>
          <p style="color:#3a7fa8;font-size:0.78rem;margin:0 0 8px;">Or copy and paste this link into your browser:</p>
          <p style="background:#e8f4fb;border-radius:6px;padding:10px 14px;font-size:0.78rem;color:#1a4f7a;word-break:break-all;margin:0 0 24px;">{$resetUrl}</p>
          <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:12px 16px;">
            <p style="color:#7a5c00;font-size:0.8rem;margin:0;">
              ⚠️ If you did not request a password reset, please ignore this email. Your password will remain unchanged.
            </p>
          </div>
        </td>
      </tr>
      <tr>
        <td style="background:#f8fafc;padding:20px 40px;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="color:#94a3b8;font-size:0.75rem;margin:0;">© {$year} {$appName}. All rights reserved.</p>
          <p style="color:#94a3b8;font-size:0.72rem;margin:4px 0 0;">This is an automated message — please do not reply.</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

// ─────────────────────────────────────────────────────────────────────────────
// CERTIFICATE ISSUED — confirmation to the barangay admin who issued it
// ─────────────────────────────────────────────────────────────────────────────
function sendCertificateIssuedEmail(
    string $toEmail,
    string $toName,
    string $residentName,
    string $certType,
    string $purpose,
    string $barangayName
): void {
    if (!$toEmail) return;
    $appName = APP_NAME;
    $year    = date('Y');
    $now     = date('F d, Y h:i A');
    $certTypeEsc    = htmlspecialchars($certType);
    $resNameEsc     = htmlspecialchars($residentName);
    $purposeEsc     = htmlspecialchars($purpose ?: '—');
    $barangayEsc    = htmlspecialchars($barangayName);
    $toNameEsc      = htmlspecialchars($toName);
    $loginUrl       = EMAIL_BASE_URL . '/barangay_admin/certificates.php';

    $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#e8f4fb;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#e8f4fb;padding:40px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);">
      <tr>
        <td style="background:linear-gradient(135deg,#1a4f7a,#2d9f50);padding:32px 40px;text-align:center;">
          <div style="font-size:2.2rem;">📜</div>
          <h1 style="color:#fff;font-size:1.3rem;margin:10px 0 4px;font-weight:700;">Certificate Issued</h1>
          <p style="color:rgba(255,255,255,0.75);font-size:0.85rem;margin:0;">{$appName}</p>
        </td>
      </tr>
      <tr>
        <td style="padding:32px 40px;">
          <p style="color:#1a2e1f;font-size:1rem;margin:0 0 16px;">Hello, <strong>{$toNameEsc}</strong>,</p>
          <p style="color:#2a5070;font-size:0.9rem;line-height:1.7;margin:0 0 20px;">
            A certificate has been successfully issued under your account. Details are below.
          </p>
          <div style="background:#f0f7ff;border:1px solid #bdd8f0;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:0.88rem;">
              <tr><td style="padding:5px 0;color:#64748b;width:130px;">Certificate Type</td><td style="padding:5px 0;color:#1a2e1f;font-weight:600;">{$certTypeEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Resident</td><td style="padding:5px 0;color:#1a2e1f;font-weight:600;">{$resNameEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Purpose</td><td style="padding:5px 0;color:#334155;">{$purposeEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Barangay</td><td style="padding:5px 0;color:#334155;">{$barangayEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Issued On</td><td style="padding:5px 0;color:#334155;">{$now}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Issued By</td><td style="padding:5px 0;color:#1a4f7a;font-weight:600;">{$toNameEsc}</td></tr>
            </table>
          </div>
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:0 0 16px;">
              <a href="{$loginUrl}" style="display:inline-block;background:linear-gradient(135deg,#1a4f7a,#2d9f50);color:#fff;text-decoration:none;padding:13px 32px;border-radius:8px;font-size:0.92rem;font-weight:600;">View Certificates</a>
            </td></tr>
          </table>
        </td>
      </tr>
      <tr>
        <td style="background:#f8fafc;padding:18px 40px;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="color:#94a3b8;font-size:0.75rem;margin:0;">© {$year} {$appName}. All rights reserved.</p>
          <p style="color:#94a3b8;font-size:0.72rem;margin:4px 0 0;">This is an automated message — please do not reply.</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;

    try {
        sendMail($toEmail, $toName, "📜 Certificate Issued: {$certType} for {$residentName} — {$appName}", $html);
    } catch (Throwable $e) {
        error_log('[Mailer] sendCertificateIssuedEmail failed: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// BLOTTER CASE FILED — notify super admins when a new blotter case is created
// ─────────────────────────────────────────────────────────────────────────────
function sendBlotterFiledEmail(
    array  $adminEmails,
    string $caseNumber,
    string $caseTitle,
    string $incidentType,
    string $priority,
    string $location,
    string $incidentDate,
    string $barangayName,
    string $filedByName,
    string $filedByEmail
): void {
    if (empty($adminEmails)) return;
    $appName   = APP_NAME;
    $year      = date('Y');
    $loginUrl  = EMAIL_BASE_URL . '/super_admin/dashboard.php';
    $priorityColors = ['Low'=>'#2d9f50','Medium'=>'#d97706','High'=>'#c0392b','Critical'=>'#7b0000'];
    $pColor = $priorityColors[$priority] ?? '#64748b';

    $caseNumEsc   = htmlspecialchars($caseNumber);
    $caseTitleEsc = htmlspecialchars($caseTitle ?: '—');
    $incTypeEsc   = htmlspecialchars($incidentType);
    $locationEsc  = htmlspecialchars($location);
    $dateEsc      = htmlspecialchars($incidentDate);
    $brgyEsc      = htmlspecialchars($barangayName);
    $filedByEsc   = htmlspecialchars($filedByName);
    $filedEmailEsc= htmlspecialchars($filedByEmail);
    $priorityEsc  = htmlspecialchars($priority);

    $emailLine = $filedByEmail
        ? '<br><span style="color:#64748b;font-size:0.82rem;">Email: </span><a href="mailto:'.$filedEmailEsc.'" style="color:#1a4f7a;">'.$filedEmailEsc.'</a>'
        : '';

    $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#e8f4fb;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#e8f4fb;padding:40px 0;">
  <tr><td align="center">
    <table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);">
      <tr>
        <td style="background:linear-gradient(135deg,#7b1c1c,#c0392b);padding:32px 40px;text-align:center;">
          <div style="font-size:2.2rem;">🚨</div>
          <h1 style="color:#fff;font-size:1.3rem;margin:10px 0 4px;font-weight:700;">New Blotter Case Filed</h1>
          <p style="color:rgba(255,255,255,0.75);font-size:0.85rem;margin:0;">{$appName}</p>
        </td>
      </tr>
      <tr>
        <td style="padding:32px 40px;">
          <p style="color:#1a2e1f;font-size:1rem;margin:0 0 16px;">Hello Administrator,</p>
          <p style="color:#2a5070;font-size:0.9rem;line-height:1.7;margin:0 0 20px;">
            A new blotter case has been filed in <strong>{$brgyEsc}</strong> and requires your awareness.
          </p>
          <div style="background:#fff5f5;border:1px solid #fca5a5;border-radius:10px;padding:16px 20px;margin-bottom:20px;">
            <p style="margin:0 0 6px;font-size:0.78rem;color:#7b1c1c;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;">Filed By</p>
            <p style="margin:0;font-size:0.95rem;color:#1a2e1f;font-weight:600;">{$filedByEsc}{$emailLine}</p>
            <p style="margin:4px 0 0;font-size:0.82rem;color:#c0392b;">Barangay: <strong>{$brgyEsc}</strong></p>
          </div>
          <div style="background:#f8fafc;border:1px solid #e0eaf3;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:0.88rem;">
              <tr><td style="padding:5px 0;color:#64748b;width:130px;">Case Number</td><td style="padding:5px 0;color:#1a2e1f;font-weight:600;">{$caseNumEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Case Title</td><td style="padding:5px 0;color:#1a2e1f;">{$caseTitleEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Incident Type</td><td style="padding:5px 0;color:#1a2e1f;">{$incTypeEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Priority</td>
                <td style="padding:5px 0;">
                  <span style="background:{$pColor};color:#fff;padding:2px 10px;border-radius:20px;font-size:0.78rem;font-weight:600;">{$priorityEsc}</span>
                </td>
              </tr>
              <tr><td style="padding:5px 0;color:#64748b;">Location</td><td style="padding:5px 0;color:#334155;">{$locationEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Incident Date</td><td style="padding:5px 0;color:#334155;">{$dateEsc}</td></tr>
            </table>
          </div>
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:0 0 16px;">
              <a href="{$loginUrl}" style="display:inline-block;background:linear-gradient(135deg,#7b1c1c,#c0392b);color:#fff;text-decoration:none;padding:13px 32px;border-radius:8px;font-size:0.92rem;font-weight:600;">View in Dashboard</a>
            </td></tr>
          </table>
        </td>
      </tr>
      <tr>
        <td style="background:#f8fafc;padding:18px 40px;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="color:#94a3b8;font-size:0.75rem;margin:0;">© {$year} {$appName}. All rights reserved.</p>
          <p style="color:#94a3b8;font-size:0.72rem;margin:4px 0 0;">This is an automated message — please do not reply.</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;

    $subject = "🚨 New Blotter Case {$caseNumber} filed in Brgy. {$barangayName} — {$appName}";
    foreach ($adminEmails as $admin) {
        try {
            sendMail($admin['email'], $admin['name'], $subject, $html);
        } catch (Throwable $e) {
            error_log('[Mailer] sendBlotterFiledEmail failed for ' . $admin['email'] . ': ' . $e->getMessage());
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// NOTICE BROADCAST — email barangay admins when super admin posts a notice
// ─────────────────────────────────────────────────────────────────────────────
function sendNoticeBroadcastEmails(
    array  $adminEmails,
    string $noticeTitle,
    string $noticeContent,
    string $createdByName
): void {
    if (empty($adminEmails)) return;
    $appName  = APP_NAME;
    $year     = date('Y');
    $loginUrl = EMAIL_BASE_URL . '/barangay_admin/notices.php';
    $now      = date('F d, Y h:i A');

    $titleEsc   = htmlspecialchars($noticeTitle);
    $contentEsc = nl2br(htmlspecialchars(mb_substr($noticeContent, 0, 600) . (mb_strlen($noticeContent) > 600 ? '…' : '')));
    $byEsc      = htmlspecialchars($createdByName);

    $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#e8f4fb;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#e8f4fb;padding:40px 0;">
  <tr><td align="center">
    <table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);">
      <tr>
        <td style="background:linear-gradient(135deg,#1a4f7a,#3a7fa8);padding:32px 40px;text-align:center;">
          <div style="font-size:2.2rem;">📢</div>
          <h1 style="color:#fff;font-size:1.3rem;margin:10px 0 4px;font-weight:700;">New Notice from LGU</h1>
          <p style="color:rgba(255,255,255,0.75);font-size:0.85rem;margin:0;">{$appName}</p>
        </td>
      </tr>
      <tr>
        <td style="padding:32px 40px;">
          <p style="color:#1a2e1f;font-size:1rem;margin:0 0 4px;">Hello Administrator,</p>
          <p style="color:#64748b;font-size:0.85rem;margin:0 0 20px;">Posted by <strong>{$byEsc}</strong> on {$now}</p>
          <div style="background:#f0f7ff;border-left:4px solid #1a4f7a;border-radius:0 10px 10px 0;padding:18px 20px;margin-bottom:24px;">
            <p style="margin:0 0 8px;font-size:1rem;font-weight:700;color:#1a2e1f;">{$titleEsc}</p>
            <p style="margin:0;font-size:0.9rem;color:#334155;line-height:1.7;">{$contentEsc}</p>
          </div>
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:0 0 16px;">
              <a href="{$loginUrl}" style="display:inline-block;background:linear-gradient(135deg,#1a4f7a,#3a7fa8);color:#fff;text-decoration:none;padding:13px 32px;border-radius:8px;font-size:0.92rem;font-weight:600;">View Full Notice</a>
            </td></tr>
          </table>
        </td>
      </tr>
      <tr>
        <td style="background:#f8fafc;padding:18px 40px;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="color:#94a3b8;font-size:0.75rem;margin:0;">© {$year} {$appName}. All rights reserved.</p>
          <p style="color:#94a3b8;font-size:0.72rem;margin:4px 0 0;">This is an automated message — please do not reply.</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;

    $subject = "📢 New Notice: {$noticeTitle} — {$appName}";
    foreach ($adminEmails as $admin) {
        try {
            sendMail($admin['email'], $admin['name'], $subject, $html);
        } catch (Throwable $e) {
            error_log('[Mailer] sendNoticeBroadcastEmails failed for ' . $admin['email'] . ': ' . $e->getMessage());
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// RESIDENT ADDED — confirmation to the barangay admin who added a resident
// ─────────────────────────────────────────────────────────────────────────────
function sendResidentAddedEmail(
    string $toEmail,
    string $toName,
    string $residentName,
    string $barangayName,
    string $gender,
    string $birthdate,
    int    $age,
    string $civilStatus,
    string $occupation
): void {
    if (!$toEmail) return;
    $appName  = APP_NAME;
    $year     = date('Y');
    $now      = date('F d, Y h:i A');
    $loginUrl = EMAIL_BASE_URL . '/barangay_admin/residents.php';

    $resNameEsc  = htmlspecialchars($residentName);
    $brgyEsc     = htmlspecialchars($barangayName);
    $toNameEsc   = htmlspecialchars($toName);
    $genderEsc   = htmlspecialchars($gender ?: '—');
    $bdateEsc    = htmlspecialchars($birthdate ? date('F d, Y', strtotime($birthdate)) : '—');
    $civilEsc    = htmlspecialchars($civilStatus ?: '—');
    $occupEsc    = htmlspecialchars($occupation ?: '—');

    $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#e8f4fb;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#e8f4fb;padding:40px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);">
      <tr>
        <td style="background:linear-gradient(135deg,#1a4f7a,#2d9f50);padding:32px 40px;text-align:center;">
          <div style="font-size:2.2rem;">👤</div>
          <h1 style="color:#fff;font-size:1.3rem;margin:10px 0 4px;font-weight:700;">New Resident Added</h1>
          <p style="color:rgba(255,255,255,0.75);font-size:0.85rem;margin:0;">{$appName}</p>
        </td>
      </tr>
      <tr>
        <td style="padding:32px 40px;">
          <p style="color:#1a2e1f;font-size:1rem;margin:0 0 4px;">Hello, <strong>{$toNameEsc}</strong>,</p>
          <p style="color:#64748b;font-size:0.85rem;margin:0 0 20px;">Recorded on {$now}</p>
          <div style="background:#f0f7ff;border:1px solid #bdd8f0;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:0.88rem;">
              <tr><td style="padding:5px 0;color:#64748b;width:130px;">Resident Name</td><td style="padding:5px 0;color:#1a2e1f;font-weight:600;">{$resNameEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Barangay</td><td style="padding:5px 0;color:#334155;">{$brgyEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Gender</td><td style="padding:5px 0;color:#334155;">{$genderEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Birth Date</td><td style="padding:5px 0;color:#334155;">{$bdateEsc} (Age: {$age})</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Civil Status</td><td style="padding:5px 0;color:#334155;">{$civilEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Occupation</td><td style="padding:5px 0;color:#334155;">{$occupEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Added By</td><td style="padding:5px 0;color:#1a4f7a;font-weight:600;">{$toNameEsc}</td></tr>
            </table>
          </div>
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:0 0 16px;">
              <a href="{$loginUrl}" style="display:inline-block;background:linear-gradient(135deg,#1a4f7a,#2d9f50);color:#fff;text-decoration:none;padding:13px 32px;border-radius:8px;font-size:0.92rem;font-weight:600;">View Residents</a>
            </td></tr>
          </table>
        </td>
      </tr>
      <tr>
        <td style="background:#f8fafc;padding:18px 40px;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="color:#94a3b8;font-size:0.75rem;margin:0;">© {$year} {$appName}. All rights reserved.</p>
          <p style="color:#94a3b8;font-size:0.72rem;margin:4px 0 0;">This is an automated message — please do not reply.</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;

    try {
        sendMail($toEmail, $toName, "👤 New Resident Added: {$residentName} — {$appName}", $html);
    } catch (Throwable $e) {
        error_log('[Mailer] sendResidentAddedEmail failed: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// GENERIC ACTIVITY / TRANSACTION LOG EMAIL — confirms any action to performer
// ─────────────────────────────────────────────────────────────────────────────
function sendTransactionLogEmail(
    string $toEmail,
    string $toName,
    string $action,
    string $module,
    string $description,
    string $module_url = ''
): void {
    if (!$toEmail) return;
    $appName  = APP_NAME;
    $year     = date('Y');
    $now      = date('F d, Y h:i A');
    $actionEsc = htmlspecialchars($action);
    $moduleEsc = htmlspecialchars($module);
    $descEsc   = htmlspecialchars($description);
    $toNameEsc = htmlspecialchars($toName);
    $loginUrl  = $module_url ?: EMAIL_BASE_URL . '/barangay_admin/activity_log.php';

    $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#e8f4fb;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#e8f4fb;padding:40px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);">
      <tr>
        <td style="background:linear-gradient(135deg,#334155,#1a4f7a);padding:28px 40px;text-align:center;">
          <div style="font-size:2rem;">🔔</div>
          <h1 style="color:#fff;font-size:1.2rem;margin:8px 0 4px;font-weight:700;">Activity Logged</h1>
          <p style="color:rgba(255,255,255,0.7);font-size:0.82rem;margin:0;">{$appName}</p>
        </td>
      </tr>
      <tr>
        <td style="padding:28px 40px;">
          <p style="color:#1a2e1f;font-size:0.95rem;margin:0 0 4px;">Hello, <strong>{$toNameEsc}</strong>,</p>
          <p style="color:#64748b;font-size:0.82rem;margin:0 0 18px;">Action performed on {$now}</p>
          <div style="background:#f8fafc;border:1px solid #e0eaf3;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:0.86rem;">
              <tr><td style="padding:4px 0;color:#64748b;width:110px;">Action</td><td style="padding:4px 0;color:#1a2e1f;font-weight:600;">{$actionEsc}</td></tr>
              <tr><td style="padding:4px 0;color:#64748b;">Module</td><td style="padding:4px 0;color:#334155;">{$moduleEsc}</td></tr>
              <tr><td style="padding:4px 0;color:#64748b;vertical-align:top;">Description</td><td style="padding:4px 0;color:#334155;line-height:1.6;">{$descEsc}</td></tr>
            </table>
          </div>
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:0 0 12px;">
              <a href="{$loginUrl}" style="display:inline-block;background:linear-gradient(135deg,#334155,#1a4f7a);color:#fff;text-decoration:none;padding:11px 28px;border-radius:8px;font-size:0.88rem;font-weight:600;">View Activity Log</a>
            </td></tr>
          </table>
        </td>
      </tr>
      <tr>
        <td style="background:#f8fafc;padding:16px 40px;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="color:#94a3b8;font-size:0.72rem;margin:0;">© {$year} {$appName}. All rights reserved.</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;

    try {
        sendMail($toEmail, $toName, "🔔 [{$module}] {$action} — {$appName}", $html);
    } catch (Throwable $e) {
        error_log('[Mailer] sendTransactionLogEmail failed: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
/**
 * Send email notification to all super admins when a new request is submitted.
 * Shows requester's name, username, and email in the format: Name <username> email@example.com
 *
 * @param array  $adminEmails  [['email'=>'...','name'=>'...'], ...]
 */
function sendNewRequestEmailToAdmins(
    array  $adminEmails,
    string $requesterName,
    string $requesterUsername,
    string $requesterEmail,
    string $barangayName,
    string $requestType,
    string $subject,
    string $details
): void {
    if (empty($adminEmails)) return;

    $appName  = APP_NAME;
    $loginUrl = EMAIL_BASE_URL . '/super_admin/requests.php';
    $year     = date('Y');

    $typeLabel = [
        'password_change'  => 'Password Change',
        'edit_information' => 'Edit Information',
        'new_user'         => 'New User',
        'access_request'   => 'Access Request',
        'reset_2fa'        => 'Reset 2FA',
    ][$requestType] ?? ucfirst(str_replace('_', ' ', $requestType));

    $subjectEsc  = htmlspecialchars($subject);
    $detailsEsc  = nl2br(htmlspecialchars($details));
    $nameEsc     = htmlspecialchars($requesterName);
    $usernameEsc = htmlspecialchars($requesterUsername);
    $emailEsc    = htmlspecialchars($requesterEmail);
    $brgyEsc     = htmlspecialchars($barangayName);

    $emailLine = $requesterEmail
        ? '<br><span style="color:#64748b;font-size:0.82rem;">Email: </span><a href="mailto:' . $emailEsc . '" style="color:#1a4f7a;font-size:0.88rem;">' . $emailEsc . '</a>'
        : '';

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#e8f4fb;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#e8f4fb;padding:40px 0;">
  <tr><td align="center">
    <table width="580" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);">
      <tr>
        <td style="background:linear-gradient(135deg,#1a4f7a,#3a7fa8);padding:32px 40px;text-align:center;">
          <div style="font-size:2rem;">📋</div>
          <h1 style="color:#ffffff;font-size:1.3rem;margin:10px 0 4px;font-weight:700;">New Request Submitted</h1>
          <p style="color:rgba(255,255,255,0.75);font-size:0.85rem;margin:0;">{$appName}</p>
        </td>
      </tr>
      <tr>
        <td style="padding:32px 40px;">
          <p style="color:#1a2e1f;font-size:1rem;margin:0 0 16px;">Hello Administrator,</p>
          <p style="color:#2a5070;font-size:0.9rem;line-height:1.7;margin:0 0 20px;">
            A new <strong>{$typeLabel}</strong> request has been submitted and requires your attention.
          </p>
          <div style="background:#f0f7ff;border:1px solid #bdd8f0;border-radius:10px;padding:16px 20px;margin-bottom:20px;">
            <p style="margin:0 0 6px;font-size:0.78rem;color:#2a5070;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;">Submitted By</p>
            <p style="margin:0;font-size:0.95rem;color:#1a2e1f;font-weight:600;">
              {$nameEsc} &lt;<span style="color:#1a4f7a;">{$usernameEsc}</span>&gt;{$emailLine}
            </p>
            <p style="margin:6px 0 0;font-size:0.82rem;color:#3a7fa8;">Barangay: <strong>{$brgyEsc}</strong></p>
          </div>
          <div style="background:#f8fafc;border:1px solid #e0eaf3;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:0.88rem;">
              <tr>
                <td style="padding:5px 0;color:#64748b;width:120px;vertical-align:top;">Request Type</td>
                <td style="padding:5px 0;color:#1a2e1f;font-weight:600;">{$typeLabel}</td>
              </tr>
              <tr>
                <td style="padding:5px 0;color:#64748b;vertical-align:top;">Subject</td>
                <td style="padding:5px 0;color:#1a2e1f;font-weight:600;">{$subjectEsc}</td>
              </tr>
              <tr>
                <td style="padding:8px 0 4px;color:#64748b;vertical-align:top;">Details</td>
                <td style="padding:8px 0 4px;color:#334155;line-height:1.6;">{$detailsEsc}</td>
              </tr>
            </table>
          </div>
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:0 0 24px;">
              <a href="{$loginUrl}"
                 style="display:inline-block;background:linear-gradient(135deg,#1a4f7a,#3a7fa8);color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:8px;font-size:0.92rem;font-weight:600;">
                View Request in System
              </a>
            </td></tr>
          </table>
        </td>
      </tr>
      <tr>
        <td style="background:#f8fafc;padding:18px 40px;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="color:#94a3b8;font-size:0.75rem;margin:0;">© {$year} {$appName}. All rights reserved.</p>
          <p style="color:#94a3b8;font-size:0.72rem;margin:4px 0 0;">This is an automated message — please do not reply.</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

    $emailSubject = "📋 New {$typeLabel} Request from {$requesterName} ({$requesterUsername}) — {$appName}";
    foreach ($adminEmails as $admin) {
        try {
            sendMail($admin['email'], $admin['name'], $emailSubject, $html);
        } catch (Throwable $e) {
            error_log('[Mailer] sendNewRequestEmailToAdmins failed for ' . $admin['email'] . ': ' . $e->getMessage());
        }
    }
}

/**
 * Send email to the requester when their request status changes.
 * Shows their username and email clearly in the message body.
 */
function sendRequestStatusUpdateEmail(
    string $toEmail,
    string $toName,
    string $username,
    string $subject,
    string $newStatus,
    string $adminNotes = ''
): void {
    if (!$toEmail) return;

    $appName  = APP_NAME;
    $year     = date('Y');
    $loginUrl = EMAIL_BASE_URL . '/barangay_admin/requests.php';

    $statusLabels = [
        'viewed'     => 'Viewed',
        'processing' => 'Processing',
        'approved'   => 'Approved',
        'rejected'   => 'Rejected',
        'done'       => 'Done',
    ];
    $statusLabel = $statusLabels[$newStatus] ?? ucfirst($newStatus);

    $statusEmojis = [
        'viewed'     => '👁️',
        'processing' => '⚙️',
        'approved'   => '✅',
        'rejected'   => '❌',
        'done'       => '🎉',
    ];
    $emoji = $statusEmojis[$newStatus] ?? 'ℹ️';

    $badgeColors = [
        'viewed'     => '#3a7fa8',
        'processing' => '#7c4dbd',
        'approved'   => '#2d9f50',
        'rejected'   => '#c0392b',
        'done'       => '#2d9f50',
    ];
    $badgeColor = $badgeColors[$newStatus] ?? '#64748b';

    $statusMessages = [
        'viewed'     => 'Your request has been <strong>opened and reviewed</strong> by the LGU administrator.',
        'processing' => 'Your request is now <strong>being processed</strong> by the LGU administrator.',
        'approved'   => 'Your request has been <strong>approved</strong> by the LGU administrator.',
        'rejected'   => 'Your request has been <strong>rejected</strong> by the LGU administrator.',
        'done'       => 'Your request has been <strong>completed</strong> by the LGU administrator.',
    ];
    $statusMsg = $statusMessages[$newStatus] ?? "Your request status has been updated to <strong>{$statusLabel}</strong>.";

    $subjectEsc  = htmlspecialchars($subject);
    $usernameEsc = htmlspecialchars($username);
    $toNameEsc   = htmlspecialchars($toName);
    $emailEsc    = htmlspecialchars($toEmail);

    $notesBlock = $adminNotes
        ? '<div style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:12px 16px;margin-bottom:20px;">
            <p style="color:#7a5c00;font-size:0.82rem;font-weight:700;margin:0 0 4px;">📝 Administrator Note:</p>
            <p style="color:#5a4200;font-size:0.88rem;margin:0;line-height:1.6;">' . nl2br(htmlspecialchars($adminNotes)) . '</p>
          </div>'
        : '';

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#e8f4fb;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#e8f4fb;padding:40px 0;">
  <tr><td align="center">
    <table width="580" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);">
      <tr>
        <td style="background:linear-gradient(135deg,#1a4f7a,#3a7fa8);padding:32px 40px;text-align:center;">
          <div style="font-size:2.2rem;">{$emoji}</div>
          <h1 style="color:#ffffff;font-size:1.3rem;margin:10px 0 4px;font-weight:700;">Request Status Update</h1>
          <p style="color:rgba(255,255,255,0.75);font-size:0.85rem;margin:0;">{$appName}</p>
        </td>
      </tr>
      <tr>
        <td style="padding:32px 40px;">
          <p style="color:#1a2e1f;font-size:1rem;margin:0 0 4px;">
            Hello, <strong>{$toNameEsc}</strong>
          </p>
          <p style="color:#64748b;font-size:0.85rem;margin:0 0 16px;">
            Username: <strong>{$usernameEsc}</strong> &nbsp;|&nbsp;
            Email: <a href="mailto:{$emailEsc}" style="color:#1a4f7a;">{$emailEsc}</a>
          </p>
          <p style="color:#2a5070;font-size:0.9rem;line-height:1.7;margin:0 0 20px;">
            {$statusMsg}
          </p>
          <div style="background:#f8fafc;border:1px solid #e0eaf3;border-radius:10px;padding:16px 20px;margin-bottom:20px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:0.88rem;">
              <tr>
                <td style="padding:5px 0;color:#64748b;width:110px;">Request</td>
                <td style="padding:5px 0;color:#1a2e1f;font-weight:600;">{$subjectEsc}</td>
              </tr>
              <tr>
                <td style="padding:5px 0;color:#64748b;">New Status</td>
                <td style="padding:5px 0;">
                  <span style="background:{$badgeColor};color:#fff;padding:3px 12px;border-radius:20px;font-size:0.8rem;font-weight:600;">
                    {$emoji} {$statusLabel}
                  </span>
                </td>
              </tr>
            </table>
          </div>
          {$notesBlock}
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:0 0 24px;">
              <a href="{$loginUrl}"
                 style="display:inline-block;background:linear-gradient(135deg,#1a4f7a,#3a7fa8);color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:8px;font-size:0.92rem;font-weight:600;">
                View My Requests
              </a>
            </td></tr>
          </table>
          <div style="background:#e8f0fb;border:1px solid #c0d0e8;border-radius:8px;padding:12px 16px;">
            <p style="color:#2a5070;font-size:0.8rem;margin:0;">
              ℹ️ Login as <strong>{$usernameEsc}</strong> to track the full history of your request.
            </p>
          </div>
        </td>
      </tr>
      <tr>
        <td style="background:#f8fafc;padding:18px 40px;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="color:#94a3b8;font-size:0.75rem;margin:0;">© {$year} {$appName}. All rights reserved.</p>
          <p style="color:#94a3b8;font-size:0.72rem;margin:4px 0 0;">This is an automated message — please do not reply.</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

    try {
        sendMail(
            $toEmail,
            $toName,
            "{$emoji} Your Request \"{$subject}\" is now {$statusLabel} — {$appName}",
            $html
        );
    } catch (Throwable $e) {
        error_log('[Mailer] sendRequestStatusUpdateEmail failed: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// MANUAL REPORT SENT — email confirmation to the barangay admin who sent it,
// and a notification email to all LGU super admins.
// ─────────────────────────────────────────────────────────────────────────────
function sendManualReportEmail(
    string $senderEmail,
    string $senderName,
    string $barangayName,
    string $reportTitle,
    string $reportType,
    string $reportBody,
    string $sentAt,
    array  $adminEmails   // [['email'=>'...','name'=>'...'], ...]
): void {
    $appName    = APP_NAME;
    $year       = date('Y');
    $reportsUrl = EMAIL_BASE_URL . '/super_admin/reports.php?tab=manual_reports';
    $myUrl      = EMAIL_BASE_URL . '/barangay_admin/reports.php?tab=manual';

    $titleEsc   = htmlspecialchars($reportTitle);
    $typeEsc    = htmlspecialchars($reportType);
    $brgyEsc    = htmlspecialchars($barangayName);
    $senderEsc  = htmlspecialchars($senderName);
    $emailEsc   = htmlspecialchars($senderEmail);
    $dateEsc    = htmlspecialchars($sentAt);
    $bodyPreview= htmlspecialchars(mb_substr(strip_tags($reportBody), 0, 400) . (mb_strlen($reportBody) > 400 ? '…' : ''));
    $bodyFull   = nl2br(htmlspecialchars($reportBody));

    // ── 1. Confirmation to the sender ────────────────────
    if ($senderEmail) {
        $htmlSender = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#e8f4fb;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#e8f4fb;padding:40px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.10);">
      <tr>
        <td style="background:linear-gradient(135deg,#1a4f7a,#3a7fa8);padding:32px 40px;text-align:center;">
          <div style="font-size:2.2rem;">📋</div>
          <h1 style="color:#fff;font-size:1.3rem;margin:10px 0 4px;font-weight:700;">Report Sent to LGU</h1>
          <p style="color:rgba(255,255,255,0.75);font-size:0.85rem;margin:0;">{$appName}</p>
        </td>
      </tr>
      <tr>
        <td style="padding:32px 40px;">
          <p style="color:#1a2e1f;font-size:1rem;margin:0 0 4px;">Hello, <strong>{$senderEsc}</strong>,</p>
          <p style="color:#64748b;font-size:0.85rem;margin:0 0 20px;">Your report has been successfully sent to the LGU on <strong>{$dateEsc}</strong>.</p>
          <div style="background:#f0f7ff;border:1px solid #bdd8f0;border-radius:10px;padding:16px 20px;margin-bottom:20px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:0.88rem;">
              <tr><td style="padding:5px 0;color:#64748b;width:120px;">Report Title</td><td style="padding:5px 0;color:#1a2e1f;font-weight:600;">{$titleEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Type</td><td style="padding:5px 0;color:#334155;">{$typeEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Barangay</td><td style="padding:5px 0;color:#334155;">{$brgyEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Sent By</td><td style="padding:5px 0;color:#1a4f7a;font-weight:600;">{$senderEsc} &lt;{$emailEsc}&gt;</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Date Sent</td><td style="padding:5px 0;color:#334155;">{$dateEsc}</td></tr>
            </table>
          </div>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
            <p style="margin:0 0 8px;font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;">Report Content</p>
            <p style="margin:0;font-size:0.88rem;color:#334155;line-height:1.7;">{$bodyPreview}</p>
          </div>
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:0 0 16px;">
              <a href="{$myUrl}" style="display:inline-block;background:linear-gradient(135deg,#1a4f7a,#3a7fa8);color:#fff;text-decoration:none;padding:13px 32px;border-radius:8px;font-size:0.92rem;font-weight:600;">View My Reports</a>
            </td></tr>
          </table>
        </td>
      </tr>
      <tr>
        <td style="background:#f8fafc;padding:18px 40px;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="color:#94a3b8;font-size:0.75rem;margin:0;">© {$year} {$appName}. All rights reserved.</p>
          <p style="color:#94a3b8;font-size:0.72rem;margin:4px 0 0;">This is an automated message — please do not reply.</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
        try {
            sendMail($senderEmail, $senderName, "✅ Report Sent: {$reportTitle} — {$appName}", $htmlSender);
        } catch (Throwable $e) {
            error_log('[Mailer] sendManualReportEmail (sender) failed: ' . $e->getMessage());
        }
    }

    // ── 2. Notification to LGU super admins ──────────────
    if (empty($adminEmails)) return;

    $htmlAdmin = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#e8f4fb;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#e8f4fb;padding:40px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.10);">
      <tr>
        <td style="background:linear-gradient(135deg,#1a4f7a,#3a7fa8);padding:32px 40px;text-align:center;">
          <div style="font-size:2.2rem;">📨</div>
          <h1 style="color:#fff;font-size:1.3rem;margin:10px 0 4px;font-weight:700;">New Manual Report from Barangay</h1>
          <p style="color:rgba(255,255,255,0.75);font-size:0.85rem;margin:0;">{$appName}</p>
        </td>
      </tr>
      <tr>
        <td style="padding:32px 40px;">
          <p style="color:#1a2e1f;font-size:1rem;margin:0 0 4px;">Hello Administrator,</p>
          <p style="color:#64748b;font-size:0.85rem;margin:0 0 20px;">
            Barangay <strong>{$brgyEsc}</strong> has submitted a manual report to the LGU.
          </p>
          <div style="background:#f0f7ff;border:1px solid #bdd8f0;border-radius:10px;padding:16px 20px;margin-bottom:20px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:0.88rem;">
              <tr><td style="padding:5px 0;color:#64748b;width:120px;">Report Title</td><td style="padding:5px 0;color:#1a2e1f;font-weight:600;">{$titleEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Type</td><td style="padding:5px 0;color:#334155;">{$typeEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Barangay</td><td style="padding:5px 0;color:#334155;">{$brgyEsc}</td></tr>
              <tr><td style="padding:5px 0;color:#64748b;">Sent By</td>
                <td style="padding:5px 0;color:#1a4f7a;font-weight:600;">
                  {$senderEsc}<br>
                  <a href="mailto:{$emailEsc}" style="color:#3a7fa8;font-size:0.82rem;">{$emailEsc}</a>
                </td>
              </tr>
              <tr><td style="padding:5px 0;color:#64748b;">Date Sent</td><td style="padding:5px 0;color:#334155;">{$dateEsc}</td></tr>
            </table>
          </div>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
            <p style="margin:0 0 8px;font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;">Report Content</p>
            <p style="margin:0;font-size:0.88rem;color:#334155;line-height:1.7;">{$bodyPreview}</p>
          </div>
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:0 0 16px;">
              <a href="{$reportsUrl}" style="display:inline-block;background:linear-gradient(135deg,#1a4f7a,#3a7fa8);color:#fff;text-decoration:none;padding:13px 32px;border-radius:8px;font-size:0.92rem;font-weight:600;">View Manual Reports</a>
            </td></tr>
          </table>
        </td>
      </tr>
      <tr>
        <td style="background:#f8fafc;padding:18px 40px;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="color:#94a3b8;font-size:0.75rem;margin:0;">© {$year} {$appName}. All rights reserved.</p>
          <p style="color:#94a3b8;font-size:0.72rem;margin:4px 0 0;">This is an automated message — please do not reply.</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;

    $subject = "📨 New Report from Brgy. {$barangayName}: {$reportTitle} — {$appName}";
    foreach ($adminEmails as $admin) {
        try {
            sendMail($admin['email'], $admin['name'], $subject, $htmlAdmin);
        } catch (Throwable $e) {
            error_log('[Mailer] sendManualReportEmail (admin) failed for ' . ($admin['email'] ?? '?') . ': ' . $e->getMessage());
        }
    }
}
