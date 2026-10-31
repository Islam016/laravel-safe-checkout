<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Exceptions;

final class UnknownGateway extends SafeCheckoutException
{
    public static function named(string $name): self
    {
        return new self("Payment gateway [{$name}] is not configured.");
    }
}
