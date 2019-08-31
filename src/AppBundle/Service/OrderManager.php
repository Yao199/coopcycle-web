<?php

namespace AppBundle\Service;

use AppBundle\Domain\Order\Command as OrderCommand;
use AppBundle\Sylius\Order\OrderTransitions;
use AppBundle\Sylius\Order\OrderInterface;
use SimpleBus\SymfonyBridge\Bus\CommandBus;
use SM\Factory\FactoryInterface as StateMachineFactoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;

class OrderManager
{
    private $stateMachineFactory;
    private $commandBus;

    public function __construct(
        StateMachineFactoryInterface $stateMachineFactory,
        CommandBus $commandBus)
    {
        $this->stateMachineFactory = $stateMachineFactory;
        $this->commandBus = $commandBus;
    }

    public function accept(OrderInterface $order)
    {
        $this->commandBus->handle(new OrderCommand\AcceptOrder($order));
    }

    public function refuse(OrderInterface $order, $reason = null)
    {
        $this->commandBus->handle(new OrderCommand\RefuseOrder($order, $reason));
    }

    public function createPaymentIntent(OrderInterface $order, string $paymentMethodId, bool $automaticCapture)
    {
        $this->commandBus->handle(new OrderCommand\CreatePaymentIntent($order, $paymentMethodId, $automaticCapture));
    }

    public function checkout(OrderInterface $order, $stripeToken)
    {
        $this->commandBus->handle(new OrderCommand\Checkout($order, $stripeToken));
    }

    public function ready(OrderInterface $order)
    {
        $stateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);
        $stateMachine->apply(OrderTransitions::TRANSITION_READY);
    }

    public function fulfill(OrderInterface $order)
    {
        $stateMachine = $this->stateMachineFactory->get($order, OrderTransitions::GRAPH);
        $stateMachine->apply(OrderTransitions::TRANSITION_FULFILL);
    }

    public function cancel(OrderInterface $order, $reason = null)
    {
        $this->commandBus->handle(new OrderCommand\CancelOrder($order, $reason));
    }

    public function onDemand(OrderInterface $order)
    {
        $this->commandBus->handle(new OrderCommand\OnDemand($order));
    }

    public function delay(OrderInterface $order, $delay = 10)
    {
        $this->commandBus->handle(new OrderCommand\DelayOrder($order, $delay));
    }

    public function completePayment(PaymentInterface $payment)
    {
        $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);
        $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);
    }

    public function refundPayment(PaymentInterface $payment, $amount = null, $refundApplicationFee = false)
    {
        $this->commandBus->handle(new OrderCommand\Refund($payment, $amount, $refundApplicationFee));
    }
}
