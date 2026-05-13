<?php

namespace App\Service\Payment;

use App\Entity\Payment;

final class PaymentIntent
{
    public function __construct(
        public readonly Payment $payment,
        public readonly string  $action,       // 'redirect' | 'show_instructions'
        public readonly string  $redirectUrl   = '',
        public readonly array   $instructions  = [],
    ) {}

    public static function redirect(Payment $p, string $url): self
    {
        return new self($p, 'redirect', redirectUrl: $url);
    }

    public static function instructions(Payment $p, array $instructions): self
    {
        return new self($p, 'show_instructions', instructions: $instructions);
    }
}
