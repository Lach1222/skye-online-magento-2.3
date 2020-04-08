<?php
namespace Skye\Skyepayments\Model;


/**
 * Class Skye_Skyepayments_Model_Paymentmethod
 *
 * overrides basic payment method functionality and visuals
 */
class Paymentmethod extends \Magento\Payment\Model\Method\AbstractMethod {
    protected $_code  = 'skyepayments';
    protected $_formBlockType = 'skyepayments/form_skyepayments';
    protected $_infoBlockType = 'skyepayments/info_skyepayments';
    protected $_isInitializeNeeded = true;
    protected $_canUseInternal = false;
    protected $_canUseForMultishipping = false;
    protected $_canUseCheckout = true;


    /**
     * Override redirect location of magento's payment method subsystem
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('skyepayments/payment/start', array('_secure' => false));
    }

    /**
    *
    */
    public function getDefaultProductOffer()
    {
        $skyeTermsCode = \Skye\Skyepayments\Helper\Data::getDefaultProductOffer();

        return $skyeTermsCode;
    }
}

?>
