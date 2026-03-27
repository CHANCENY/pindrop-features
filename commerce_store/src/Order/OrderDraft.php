<?php

namespace Simp\Pindrop\Modules\commerce_store\src\Order;

class OrderDraft
{
    public array $messages = [];

    public function setMessage(string $message, int $type = 1): void
    {
        $this->messages[$type][] = $message;
    }

    public function getMessages(): array {
        return $this->messages;
    }
}