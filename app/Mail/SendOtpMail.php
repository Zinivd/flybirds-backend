<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SendOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otpCode;
    public string $name;

    public function __construct(string $name, string $otpCode)
    {
        $this->name = $name;
        $this->otpCode = $otpCode;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Flybirds Leggings Verification Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: "<h3>Hello {$this->name},</h3>
                         <p>Thank you for initiating your registration with Flybirds Leggings.</p>
                         <p>Your unique 5-digit registration verification OTP is: <strong>{$this->otpCode}</strong></p>
                         <p>This code is valid for 15 minutes. Please do not share this code with anyone.</p>"
        );
    }
}