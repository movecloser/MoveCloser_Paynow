<?php

/**
 * @package MoveCloser_Paynow
 */
class MoveCloser_Paynow_Model_Observer
{
    /**
     * Check for new paynow version after admin login.
     *
     * @param $observer
     *
     * @return void
     */
    public function handle_adminLoginAfter($observer)
    {
        $session = Mage::getSingleton('core/session');

        try {
            $currentVersion = $this->getPaymentModel()->getPluginVersion();

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_USERAGENT, 'movecloser/paynow-magento-ext');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            // @fixme: hardcoded url?
            curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/movecloser/MoveCloser_Paynow/tags');
            $tagsJson = curl_exec($ch);
            curl_close($ch);

            $newestVersion = json_decode($tagsJson, true)[0]['name'];
            if (empty($newestVersion)) {
                throw new \Exception($this->_t('Plugin version not found'));
            }

            if (version_compare($currentVersion, $newestVersion, '>')) {
                $session->addNotice($this->_t('New MoveCloser_Paynow plugin version available. Installed version: %s, newest version: %s', $currentVersion, $newestVersion));
            } else {
                $session->addSuccess($this->_t('Installed version of MoveCloser_Paynow is up to date (%s)', $currentVersion));
            }
        } catch (\Exception $e) {
            $session->addError($this->_t('Something went wrong during MoveCloser_Paynow version lookup. Details: %s', $e->getMessage()));
        }
    }

    /**
     * Handle payment methods config update.
     *
     * @param $observer
     *
     * @return void
     */
    public function handle_adminSystemConfigChangedSection($observer)
    {
        $payment = $this->getPaymentModel();

        if ($payment->isEnabled()) {
            $payment->handleConfigUpdate();
        }
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
        return Mage::helper('payment')->__($content, ...$args);
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