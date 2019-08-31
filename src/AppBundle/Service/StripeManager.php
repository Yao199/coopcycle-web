<?php

namespace AppBundle\Service;

use AppBundle\Entity\StripePayment;
use Psr\Log\LoggerInterface;
use Stripe;

class StripeManager
{
    private $settingsManager;

    public function __construct(SettingsManager $settingsManager)
    {
        $this->settingsManager = $settingsManager;
    }

    public function configurePayment(StripePayment $stripePayment)
    {
        $order = $stripePayment->getOrder();

        $restaurant = $order->getRestaurant();
        if (null === $restaurant) {
            return;
        }

        $livemode = $this->settingsManager->isStripeLivemode();
        $stripeAccount = $restaurant->getStripeAccount($livemode);

        if (null !== $stripeAccount && $restaurant->getContract()->isRestaurantPaysStripeFee()) {
            $stripePayment->setStripeUserId($stripeAccount->getStripeUserId());
        }
    }

    private function getStripeOptions(StripePayment $stripePayment)
    {
        $options = [];

        $stripeAccount = $stripePayment->getStripeUserId();
        if (null !== $stripeAccount) {
            $options['stripe_account'] = $stripeAccount;
        }

        return $options;
    }

    private function configureCreateIntentPayload(StripePayment $stripePayment, array $payload)
    {
        $order = $stripePayment->getOrder();

        $restaurant = $order->getRestaurant();
        if (null === $restaurant) {

            return $payload;
        }

        $attrs = [];

        $stripeAccount = $stripePayment->getStripeUserId();
        if (null !== $stripeAccount) {

            $restaurantPaysStripeFee = $restaurant->getContract()->isRestaurantPaysStripeFee();
            $applicationFee = $order->getFeeTotal();

            if ($restaurantPaysStripeFee) {
                $attrs['application_fee_amount'] = $applicationFee;
            } else {
                $attrs['transfer_data'] = array(
                    'destination' => $stripeAccount,
                    'amount' => $order->getTotal() - $applicationFee
                );
            }
        }

        return $payload + $attrs;
    }

    /**
     * @return Stripe\PaymentIntent
     */
    public function createIntent(StripePayment $stripePayment, bool $automaticCapture = true): Stripe\PaymentIntent
    {
        Stripe\Stripe::setApiKey($this->settingsManager->get('stripe_secret_key'));

        $order = $stripePayment->getOrder();

        $payload = [
            'amount' => $stripePayment->getAmount(),
            'currency' => strtolower($stripePayment->getCurrencyCode()),
            'description' => sprintf('Order %s', $order->getNumber()),
            'payment_method' => $stripePayment->getPaymentMethod(),
            'confirmation_method' => 'manual',
            'confirm' => true,
            // 'statement_descriptor' => '...',
        ];

        if (!$automaticCapture) {
            // @see https://stripe.com/docs/payments/payment-intents/use-cases#separate-auth-capture
            // @see https://stripe.com/docs/payments/payment-intents/creating-payment-intents#separate-authorization-and-capture
            $payload['capture_method'] = 'manual';
        }

        $this->configurePayment($stripePayment);

        $payload = $this->configureCreateIntentPayload($stripePayment, $payload);
        $stripeOptions = $this->getStripeOptions($stripePayment);

        $intent = Stripe\PaymentIntent::create($payload, $stripeOptions);

        return $intent;
    }

    /**
     * @return Stripe\PaymentIntent
     */
    public function confirmIntent(StripePayment $stripePayment): Stripe\PaymentIntent
    {
        Stripe\Stripe::setApiKey($this->settingsManager->get('stripe_secret_key'));

        $stripeOptions = $this->getStripeOptions($stripePayment);

        $intent = Stripe\PaymentIntent::retrieve(
            $stripePayment->getPaymentIntent(),
            $stripeOptions
        );

        $intent->confirm();

        return $intent;
    }

    /**
     * @return Stripe\Charge
     */
    public function authorize(StripePayment $stripePayment)
    {
        Stripe\Stripe::setApiKey($this->settingsManager->get('stripe_secret_key'));

        $livemode = $this->settingsManager->isStripeLivemode();

        $stripeToken = $stripePayment->getStripeToken();

        if (null === $stripeToken) {
            throw new \Exception('No Stripe token provided');
        }

        $order = $stripePayment->getOrder();
        $stripeAccount = $order->getRestaurant()->getStripeAccount($livemode);

        $stripeParams = [
            'amount' => $stripePayment->getAmount(),
            'currency' => strtolower($stripePayment->getCurrencyCode()),
            'source' => $stripeToken,
            'description' => sprintf('Order %s', $order->getNumber()),
            // To authorize a payment without capturing it,
            // make a charge request that also includes the capture parameter with a value of false.
            // This instructs Stripe to only authorize the amount on the customer’s card.
            'capture' => false
        ];

        $stripeOptions = [];

        if (!is_null($stripeAccount)) {

            $restaurantPaysStripeFee = $order->getRestaurant()->getContract()->isRestaurantPaysStripeFee();
            $applicationFee = $order->getFeeTotal();

            if ($restaurantPaysStripeFee) {
                // needed only when using direct charges (the charge is linked to the restaurant's Stripe account)
                $stripePayment->setStripeUserId($stripeAccount->getStripeUserId());
                $stripeOptions['stripe_account'] = $stripeAccount->getStripeUserId();
                $stripeParams['application_fee'] = $applicationFee;
            } else {
                $stripeParams['destination'] = array(
                    'account' => $stripeAccount->getStripeUserId(),
                    'amount' => $order->getTotal() - $applicationFee
                );
            }
        }

        return Stripe\Charge::create(
            $stripeParams,
            $stripeOptions
        );
    }

    /**
     * @return Stripe\Charge
     */
    public function capture(StripePayment $stripePayment)
    {
        Stripe\Stripe::setApiKey($this->settingsManager->get('stripe_secret_key'));

        if (null !== $stripePayment->getPaymentIntent()) {
            // TODO Exception
            $intent = Stripe\PaymentIntent::retrieve(
                $stripePayment->getPaymentIntent(),
                $this->getStripeOptions($stripePayment)
            );

            $intent->capture([
                'amount_to_capture' => $stripePayment->getAmount()
            ]);

            // TODO Return charge
            return $intent;
        }

        $charge = Stripe\Charge::retrieve(
            $stripePayment->getCharge(),
            $this->getStripeOptions($stripePayment)
        );

        if ($charge->captured) {
            // FIXME
            // If we land here, there is a severe problem
            throw new \Exception('Charge already captured');
        }

        $charge->capture();

        return $charge;
    }

    /**
     * @return Stripe\Refund
     */
    public function refund(StripePayment $stripePayment, $amount = null, $refundApplicationFee = false)
    {
        // FIXME
        // Check if the charge was made in test or live mode
        // To achieve this, we need to store a "livemode" key in payment details

        Stripe\Stripe::setApiKey($this->settingsManager->get('stripe_secret_key'));

        $stripeAccount = $stripePayment->getStripeUserId();
        $stripeOptions = array();

        if (null !== $stripeAccount) {
            $stripeOptions['stripe_account'] = $stripeAccount;
        }

        $args = [
            'charge' => $stripePayment->getCharge(),
        ];

        if (null !== $amount) {
            $amount = (int) $amount;
            if ($amount !== $stripePayment->getAmount()) {
                $args['amount'] = $amount;
            }
        }

        $args['refund_application_fee'] = $refundApplicationFee;

        return Stripe\Refund::create($args, $stripeOptions);
    }
}
