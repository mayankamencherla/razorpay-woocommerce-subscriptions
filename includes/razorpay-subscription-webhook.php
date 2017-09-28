<?php

use Razorpay\Api\Errors;

class RZP_Subscription_Webhook extends RZP_Webhook
{
    /**
     * Handling the payment authorized webhook
     *
     * @param $data
     * @return string|void
     */
    protected function paymentAuthorized(array $data)
    {
        //
        // Order entity should be sent as part of the webhook payload
        //

        $paymentId = $data['payload']['payment']['entity']['id'];

        if (isset($data['payload']['payment']['entity']['invoice_id']) === true)
        {
            $invoiceId = $data['payload']['payment']['entity']['invoice_id'];

            $invoice = $this->getInvoice($invoiceId);

            // Process subscription this way
            if (empty($invoice->subscription_id) === false)
            {
                $subscriptionId = $invoice->subscription_id;

                return $this->processSubscription($paymentId, $subscriptionId);
            }
        }

        $orderId = $data['payload']['payment']['entity']['notes']['woocommerce_order_id'];

        $order = new WC_Order($orderId);

        if ($order->needs_payment() === false)
        {
            return;
        }

        $razorpayPaymentId = $data['payload']['payment']['entity']['id'];

        try
        {
            $payment = $this->api->payment->fetch($razorpayPaymentId);
        }
        catch (Exception $e)
        {
            $log = array(
                'message'   => $e->getMessage(),
                'data'      => $razorpayPaymentId,
                'event'     => $data['event']
            );

            write_log($log);

            exit;
        }

        $amount = $this->getOrderAmountAsInteger($order);

        $success = false;
        $errorMessage = 'The payment has failed.';

        if ($payment['status'] === 'captured')
        {
            $success = true;
        }
        else if (($payment['status'] === 'authorized') and
                 ($this->razorpay->payment_action === 'capture'))
        {
            //
            // If the payment is only authorized, we capture it
            // If the merchant has enabled auto capture
            //
            $payment->capture(array('amount' => $amount));

            $success = true;
        }

        $this->razorpay->updateOrder($order, $success, $errorMessage, $razorpayPaymentId, true);

        exit;
    }

    /**
     * Currently we handle only subscription failures using this webhook
     *
     * @param $data
     */
    protected function paymentFailed(array $data)
    {
        $paymentId = $data['payload']['payment']['entity']['id'];

        if (isset($data['payload']['payment']['entity']['invoice_id']) === true)
        {
            $invoiceId = $data['payload']['payment']['entity']['invoice_id'];

            $invoice = $this->getInvoice($invoiceId);

            // Process subscription this way
            if (empty($invoice->subscription_id) === false)
            {
                $subscriptionId = $invoice->subscription_id;

                return $this->processSubscription($paymentId, $subscriptionId, false);
            }
        }

        exit;
    }

    protected function getInvoice($invoiceId)
    {
        try
        {
            $invoice = $this->api->invoice->fetch($invoiceId);
        }
        catch (Exception $e)
        {
            $log = array(
                'message'   => $e->getMessage(),
                'data'      => $invoiceId,
                'event'     => $data['event']
            );

            write_log($log);

            exit;
        }

        return $invoice;
    }

    /**
     * Helper method used to handle all subscription processing
     *
     * @param $orderId
     * @param string $paymentId
     * @param $subscriptionId
     * @param bool $success
     * @return string|void
     */
    protected function processSubscription($paymentId, $subscriptionId, $success = true)
    {
        //
        // If success is false, automatically process subscription failure
        //

        $api = $this->razorpay->getRazorpayApiInstance();

        try
        {
            $subscription = $api->subscription->fetch($subscriptionId);
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();
            return 'RAZORPAY ERROR: Subscription fetch failed with the message \'' . $message . '\'';
        }

        $orderId = $subscription->notes->woocommerce_order_id;

        if ($success === false)
        {
            return $this->processSubscriptionFailed($orderId);
        }

        $this->processSubscriptionSuccess($orderId, $subscription, $paymentId);

        exit;
    }

    /**
     * In the case of successful payment, we mark the subscription successful
     *
     * @param $orderId
     * @param $subscription
     * @param $paymentId
     */
    protected function processSubscriptionSuccess($orderId, $subscription, $paymentId)
    {
        //
        // This method is used to process the subscription's recurring payment
        //
        $wcSubscription = wcs_get_subscriptions_for_order($orderId);

        $wcSubscriptionId = array_keys($wcSubscription)[0];

        //
        // We will only process one subscription per order
        //
        $wcSubscription = array_values($wcSubscription)[0];

        if (count($wcSubscription) > 1)
        {
            $log = array(
                'Error' => 'There are more than one subscription products in this order'
            );

            write_log($log);

            exit;
        }

        $paymentCount = $wcSubscription->get_completed_payment_count();

        //
        // The subscription is completely paid for
        //
        if ($paymentCount === $subscription->total_count)
        {
            return;
        }
        else if ($paymentCount + 1 === $subscription->paid_count)
        {
            //
            // If subscription has been paid for on razorpay's end, we need to mark the
            // subscription payment to be successful on woocommerce's end
            //
            WC_Subscriptions_Manager::prepare_renewal($wcSubscriptionId);

            $wcSubscription->payment_complete($paymentId);
        }
    }

    /**
     * In the case of payment failure, we mark the subscription as failed
     *
     * @param $orderId
     */
    protected function processSubscriptionFailed($orderId)
    {
        WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($orderId);
    }
}
