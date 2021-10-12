<?php

/**
 * @package MoveCloser_Paynow
 */
class MoveCloser_Paynow_Block_Form_Payment extends Mage_Payment_Block_Form
{
    /**
     * Check if "level 0" integration is enabled.
     *
     * @return boolean
     */
    public function isLevel0Enabled()
    {
        return $this->getPaymentModel()->useLevel0();
    }

    /**
     * Get paynow accepted payment methods.
     *
     * @return array|\Paynow\Model\PaymentMethods\PaymentMethod[]|null Available payment methods
     */
    public function paymentMethods()
    {
        $quote = Mage::getModel('checkout/cart')->getQuote();
        $cartTotal = round($quote->getTotals()['grand_total']->getValue() * 100);
        $currencyCode = $quote->getQuoteCurrencyCode();

        return $this->getPaymentModel()->getAvailableMethods($cartTotal, $currencyCode);
    }

    /**
     * Internal constructor, that is called from real constructor.
     *
     * @return void
     */
    protected function _construct()
    {
        parent::_construct();

        $this->initBlock();
    }

    /**
     * Preparing global layout.
     *
     * @return Mage_Core_Block_Abstract
     */
    protected function _prepareLayout()
    {
        if ($head = $this->getLayout()->getBlock('head')) {
            $head->addCss('css/movecloser_paynow/paynow.css');
        }

        return parent::_prepareLayout();
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

    /**
     * Setup payment method appearance.
     *
     * @return void
     */
    protected function initBlock()
    {
        $availableMethods = $this->paymentMethods();

        if (is_array($availableMethods) && !empty($availableMethods)) {
            $this->setMethodLabelAfterHtml('<img src="' . $this->getSkinUrl($this->getPaymentModel()->getLogoPath()) . '" alt="' . $this->getPaymentModel()->getTitle() . '" class="mainPaynowLogo"/>');
            $this->setMethodTitle($this->getPaymentModel()->getTitle());

            $this->setTemplate('movecloser_paynow/form/payment.phtml');
        }
    }
}