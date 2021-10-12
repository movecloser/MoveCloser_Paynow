<?php

/**
 * @package MoveCloser_Paynow
 */
class MoveCloser_Paynow_Block_Info_Payment extends Mage_Payment_Block_Info
{
    /**
     * Internal constructor, that is called from real constructor.
     *
     * @return void
     */
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('movecloser_paynow/info/default.phtml');
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
     * Prepare specific information to render in payment details blocks.
     *
     * @param Varien_Object|null $transport
     * @return array|Varien_Object|null
     * @throws Mage_Core_Exception
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        if (null !== $this->_paymentSpecificInformation) {
            return $this->_paymentSpecificInformation;
        }

        $info = $this->getInfo();
        $order = $info->getOrder();

        $transport = new Varien_Object();
        $transport = parent::_prepareSpecificInformation($transport);

        // Append information about chosen paynow payment method (ex. Blik)
        if ($chosenMethod = $this->getChosenMethod($info)) {
            $transport->addData([
                $this->_t('Selected method') => $chosenMethod->getDescription(),
            ]);
        }

        // Append information about refund ID.
        if (!empty($refundId = $info->getAdditionalInformation('refund_id'))) {
            $transport->addData([
                $this->_t('Refund ID') => $refundId,
            ]);
        }

        // Add payment-related URLs to order information
        if (!empty($redirectUri = $info->getAdditionalInformation('redirect_url')) && floatval($order->getBaseTotalDue()) > 0 && !$order->isCanceled()) {
            $status = $info->getAdditionalInformation('paynow_status');

            if (in_array($status, $this->getPaymentModel()->getFinishableStatuses())) {
                $transport->addData([
                    $this->_t('Transaction') => '<a href="' . $redirectUri . '" target="_blank" rel="noopener">' . $this->_t('Finish transaction') . '</a>',
                ]);
            } elseif (in_array($status, $this->getPaymentModel()->getRetryOrCancelableStatuses())) {
                $transport->addData([
                    $this->_t('Re-try') => '<a href="' . $this->_getRetryPaymentUrl($order) . '">' . $this->_t('New transaction') . '</a>',
                    $this->_t('Cancel order') => '<a href="' . $this->_getCancelOrderUrl($order) . '">' . $this->_t('Cancel order') . '</a>',
                ]);
            }
        }

        return $transport;
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
     * Get customer-chosen paynow method.
     *
     * @param Mage_Payment_Model_Info $info
     *
     * @return \Paynow\Model\PaymentMethods\PaymentMethod|null
     */
    protected function getChosenMethod($info)
    {
        $quote = Mage::getModel('checkout/cart')->getQuote();
        $currencyCode = $quote->getQuoteCurrencyCode();

        $methodId = strval($info->getAdditionalInformation('payment_method_id'));
        $cartTotal = round($quote->getTotals()['grand_total']->getValue() * 100);

        foreach ($this->getPaymentModel()->getAvailableMethods($cartTotal, $currencyCode) as $method) {
            if (strval($method->getId()) === $methodId) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Get Payment Model instance.
     *
     * @return MoveCloser_Paynow_Model_Payment
     */
    protected function getPaymentModel()
    {
        return Mage::getModel('paynow/payment');
    }
}