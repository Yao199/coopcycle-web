<?php

namespace AppBundle\Controller;

use AppBundle\Controller\Utils\StripeTrait;
use AppBundle\Entity\Delivery;
use AppBundle\Entity\StripePayment;
use AppBundle\Form\StripePaymentType;
use AppBundle\Service\OrderManager;
use AppBundle\Service\SettingsManager;
use AppBundle\Service\StripeManager;
use Doctrine\Common\Persistence\ObjectManager;
use Hashids\Hashids;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWSProvider\JWSProviderInterface;
use Psr\Log\LoggerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use SM\Factory\FactoryInterface as StateMachineFactoryInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Stripe;

/**
 * @Route("/{_locale}/pub", requirements={ "_locale": "%locale_regex%" })
 */
class PublicController extends AbstractController
{
    use StripeTrait;

    public function __construct(
        StateMachineFactoryInterface $stateMachineFactory,
        OrderRepositoryInterface $orderRepository,
        LoggerInterface $logger)
    {
        $this->stateMachineFactory = $stateMachineFactory;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    /**
     * @Route("/o/{number}", name="public_order")
     * @Template
     */
    public function orderAction($number, Request $request,
        SettingsManager $settingsManager,
        StripeManager $stripeManager)
    {
        $order = $this->orderRepository->findOneBy([
            'number' => $number
        ]);

        if (null === $order) {
            throw new NotFoundHttpException(sprintf('Order %s does not exist', $number));
        }

        $stripePayment = $order->getLastPayment();
        $stateMachine = $this->stateMachineFactory->get($stripePayment, PaymentTransitions::GRAPH);

        $parameters = [
            'order' => $order,
            'stripe_payment' => $stripePayment,
        ];

        if ($stateMachine->can(PaymentTransitions::TRANSITION_COMPLETE)) {

            // Make sure to call StripeManager::configurePayment()
            // It will resolve the Stripe account that will be used
            $stripeManager->configurePayment($stripePayment);

            $form = $this->createForm(StripePaymentType::class, $stripePayment);

            $form->handleRequest($request);

            // TODO : handle this with orderManager
            if ($form->isSubmitted() && $form->isValid()) {

                $stripeToken = $form->get('stripeToken')->getData();

                try {

                    if ($stripePayment->getPaymentIntent()) {

                        if ($stripePayment->getPaymentIntent() !== $stripeToken) {
                            throw new \Exception('Payment Intent mismatch');
                        }

                        if ($stripePayment->requiresUseStripeSDK()) {
                            $stripeManager->confirmIntent($stripePayment);
                        }

                    } else {

                        // Legacy
                        Stripe\Stripe::setApiKey($settingsManager->get('stripe_secret_key'));
                        $charge = Stripe\Charge::create([
                            'amount' => $stripePayment->getAmount(),
                            'currency' => strtolower($stripePayment->getCurrencyCode()),
                            'description' => sprintf('Order %s', $order->getNumber()),
                            'metadata' => [
                                'order_id' => $order->getId()
                            ],
                            'source' => $stripeToken,
                        ]);
                        $stripePayment->setCharge($charge->id);

                    }

                    $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);

                } catch (\Exception $e) {

                    $stripePayment->setLastError($e->getMessage());
                    $stateMachine->apply(PaymentTransitions::TRANSITION_FAIL);

                } finally {
                    $this->getDoctrine()->getManagerForClass(StripePayment::class)->flush();
                }

                return $this->redirectToRoute('public_order', ['number' => $number]);
            }

            $parameters = array_merge($parameters, ['form' => $form->createView()]);
        }

        return $parameters;
    }

    /**
     * @Route("/o/{number}/confirm-payment", name="public_order_confirm_payment", methods={"POST"})
     */
    public function confirmPaymentAction($number, Request $request,
        OrderManager $orderManager,
        ObjectManager $objectManager)
    {
        $order = $this->orderRepository->findOneBy([
            'number' => $number
        ]);

        if (null === $order) {
            throw new NotFoundHttpException(sprintf('Order %s does not exist', $number));
        }

        return $this->confirmPayment(
            $request,
            $order,
            true,
            $orderManager,
            $objectManager,
            $this->logger
        );
    }

    /**
     * @Route("/i/{number}", name="public_invoice")
     * @Template
     */
    public function invoiceAction($number, Request $request)
    {
        $order = $this->orderRepository->findOneBy([
            'number' => $number
        ]);

        if (null === $order) {
            throw new NotFoundHttpException(sprintf('Order %s does not exist', $number));
        }

        $delivery = $this->getDoctrine()
            ->getRepository(Delivery::class)
            ->findOneByOrder($order);

        $html = $this->renderView('@App/pdf/delivery.html.twig', [
            'order' => $order,
            'delivery' => $delivery,
            'customer' => $order->getCustomer()
        ]);

        $httpClient = $this->get('csa_guzzle.client.browserless');

        $response = $httpClient->request('POST', '/pdf', ['json' => ['html' => $html]]);

        // TODO Check status

        return new Response((string) $response->getBody(), 200, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * @Route("/d/{hashid}", name="public_delivery")
     * @Template
     */
    public function deliveryAction($hashid, Request $request,
        SettingsManager $settingsManager,
        JWTEncoderInterface $jwtEncoder,
        JWSProviderInterface $jwsProvider)
    {
        $hashids = new Hashids($this->getParameter('secret'), 8);

        $decoded = $hashids->decode($hashid);

        if (count($decoded) !== 1) {
            throw new BadRequestHttpException(sprintf('Hashid "%s" could not be decoded', $hashid));
        }

        $id = current($decoded);
        $delivery = $this->getDoctrine()->getRepository(Delivery::class)->find($id);

        if (null === $delivery) {
            throw $this->createNotFoundException(sprintf('Delivery #%d does not exist', $id));
        }

        $courier = null;
        if ($delivery->isAssigned()) {
            $courier = $delivery->getPickup()->getAssignedCourier();
        }

        $token = null;
        if ($delivery->isAssigned() && !$delivery->isCompleted()) {

            $expiration = clone $delivery->getDropoff()->getDoneBefore();
            $expiration->modify('+3 hours');

            $token = $jwsProvider->create([
                // We add a custom "msn" claim to the token,
                // that will allow tracking a messenger
                'msn' => $courier->getUsername(),
                // Token expires 3 hours after expected completion
                'exp' => $expiration->getTimestamp(),
            ])->getToken();
        }

        return $this->render('@App/delivery/tracking.html.twig', [
            'delivery' => $delivery,
            'courier' => $courier,
            'token' => $token,
        ]);
    }
}
