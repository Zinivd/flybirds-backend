<?php
// app/Mail/InvoiceMail.php
namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceMail extends Mailable // <-- ShouldQueue removed for now
{
    public function __construct(public Order $order, public array $invoiceData) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Flybirds Invoice #' . $this->order->invoice_number,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invoice');
    }

    public function attachments(): array
    {
        $pdf = Pdf::loadView('invoices.pdf', ['data' => $this->invoiceData]);

        return [
            Attachment::fromData(
                fn () => $pdf->output(),
                'Invoice-' . $this->order->invoice_number . '.pdf'
            )->withMime('application/pdf'),
        ];
    }
}