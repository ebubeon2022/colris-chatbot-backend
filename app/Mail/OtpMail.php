<?php

namespace App\Mail;

class OtpMail
{
    public $otp;
    public $userName;
    public $email;

    public function __construct($otp, $userName, $email)
    {
        $this->otp = $otp;
        $this->userName = $userName;
        $this->email = $email;
    }

    public function send()
    {
        $apiKey = env('BREVO_API_KEY');
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{font-family:Georgia,serif;background:#faf7f2;margin:0;padding:0}.container{max-width:480px;margin:40px auto;background:white;border-radius:12px;overflow:hidden;border:1px solid #e8dcc8}.header{background:#2c1810;padding:32px;text-align:center}.header h1{color:#c9a84c;font-size:24px;margin:0;letter-spacing:2px}.header p{color:rgba(250,247,242,0.6);font-size:13px;margin:6px 0 0}.body{padding:32px;text-align:center}.body h2{color:#2c1810;font-size:18px;margin:0 0 8px}.body p{color:#8b7355;font-size:14px;margin:0 0 24px}.otp-code{background:#fdf6e3;border:2px dashed #c9a84c;border-radius:12px;padding:20px;display:inline-block;margin:0 auto 24px}.otp-code span{font-size:36px;font-weight:700;color:#2c1810;letter-spacing:8px;font-family:monospace}.warning{background:#faf7f2;border-radius:8px;padding:12px 16px;font-size:13px;color:#8b7355;margin-top:16px}.footer{background:#fdf6e3;padding:16px 32px;text-align:center;font-size:12px;color:#b8a898;border-top:1px solid #e8dcc8}</style></head><body><div class="container"><div class="header"><h1>COLRIS</h1><p>Covenant University Library System</p></div><div class="body"><h2>Hello, ' . htmlspecialchars($this->userName) . '!</h2><p>Use the code below to verify your email address. It expires in 10 minutes.</p><div class="otp-code"><span>' . $this->otp . '</span></div><div class="warning">If you did not create a COLRIS account, please ignore this email.</div></div><div class="footer">Covenant University Library &nbsp;|&nbsp; Ota, Ogun State, Nigeria</div></div></body></html>';

        $data = [
            'sender' => ['name' => 'COLRIS Library', 'email' => 'ebubeon2022@gmail.com'],
            'to' => [['email' => $this->email, 'name' => $this->userName]],
            'subject' => 'Your COLRIS Verification Code',
            'htmlContent' => $html,
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'api-key: ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('Brevo API error: ' . $error);
        }

        return $response;
    }
}
