<?php

/**
 * @package Paynow_PaymentGateway
 */
class Paynow_PaymentGateway_Block_Onepage_Failure extends Mage_Core_Block_Template
{
    /**
     * Continue shopping URL
     *
     * @return      string
     */
    public function getContinueShoppingUrl()
    {
        return Mage::getUrl('checkout/cart');
    }

    /**
     *  Payment custom error message
     *
     * @return string
     */
    public function getErrorMessage()
    {
        return Mage::getSingleton('checkout/session')->getErrorMessage();
    }

    /**
     * Get payment-related links.
     *
     * @return array|string[]
     */
    public function getLinks()
    {
        $order = Mage::getModel('sales/order')->loadByIncrementId($this->getRealOrderId());

        if (floatval($order->getBaseTotalDue()) > 0 && !$order->isCanceled()) {
            $status = $order->getPayment()->getAdditionalInformation(Paynow_PaymentGateway_Model_Payment::PAYMENT_AI_PAYNOW_STATUS);

            if (in_array($status, $this->getPaymentModel()->getRetryOrCancelableStatuses())) {
                return [
                    $this->_t('Re-try') => '<a href="' . $this->_getRetryPaymentUrl($order) . '">' . $this->_t('New transaction') . '</a>',
                    $this->_t('Cancel order') => '<a href="' . $this->_getCancelOrderUrl($order) . '">' . $this->_t('Cancel order') . '</a>',
                ];
            }
        }

        return [];
    }

    /**
     * Get last order increment_id.
     *
     * @return string
     */
    public function getRealOrderId()
    {
        return Mage::getSingleton('checkout/session')->getLastRealOrderId();
    }

    /**
     * Set path to template used for generating block's output.
     *
     * @param string $template
     * @return Mage_Core_Block_Template
     */
    public function setTemplate($template)
    {
        if (strpos($template, 'paynow_paymentgateway') === false) {
            return $this;
        }

        return parent::setTemplate($template);
    }

    /**
     * Internal constructor, that is called from real constructor
     *
     */
    protected function _construct()
    {
        parent::_construct();

        $this->setTemplate('paynow_paymentgateway/checkout/failure.phtml');
    }

    /**
     * Get URL that allows to retry payment for unpaid order.
     *
     * @param Mage_Sales_Model_Order $order
     *
     * @return string
     */
    protected function _getRetryPaymentUrl($order)
    {
        return Mage::helper('core/url')
            ->addRequestParam(
                Mage::getUrl('paynow/payment/retry', ['_secure' => true]),
                [
                    'key' => $this->getPaymentModel()->getOrderIdempotencyKey($order),
                    'order_id' => $order->getIncrementId()
                ]
            );
    }

    /**
     * Get URL that allows to cancel unpaid order.
     *
     * @param Mage_Sales_Model_Order $order
     *
     * @return string
     */
    protected function _getCancelOrderUrl($order)
    {
        return Mage::helper('core/url')
            ->addRequestParam(
                Mage::getUrl('paynow/payment/cancel', ['_secure' => true]),
                [
                    'key' => $this->getPaymentModel()->getOrderIdempotencyKey($order),
                    'order_id' => $order->getIncrementId()
                ]
            );
    }

    /**
     * Translate provided string.
     *
     * @param string $content
     * @return string
     */
    protected function _t($content)
    {
        return Mage::helper('payment')->__($content);
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
}
