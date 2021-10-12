<?php

// Autoload Paynow SDK
require_once(Mage::getBaseDir('lib') . '/Paynow/vendor/autoload.php');

use Paynow\Client;
use Paynow\Environment;
use Paynow\Exception\PaynowException;
use Paynow\Exception\SignatureVerificationException;
use Paynow\Model\PaymentMethods\PaymentMethod;
use Paynow\Notification;
use Paynow\Service\Payment;
use Paynow\Service\Refund;
use Paynow\Service\ShopConfiguration;

/**
 * @package MoveCloser_Paynow
 */
class MoveCloser_Paynow_Model_Payment extends Mage_Payment_Model_Method_Abstract
{
    const PAYMENT_STATUS_NEW = 'NEW';
    const PAYMENT_STATUS_PENDING = 'PENDING';
    const PAYMENT_STATUS_ERROR = 'ERROR';
    const PAYMENT_STATUS_REJECTED = 'REJECTED';
    const PAYMENT_STATUS_CONFIRMED = 'CONFIRMED';
    const PAYMENT_STATUS_EXPIRED = 'EXPIRED';

    const REFUND_STATUS_NEW = 'NEW';
    const REFUND_STATUS_PENDING = 'PENDING';
    const REFUND_STATUS_SUCCESSFUL = 'SUCCESSFUL';

    protected $_code = 'paynow';
    protected $_formBlockType = 'paynow/form_payment';
    protected $_infoBlockType = 'paynow/info_payment';
    protected $_isInitializeNeeded = true;
    protected $_canUseInternal = true;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid = false;
    protected $_isGateway = false;
    protected $_canOrder = false;
    protected $_canAuthorize = false;
    protected $_canCapture = true;
    protected $_canCapturePartial = false;

    protected $paymentMethods = null;

    /**
     * Assign data entered in paynow template (checkout).
     *
     * @param mixed $data
     *
     * @return $this
     *
     * @throws Mage_Core_Exception
     */
    public function assignData($data)
    {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }

        if ($methodId = $data->getData('payment_method_id')) {
            $this->getInfoInstance()->setAdditionalInformation('payment_method_id', $methodId);
        }

        return $this;
    }

    /**
     * Cancel payment.
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param string $transactionId
     *
     * @return void
     *
     * @throws Exception
     */
    public function cancelPayment($payment, $transactionId)
    {
        $this->_updatePaymentStatusCanceled($payment, $transactionId, true);
    }

    /**
     * Create new paynow transaction and update order accordingly.
     *
     * @param Mage_Sales_Model_Order $order
     * @param boolean $retry Determines whether transaction is being made for the first time or as a retry action.
     *
     * @return \Paynow\Response\Payment\Authorize
     *
     * @throws Mage_Core_Exception
     * @throws PaynowException
     * @throws Exception
     */
    public function createOrder($order, $retry = false)
    {
        $billingAddress = $order->getBillingAddress();

        $paymentData = [
            'amount' => round($order->getGrandTotal() * 100),
            'currency' => $order->getOrderCurrencyCode(),
            'externalId' => $order->getIncrementId(),
            'description' => $this->_t('Order #%s from %s', $order->getIncrementId(), str_replace(PHP_EOL, ' / ', $order->getStoreName())),
            'continueUrl' => Mage::getUrl('paynow/payment/complete', ['_secure' => true]),
            'buyer' => [
                'email' => $billingAddress->getEmail(),
                'firstName' => $billingAddress->getFirstname(),
                'lastName' => $billingAddress->getLastname(),
                'locale' => str_replace('_', '-', Mage::getStoreConfig('general/locale/code', $order->getStoreId())),
            ],
        ];

        $vt = $this->validityTime();
        if ($vt > 0) {
            $paymentData['validityTime'] = $vt;
        }

        $phone = $billingAddress->getTelephone();
        if (!empty($phone)) {
            // @fixme: hardcoded to 9 digit numbers...
            $digits = 9;
            $prefix = substr($phone, 0, strlen($phone) - $digits);
            if (strlen($prefix) >= 2 && strlen($prefix) <= 5 && is_numeric($prefix)) {
                if (!str_starts_with($prefix, '+')) {
                    $prefix = '+' . $prefix;
                }

                $number = substr($phone, -$digits);
                if (!empty($number) && strlen($number) <= $digits && is_numeric($number)) {
                    $paymentData['buyer']['phone'] = [
                        'prefix' => $prefix,
                        'number' => $number,
                    ];
                }
            }
        }

        if ($this->sendCartWithOrder()) {
            $paymentData['orderItems'] = [];

            foreach ($order->getAllVisibleItems() as $item) {
                $categoryString = [];
                foreach ($item->getProduct()->getCategoryCollection()->addAttributeToSelect('name') as $category) {
                    $categoryString[] = $category->getName();
                }

                $paymentData['orderItems'][] = [
                    'name' => $item->getName(),
                    'category' => implode(' / ', $categoryString),
                    'quantity' => ceil($item->getQtyOrdered()),
                    'price' => round($item->getPriceInclTax() * 100),
                ];
            }
        }

        $paymentInstance = $order->getPayment();
        if (!empty($paymentMethodId = $paymentInstance->getAdditionalInformation('payment_method_id'))) {
            $paymentData['paymentMethodId'] = $paymentMethodId;
        }

        $result = $this->getPaymentService()->authorize($paymentData, $this->getOrderIdempotencyKey($order));

        $paymentInstance->setAdditionalInformation('redirect_url', $result->getRedirectUrl());
        $paymentInstance->setAdditionalInformation('payment_id', $result->getPaymentId());
        $paymentInstance->setAdditionalInformation('paynow_status', $result->getStatus());

        $paymentInstance->save();

        $this->_updatePaymentStatusNew($paymentInstance, $result->getPaymentId());

        if (!$retry) {
            try {
                $order->sendNewOrderEmail()->save();
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }

        return $result;
    }

    /**
     * Get paynow accepted payment methods.
     *
     * @param int $amount Cart total
     * @param null $currency Cart currency
     *
     * @return array|PaymentMethod[]|null Available payment methods
     *
     * @throws Mage_Core_Model_Store_Exception
     */
    public function getAvailableMethods($amount = 0, $currency = null)
    {
        if ($this->paymentMethods === null) {
            $currency = $currency ? $currency : Mage::app()->getStore()->getCurrentCurrencyCode();
            try {
                $this->paymentMethods = $this->getPaymentService()->getPaymentMethods($currency, $amount)->getAll();
            } catch (PaynowException $e) {
                Mage::logException($e);

                $this->paymentMethods = [];
            }
        }

        return $this->paymentMethods;
    }

    /**
     * Get payment statuses that are considered "successful" while returning from payment gateway.
     *
     * @return string[]
     */
    public function getCompletableStatuses()
    {
        return [self::PAYMENT_STATUS_PENDING, self::PAYMENT_STATUS_CONFIRMED];
    }

    /**
     * Get paynow environment as defined by admin.
     *
     * @return string
     */
    public function getEnvironment()
    {
        return $this->getConfigBoolval('use_sandbox') ? Environment::SANDBOX : Environment::PRODUCTION;
    }

    /**
     * Get payment statuses that allow customer to complete transaction in paynow.
     *
     * @return string[]
     */
    public function getFinishableStatuses()
    {
        return [self::PAYMENT_STATUS_NEW, self::PAYMENT_STATUS_PENDING];
    }

    /**
     * Get paynow skin logo path.
     *
     * @return string
     */
    public function getLogoPath()
    {
        return 'images/movecloser_paynow/paynow_logo_black.png';
    }

    /**
     * Get unique key representing current order state to avoid duplicated transactions.
     *
     * @param Mage_Sales_Model_Order $order
     *
     * @return string
     */
    public function getOrderIdempotencyKey($order)
    {
        $transactionCount = Mage::getModel('sales/order_payment_transaction')
            ->getCollection()
            ->addAttributeToFilter('order_id', [
                'eq' => $order->getEntityId()
            ])
            ->getSize();

        return hash_hmac('md5', $order->getId() . '_' . $order->getQuoteId() . '_' . $order->getStoreId() . '_' . $transactionCount, $this->getApiSignature());
    }

    /**
     * Get url to redirect user to after placing an order.
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('paynow/payment/new', ['_secure' => true]);
    }

    /**
     * Get MoveCloser_Paynow plugin version.
     *
     * @return string
     */
    public function getPluginVersion()
    {
        return Mage::getConfig()->getNode('modules/MoveCloser_Paynow/version');
    }

    /**
     * Get refund statuses that allow to mark refund request as successful.
     *
     * @return string[]
     */
    public function getRefundRequestSuccessfullStatuses()
    {
        return [self::REFUND_STATUS_NEW, self::REFUND_STATUS_PENDING, self::REFUND_STATUS_SUCCESSFUL];
    }

    /**
     * Get payment statuses that allow customer to retry transaction or cancel the order.
     *
     * @return string[]
     */
    public function getRetryOrCancelableStatuses()
    {
        return [self::PAYMENT_STATUS_ERROR, self::PAYMENT_STATUS_REJECTED];
    }

    /**
     * Get paynow method title as defined by admin.
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->getConfigData('title');
    }

    /**
     * Update configuration in paynow merchant panel.
     *
     * @return void
     */
    public function handleConfigUpdate()
    {
        try {
            $this->getShopConfigurationService()->changeUrls(
                Mage::getUrl('paynow/payment/complete', ['_secure' => true]),
                Mage::getUrl('paynow/payment/notify', ['_secure' => true])
            );

            Mage::getSingleton('core/session')
                ->addNotice($this->_t('paynow store config has been updated!'));
        } catch (Exception $e) {
            Mage::logException($e);

            Mage::getSingleton('core/session')
                ->addError($this->_t('There was a problem updating store config in paynow: %s', $e->getMessage()));
        }
    }

    /**
     * Check whether method is available
     *
     * @param Mage_Sales_Model_Quote|null $quote
     *
     * @return bool
     *
     * @throws Mage_Core_Model_Store_Exception
     */
    public function isAvailable($quote = null)
    {
        if (empty($quote)) {
            return false;
        }

        $cartTotal = round($quote->getTotals()['grand_total']->getValue() * 100);
        $currencyCode = $quote->getQuoteCurrencyCode();

        return parent::isAvailable($quote) && count($this->getAvailableMethods($cartTotal, $currencyCode)) > 0;
    }

    /**
     * Checks whether paynow is enabled.
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->getConfigBoolval('active');
    }

    /**
     * Process paynow payment status update notification.
     *
     * @param string $data
     * @param string $requestSignature
     *
     * @return void
     *
     * @throws Exception
     */
    public function processNotification($data, $requestSignature)
    {
        try {
            // @fixme: side-effecting, useless instance
            new Notification($this->getApiSignature(), $data, ['Signature' => $requestSignature]);

            $this->updatePaymentStatus(json_decode($data, true));
        } catch (SignatureVerificationException $e) {
            Mage::logException($e);
        }
    }

    /**
     * Refund payment.
     *
     * @param Varien_Object $payment
     * @param float $amount
     *
     * @return $this|void
     *
     * @throws Mage_Core_Exception
     */
    public function refund($payment, $amount)
    {
        /** @var Mage_Sales_Model_Order $order */
        $order = $payment->getOrder();

        $paymentId = $order->getPayment()->getLastTransId();

        try {
            $status = $this->getRefundService()
                ->create(
                    $paymentId,
                    hash_hmac('md5', $paymentId, $this->getApiSignature()), round($amount * 100)
                );

            if (in_array($status->getStatus(), $this->getRefundRequestSuccessfullStatuses())) {
                $order
                    ->getPayment()
                    ->setAdditionalInformation('refund_id', $status->getRefundId())
                    ->save();

                $order
                    ->addStatusHistoryComment(
                        $this->_t('paynow refund - amount: %s, status: %s', $amount, $status->getStatus())
                    )
                    ->save();

                return $this;
            }
        } catch (Exception $e) {
            if (method_exists($e, 'getErrors') && count($e->getErrors()) > 0) {
                Mage::throwException(
                    $this->_t(
                        'paynow refund - amount: %s, status: %s',
                        $amount,
                        $this->_t($e->getErrors()[0]->getType())
                    )
                );
            } else {
                Mage::throwException(
                    $this->_t(
                        'paynow refund - amount: %s, status: %s',
                        $amount,
                        $e->getMessage()
                    )
                );
            }
        }
    }

    /**
     * Checks whether cart items should be sent with new transaction request.
     *
     * @return bool
     */
    public function sendCartWithOrder()
    {
        return $this->getConfigBoolval('send_cart');
    }

    /**
     * Checks whether paynow uses "level 0" integration.
     *
     * @return bool
     */
    public function useLevel0()
    {
        return $this->getConfigBoolval('level_0');
    }

    /**
     * Validate data entered in paynow template (checkout).
     *
     * @return $this
     *
     * @throws Mage_Core_Exception
     */
    public function validate()
    {
        parent::validate();

        if ($this->useLevel0()) {
            if (empty($this->getInfoInstance()->getAdditionalInformation('payment_method_id'))) {
                Mage::throwException($this->_t('Please select payment method'));
            }
        }

        return $this;
    }

    /**
     * Get admin-defined payment validity time in seconds.
     * Returns 0 if configured value is out of range or payment should not expire.
     *
     * @return int
     */
    public function validityTime()
    {
        $expires = $this->getConfigData('expires');
        if (empty($expires)) {
            return 0;
        }

        $expires = intval($expires);
        if ($expires < 1 || $expires > 1440) {
            return 0;
        }

        return $expires * 60;
    }

    /**
     * Translate provided string.
     *
     * @param string $content
     *
     * @return string
     */
    protected function _t($content, ...$args)
    {
        return $this->_getHelper()->__($content, ...$args);
    }

    /**
     * Change the payment and order status to canceled.
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param string $transactionId
     * @param bool $user Determines whether order is being canceled by the user or notification
     *.
     * @return void
     *
     * @throws Exception
     */
    protected function _updatePaymentStatusCanceled($payment, $transactionId, $user = false)
    {
        $comment = $this->_t('The transaction has been canceled.');
        if ($user) {
            $comment = $this->_t('The transaction has been canceled by user.');
        }

        $payment
            ->setTransactionId($transactionId)
            ->setPreparedMessage($comment)
            ->setIsTransactionApproved(true)
            ->setIsTransactionClosed(true)
            ->save();

        $payment
            ->addTransaction(
                Mage_Sales_Model_Order_Payment_Transaction::TYPE_ORDER,
                null,
                false,
                $comment
            )
            ->save();

        $payment
            ->getOrder()
            ->cancel()
            ->sendOrderUpdateEmail(true, $comment)
            ->save();
    }

    /**
     * Update payment and order status to completed.
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param Mage_Sales_Model_Order $order
     * @param string $transactionId
     *
     * @return void
     *
     * @throws Exception
     */
    protected function _updatePaymentStatusCompleted($payment, $order, $transactionId)
    {
        $payment
            ->setTransactionId($transactionId)
            ->setPreparedMessage($this->_t('Transaction completed successfully.'))
            ->setCurrencyCode($payment->getOrder()->getBaseCurrencyCode())
            ->setIsTransactionApproved(true)
            ->setIsTransactionClosed(true)
            ->registerCaptureNotification($order->getTotalDue(), true)
            ->save();

        $order->save();

        if ($invoice = $payment->getCreatedInvoice()) {
            $invoice->sendEmail();
            $order
                ->addStatusHistoryComment($this->_t('Notified customer about invoice #%s.', $invoice->getIncrementId()))
                ->setIsCustomerNotified(true)
                ->save();
        }
    }

    /**
     * Update payment status to new.
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param string $transactionId
     *
     * @return void
     *
     * @throws Exception
     */
    protected function _updatePaymentStatusNew($payment, $transactionId)
    {
        $comment = $this->_t('Transaction registered.');

        $payment
            ->setTransactionId($transactionId)
            ->setPreparedMessage($comment)
            ->setCurrencyCode($payment->getOrder()->getOrderCurrencyCode())
            ->setIsTransactionApproved(false)
            ->setIsTransactionClosed(false)
            ->save();

        $payment
            ->addTransaction(
                Mage_Sales_Model_Order_Payment_Transaction::TYPE_ORDER,
                null,
                false,
                $comment
            )
            ->save();

        $payment
            ->getOrder()
            ->save();
    }

    /**
     * Change the payment status to rejected.
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param string $transactionId
     *
     * @return void
     *
     * @throws Exception
     */
    protected function _updatePaymentStatusRejected($payment, $transactionId)
    {
        $comment = $this->_t('The transaction has been canceled.');

        $payment
            ->setTransactionId($transactionId)
            ->setPreparedMessage($comment)
            ->setIsTransactionApproved(true)
            ->setIsTransactionClosed(true)
            ->save();

        $payment
            ->addTransaction(
                Mage_Sales_Model_Order_Payment_Transaction::TYPE_ORDER,
                null,
                false,
                $comment
            )
            ->save();

        $payment
            ->getOrder()
            ->sendOrderUpdateEmail(true, $comment)
            ->save();
    }

    /**
     * Get admin-defined paynow API key for configured environment.
     *
     * @return string
     */
    protected function getApiKey()
    {
        return $this->getEnvironment() === Environment::SANDBOX
            ? $this->getConfigData('api_key_sandbox')
            : $this->getConfigData('api_key');
    }

    /**
     * Get admin-defined paynow API signature for configured environment.
     *
     * @return string
     */
    protected function getApiSignature()
    {
        return $this->getEnvironment() === Environment::SANDBOX
            ? $this->getConfigData('api_signature_sandbox')
            : $this->getConfigData('api_signature');
    }

    /**
     * Get API Client instance.
     *
     * @return Client
     */
    protected function getApiClient()
    {
        return new Client(
            $this->getApiKey(),
            $this->getApiSignature(),
            $this->getEnvironment()
        );
    }

    /**
     * Get yes/no config path converted to boolean.
     *
     * @param string $path
     *
     * @return bool
     */
    protected function getConfigBoolval($path)
    {
        return in_array($this->getConfigData($path), [1, '1', true]);
    }

    /**
     * Get API payment service instance.
     *
     * @return Payment
     *
     * @throws \Paynow\Exception\ConfigurationException
     */
    protected function getPaymentService()
    {
        return new Payment($this->getApiClient());
    }

    /**
     * Get API refund service instance.
     *
     * @return Refund
     *
     * @throws \Paynow\Exception\ConfigurationException
     */
    protected function getRefundService()
    {
        return new Refund($this->getApiClient());
    }

    /**
     * Get API shop configuration service instance.
     *
     * @return ShopConfiguration
     *
     * @throws \Paynow\Exception\ConfigurationException
     */
    protected function getShopConfigurationService()
    {
        return new ShopConfiguration($this->getApiClient());
    }

    /**
     * Update payment and order status based on incoming notification.
     *
     * @param array $notification
     *
     * @return void
     *
     * @throws Exception
     */
    protected function updatePaymentStatus($notification)
    {
        $statuses = [
            self::PAYMENT_STATUS_NEW => 0,
            self::PAYMENT_STATUS_PENDING => 1,
            self::PAYMENT_STATUS_ERROR => 2,
            self::PAYMENT_STATUS_REJECTED => 3,
            self::PAYMENT_STATUS_CONFIRMED => 4,
            self::PAYMENT_STATUS_EXPIRED => 4
        ];

        $order = Mage::getModel('sales/order')->loadByIncrementId($notification['externalId']);
        $payment = $order->getPayment();
        $currentStatus = $statuses[$payment->getAdditionalInformation('paynow_status')] ?? 0;
        $newStatus = $statuses[$notification['status']] ?? 0;

        // Current status is "more important" than incoming one.
        // It is possible for notifications to arrive out of order.
        if ($currentStatus >= $newStatus) {
            return;
        }

        switch ($notification['status']) {
            case self::PAYMENT_STATUS_NEW:
            case self::PAYMENT_STATUS_PENDING:
                // order is already in pending state
                break;
            case self::PAYMENT_STATUS_ERROR:
            case self::PAYMENT_STATUS_REJECTED:
                $this->_updatePaymentStatusRejected($payment, $notification['paymentId']);
                break;
            case self::PAYMENT_STATUS_CONFIRMED:
                $this->_updatePaymentStatusCompleted($payment, $order, $notification['paymentId']);
                break;
            case self::PAYMENT_STATUS_EXPIRED:
                $this->_updatePaymentStatusCanceled($payment, $notification['paymentId']);
                break;
        }

        $payment
            ->setAdditionalInformation('paynow_status', $notification['status'])
            ->save();
    }
}