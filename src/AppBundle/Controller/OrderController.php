<?php

namespace AppBundle\Controller;

use AppBundle\Controller\Utils\StripeTrait;
use AppBundle\Entity\Address;
use AppBundle\Entity\Delivery;
use AppBundle\Entity\Restaurant;
use AppBundle\Entity\StripePayment;
use AppBundle\Form\Checkout\CheckoutAddressType;
use AppBundle\Form\Checkout\CheckoutPaymentType;
use AppBundle\Service\OrderManager;
use AppBundle\Service\StripeManager;
use AppBundle\Sylius\Order\OrderInterface;
use AppBundle\Utils\OrderTimeHelper;
use Carbon\Carbon;
use Doctrine\Common\Persistence\ObjectManager;
use Psr\Log\LoggerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use SimpleBus\Message\Bus\MessageBus;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * @Route("/order")
 */
class OrderController extends AbstractController
{
    use StripeTrait;

    private $objectManager;
    private $commandBus;
    private $orderTimeHelper;
    private $logger;

    public function __construct(
        ObjectManager $objectManager,
        MessageBus $commandBus,
        OrderTimeHelper $orderTimeHelper,
        LoggerInterface $logger)
    {
        $this->objectManager = $objectManager;
        $this->commandBus = $commandBus;
        $this->orderTimeHelper = $orderTimeHelper;
        $this->logger = $logger;
    }

    /**
     * @Route("/", name="order")
     * @Template()
     */
    public function indexAction(Request $request,
        CartContextInterface $cartContext,
        OrderProcessorInterface $orderProcessor,
        TranslatorInterface $translator)
    {
        $order = $cartContext->getCart();

        if (null === $order || null === $order->getRestaurant()) {

            return $this->redirectToRoute('homepage');
        }

        $user = $this->getUser();

        // At this step, we are pretty sure the customer is logged in
        // Make sure the order actually has a customer, if not set previously
        // @see AppBundle\EventListener\WebAuthenticationListener
        if ($user !== $order->getCustomer()) {
            $order->setCustomer($user);
            $this->objectManager->flush();
        }

        $originalPromotionCoupon = $order->getPromotionCoupon();
        $originalReusablePackagingEnabled = $order->isReusablePackagingEnabled();

        $form = $this->createForm(CheckoutAddressType::class, $order);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            $order = $form->getData();

            $orderProcessor->process($order);

            $promotionCoupon = $order->getPromotionCoupon();

            // Check if a promotion coupon has been added
            if (null === $originalPromotionCoupon && null !== $promotionCoupon) {
                $this->addFlash(
                    'notice',
                    $translator->trans('promotions.promotion_coupon.success', ['%code%' => $promotionCoupon->getCode()])
                );
            }

            $this->objectManager->flush();

            if ($originalReusablePackagingEnabled !== $order->isReusablePackagingEnabled()) {
                return $this->redirectToRoute('order');
            }

            return $this->redirectToRoute('order_payment');
        }

        $timeInfo = $this->orderTimeHelper->getTimeInfo(
            $order,
            $this->orderTimeHelper->getAvailabilities($order)
        );

        return array(
            'order' => $order,
            'asap' => $timeInfo['asap'],
            'form' => $form->createView(),
        );
    }

    /**
     * @Route("/confirm-payment", name="order_confirm_payment", methods={"POST"})
     */
    public function confirmPaymentAction(Request $request,
        CartContextInterface $cartContext,
        OrderManager $orderManager)
    {
        $order = $cartContext->getCart();

        if (null === $order || null === $order->getRestaurant()) {

            // TODO Validate order status
            // TODO Throw 400 error
        }

        return $this->confirmPayment(
            $request,
            $order,
            false,
            $orderManager,
            $this->objectManager,
            $this->logger
        );
    }

    /**
     * @Route("/payment", name="order_payment")
     * @Template()
     */
    public function paymentAction(Request $request,
        OrderManager $orderManager,
        CartContextInterface $cartContext,
        StripeManager $stripeManager,
        OrderProcessorInterface $orderProcessor)
    {
        $order = $cartContext->getCart();

        if (null === $order || null === $order->getRestaurant()) {

            return $this->redirectToRoute('homepage');
        }

        $stripePayment = $order->getLastPayment(PaymentInterface::STATE_CART);

        // if (null === $stripePayment) {
        //     $orderProcessor->process($order);
        //     $this->objectManager->flush();
        // }

        // Make sure to call StripeManager::configurePayment()
        // It will resolve the Stripe account that will be used
        $stripeManager->configurePayment($stripePayment);

        $form = $this->createForm(CheckoutPaymentType::class, $order);

        $timeInfo = $this->orderTimeHelper->getTimeInfo(
            $order,
            $this->orderTimeHelper->getAvailabilities($order)
        );

        $parameters =  [
            'order' => $order,
            'payment' => $stripePayment,
            'deliveryAddress' => $order->getShippingAddress(),
            'restaurant' => $order->getRestaurant(),
            'asap' => $timeInfo['asap'],
        ];

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            $stripePayment = $order->getLastPayment(PaymentInterface::STATE_CART);

            $orderManager->checkout($order, $form->get('stripePayment')->get('stripeToken')->getData());

            $this->objectManager->flush();

            if (PaymentInterface::STATE_FAILED === $stripePayment->getState()) {
                return array_merge($parameters, [
                    'form' => $form->createView(),
                    'error' => $stripePayment->getLastError()
                ]);
            }

            $this->addFlash('track_goal', true);

            return $this->redirectToRoute('profile_order', [
                'id' => $order->getId(),
                'reset' => 'yes'
            ]);
        }

        $parameters['form'] = $form->createView();

        return $parameters;
    }
}
