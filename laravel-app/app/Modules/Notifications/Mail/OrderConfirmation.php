<?php

namespace App\Modules\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

final class OrderConfirmation extends Mailable
{
    public function __construct(public readonly array $event) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'NovaCart order #'.$this->event['order_id'].' confirmed',
            using: [function (Email $email): void {
                $email->getHeaders()->addIdHeader('Message-ID', $this->event['event_id'].'@novacart.local');
            }],
        );
    }

    public function content(): Content
    {
        $order = $this->event['order'];
        $lines = ["Your order #{$order['id']} was placed successfully.", "Status: {$order['status_label']}", "Total: {$order['total_amount']}", ''];
        foreach ($order['items'] ?? [] as $line) {
            $lines[] = "Product #{$line['product_id']}: {$line['quantity']} × {$line['unit_price']}";
        }

        return new Content(htmlString: '<pre>'.htmlspecialchars(implode("\n", $lines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>');
    }
}
