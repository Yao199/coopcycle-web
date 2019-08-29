<?php

namespace AppBundle\Domain\Order\Command;

use AppBundle\Sylius\Order\OrderInterface;

class CreatePaymentIntent
{
    private $order;
    private $paymentMethodId;
    private $automaticCapture;

    public function __construct(
        OrderInterface $order,
        string $paymentMethodId,
        bool $automaticCapture)
    {
        $this->order = $order;
        $this->paymentMethodId = $paymentMethodId;
        $this->automaticCapture = $automaticCapture;
    }

    public function getOrder()
    {
        return $this->order;
    }

    public function getPaymentMethodId()
    {
        return $this->paymentMethodId;
    }

    public function isAutomaticCapture()
    {
        return $this->automaticCapture;
    }
}

