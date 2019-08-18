<?php

namespace Tests\AppBundle\Sylius\OrderProcessing;

use AppBundle\Entity\ApiUser;
use AppBundle\Entity\Contract;
use AppBundle\Entity\Restaurant;
use AppBundle\Entity\Sylius\Order;
use AppBundle\Sylius\Order\AdjustmentInterface;
use AppBundle\Sylius\OrderProcessing\OrderDepositRefundProcessor;
use AppBundle\Entity\Sylius\ProductVariant;
use Prophecy\Argument;
use Sylius\Component\Order\Model\OrderInterface;
use AppBundle\Sylius\Order\OrderItemInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatorInterface;
use Sylius\Component\Order\Model\Adjustment;
use Sylius\Component\Order\Factory\AdjustmentFactoryInterface;
use Doctrine\Common\Collections\ArrayCollection;
use AppBundle\Entity\Sylius\Product;

class OrderDepositRefundProcessorTest extends TestCase
{
    private $adjustmentFactory;
    private $orderDepositRefundProcessor;

    public function setUp(): void
    {
        $this->adjustmentFactory = $this->prophesize(AdjustmentFactoryInterface::class);
        $this->translator = $this->prophesize(TranslatorInterface::class);

        $this->translator->trans('order.adjustment_type.reusable_packaging')
            ->willReturn('Packaging');

        $this->translator->trans('order_item.adjustment_type.reusable_packaging', Argument::type('array'))
            ->willReturn('1 × packaging(s)');

        $this->orderDepositRefundProcessor = new OrderDepositRefundProcessor(
            $this->adjustmentFactory->reveal(),
            $this->translator->reveal()
        );
    }

    private static function createContract($flatDeliveryPrice, $customerAmount, $feeRate)
    {
        $contract = new Contract();
        $contract->setFlatDeliveryPrice($flatDeliveryPrice);
        $contract->setCustomerAmount($customerAmount);
        $contract->setFeeRate($feeRate);

        return $contract;
    }

    private function createOrderItem($quantity, $units, $enabled)
    {
        $orderItem = $this->prophesize(OrderItemInterface::class);
        $variant = $this->prophesize(ProductVariant::class);

        $product = new Product();
        $product->setReusablePackagingEnabled($enabled);
        $product->setReusablePackagingUnit($units);

        $variant->getProduct()->willReturn($product);
        $orderItem->getVariant()->willReturn($variant->reveal());
        $orderItem->getQuantity()->willReturn($quantity);

        return $orderItem;
    }

    public function testNoRestaurantDoesNothing()
    {
        $order = new Order();

        $this->adjustmentFactory->createWithData(
            AdjustmentInterface::REUSABLE_PACKAGING_ADJUSTMENT,
            Argument::type('string'),
            Argument::type('int'),
            Argument::type('bool')
        )->shouldNotBeCalled();

        $this->orderDepositRefundProcessor->process($order);
    }

    public function testRestaurantDepositRefundDisabledDoesNothing()
    {
        $order = new Order();

        $restaurant = new Restaurant();
        $restaurant->setDepositRefundEnabled(false);

        $order->setRestaurant($restaurant);

        $this->adjustmentFactory->createWithData(
            AdjustmentInterface::REUSABLE_PACKAGING_ADJUSTMENT,
            Argument::type('string'),
            Argument::type('int'),
            Argument::type('bool')
        )->shouldNotBeCalled();

        $this->orderDepositRefundProcessor->process($order);
    }

    public function testOrderDoesNotContainReusablePackagingDoesNothing()
    {
        $order = new Order();
        $restaurant = new Restaurant();

        $restaurant->setDepositRefundEnabled(true);
        $order->setRestaurant($restaurant);

        $order->setReusablePackagingEnabled(false);

        $this->adjustmentFactory->createWithData(
            AdjustmentInterface::REUSABLE_PACKAGING_ADJUSTMENT,
            Argument::type('string'),
            Argument::type('int'),
            Argument::type('bool')
        )->shouldNotBeCalled();

        $this->orderDepositRefundProcessor->process($order);
    }

    public function testOrderDepositRefundEnabledAddAdjustment()
    {
        $restaurant = new Restaurant();
        $restaurant->setDepositRefundEnabled(true);

        $customer = $this->prophesize(ApiUser::class);
        $customer
            ->hasReusablePackagingUnitsForOrder(Argument::type(OrderInterface::class))
            ->willReturn(false);

        $order = $this->prophesize(Order::class);
        $order
            ->getCustomer()
            ->willReturn($customer->reveal());
        $order
            ->isReusablePackagingEnabled()
            ->willReturn(true);
        $order
            ->getRestaurant()
            ->willReturn($restaurant);
        $order
            ->removeAdjustmentsRecursively(AdjustmentInterface::REUSABLE_PACKAGING_ADJUSTMENT)
            ->shouldBeCalled();
        $order
            ->removeAdjustmentsRecursively(AdjustmentInterface::GIVE_BACK_ADJUSTMENT)
            ->shouldBeCalled();

        $adjustment = $this->prophesize(AdjustmentInterface::class)->reveal();

        $this->adjustmentFactory->createWithData(
            AdjustmentInterface::REUSABLE_PACKAGING_ADJUSTMENT,
            Argument::type('string'),
            Argument::type('integer'),
            Argument::type('bool')
        )
            ->shouldBeCalled()
            ->will(function ($args) {
                $adjustment = new Adjustment();
                $adjustment->setType($args[0]);
                $adjustment->setAmount($args[2]);

                return $adjustment;
            });

        $item1 = $this->createOrderItem($quantity = 1, $units = 0.5, $enabled = true);
        $item1
            ->addAdjustment(Argument::that(function (Adjustment $adjustment) {
                return $adjustment->getAmount() === 100;
            }))
            ->shouldBeCalled();

        $item2 = $this->createOrderItem($quantity = 2, $units = 1, $enabled = true);
        $item2
            ->addAdjustment(Argument::that(function (Adjustment $adjustment) {
                return $adjustment->getAmount() === 200;
            }))
            ->shouldBeCalled();

        $items = new ArrayCollection([ $item1->reveal(), $item2->reveal() ]);
        $order->getItems()->willReturn($items);

        $order
            ->addAdjustment(Argument::that(function (Adjustment $adjustment) {
                return $adjustment->getAmount() === 300;
            }))
            ->shouldBeCalled();

        $this->orderDepositRefundProcessor->process($order->reveal());
    }
}
