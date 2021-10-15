<?php

/**
 * @package Paynow_PaymentGateway
 */
class Paynow_PaymentGateway_PaymentController extends Mage_Core_Controller_Front_Action
{
    /**
     * Cancel order upon customer request.
     *
     * @return void
     */
    public function cancelAction()
    {
        try {
            $order = $this->validateIncomingOrder();

            $payment = $order->getPayment();
            $this->getPaymentModel()
                ->cancelPayment(
                    $payment,
                    $payment->getLastTransId()
                );

            $this->getSession()
                ->addSuccess($this->__('Order #%s cancelled successfully!', $order->getIncrementId()));

            $this->_redirect('checkout/cart');
        } catch (Exception $e) {
            Mage::logException($e);

            $this->_redirect('checkout/cart');
        }
    }

    /**
     * Catch the customers returning from payment gateway and redirect them to correct checkout page.
     *
     * @return void
     */
    public function completeAction()
    {
        try {
            $this->getCheckoutSession()->getQuote()->setIsActive(false)->save();
        } catch (\Exception $e) {
            Mage::logException($e);
        }

        $action = 'failure';
        if (in_array($this->getReqParam('paymentStatus'), $this->getPaymentModel()->getCompletableStatuses())) {
            $action = 'success';
        }

        $this->_redirect('checkout/onepage/' . $action, ['_secure' => true]);
    }

    /**
     * Create a paynow payment request and redirect user to obtained url.
     *
     * @return void
     */
    public function newAction()
    {
        try {
            $order = $this->getOrder()->loadByIncrementId($this->getCheckoutSession()->getLastRealOrderId());

            if (!$order->getId()) {
                Mage::throwException($this->__('Order not provided'));
            }

            $result = $this
                ->getPaymentModel()
                ->createOrder($order);

            $this->_redirectUrl($result->getRedirectUrl());
        } catch (\Exception $e) {
            Mage::logException($e);
            $this->getSession()->addError($e->getMessage());

            $this->_redirect('checkout/onepage/failure', ['_secure' => true]);
        }
    }

    /**
     * Handle incoming paynow payment status notification.
     *
     * @return void
     *
     * @throws Zend_Controller_Request_Exception
     *
     * @throws Exception
     */
    public function notifyAction()
    {
        $this->getPaymentModel()->processNotification(trim($this->getIncomingData()), $this->getRequest()->getHeader('Signature'));
    }

    /**
     * Create new transaction upon customer request.
     *
     * @return void
     */
    public function retryAction()
    {
        try {
            $order = $this->validateIncomingOrder();

            if (!in_array($order->getPayment()->getAdditionalInformation(Paynow_PaymentGateway_Model_Payment::PAYMENT_AI_PAYNOW_STATUS), $this->getPaymentModel()->getRetryOrCancelableStatuses())) {
                Mage::throwException($this->__('New transaction cannot be created'));
            }

            $result = $this->getPaymentModel()
                ->createOrder($order, true);

            $this->_redirectUrl($result->getRedirectUrl());
        } catch (Exception $e) {
            Mage::logException($e);
            $this->getSession()->addError($e->getMessage());

            $this->_redirect('checkout/onepage/failure', ['_secure' => true]);
        }
    }

    /**
     * Get Magento session singleton.
     *
     * @return Mage_Core_Model_Session
     */
    protected function getCheckoutSession()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Get incoming request data.
     *
     * @return false|string
     */
    protected function getIncomingData()
    {
        return file_get_contents('php://input');
    }

    /**
     * Get Magento order model.
     *
     * @return Mage_Sales_Model_Order
     */
    protected function getOrder()
    {
        return Mage::getModel('sales/order');
    }

    /**
     * Get Payment Model instance.
     *
     * @return Paynow_PaymentGateway_Model_Payment
     */
    protected function getPaymentModel()
    {
        return Mage::getModel('paynow/payment');
    }

    /**
     * Get incoming request parameter.
     *
     * @param string $key
     * @return string
     */
    protected function getReqParam($key)
    {
        return $this->getRequest()->getParam($key);
    }

    /**
     * Get Magento session singleton.
     *
     * @return Mage_Core_Model_Session
     */
    protected function getSession()
    {
        return Mage::getSingleton('core/session');
    }

    /**
     * Get incoming order validated by security key.
     *
     * @return Mage_Sales_Model_Order
     *
     * @throws Mage_Core_Exception
     */
    protected function validateIncomingOrder()
    {
        $orderIncrementId = $this->getReqParam('order_id');
        $securityKey = $this->getReqParam('key');

        if (empty($orderIncrementId) || empty($securityKey)) {
            Mage::throwException($this->__('Security key or order id not provided'));
        }

        $order = $this->getOrder()->loadByIncrementId($orderIncrementId);
        if ($this->getPaymentModel()->getOrderIdempotencyKey($order) !== $securityKey) {
            Mage::throwException($this->__('Security key does not match order'));
        }

        return $order;
    }
}
