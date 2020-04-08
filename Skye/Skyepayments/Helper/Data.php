<?php
namespace Skye\Skyepayments\Helper;


/**
 * Class Skye_Skyepayments_Helper_Data
 *
 * Provides helper methods for retrieving data for the Skye plugin
 */
class Data extends \Magento\Framework\App\Helper\AbstractHelper
{

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
        parent::__construct(
            $context
        );
    }
   
    public static function init()
    {
    }

    /**
     * get the merchant number
     * @return string
     */
    public static function getMerchantNumber() {
        return $this->scopeConfig->getValue('payment/skyepayments/merchant_number', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * get the operator id
     * @return string
     */
    public static function getOperatorId() {
        return $this->scopeConfig->getValue('payment/skyepayments/operator_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * get the operator password
     * @return string
     */
    public static function getOperatorPassword() {
        return $this->scopeConfig->getValue('payment/skyepayments/operator_password', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * Check if customer should only get default offer or option to select
     * @return boolean
     */
    public static function getCustomerProductOption() {
        return $this->scopeConfig->getValue('payment/skyepayments/default_product_only', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    } 
    /**
     * get the default product offer
     * @return string
     */
    public static function getDefaultProductOffer() {
        return $this->scopeConfig->getValue('payment/skyepayments/default_product_offer', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }   
    /**
     * get the default product offer description
     * @return string
     */
    public static function getDefaultProductDescription() {
        return $this->scopeConfig->getValue('payment/skyepayments/default_product_description', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }       
    /**
     * get the credit product
     * @return string
     */
    public static function getCreditProduct() {
        return $this->scopeConfig->getValue('payment/skyepayments/credit_product', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

     /**
     * get the api key
     * @return string
     */
    public static function getApiKey() {
        return $this->scopeConfig->getValue('payment/skyepayments/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * get the URL of the configured skye SOAP service URL
     * @return string
     */
    public static function getSkyeSoapUrl() {
        return $this->scopeConfig->getValue('payment/skyepayments/skyesoap_url', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * get the URL of the configured skye online url
     * @return string
     */
    public static function getSkyeOnlineUrl() {
        return $this->scopeConfig->getValue('payment/skyepayments/skyeonline_url', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
    * get min order
    * @return string
    */
    public static function getSkyeMinimum() {
        return $this->scopeConfig->getValue('payment/skyepayments/min_order_total', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }
    /**
     * @return string
     */
    public static function getCompleteUrl($orderId) {
        return Mage::getBaseUrl() . 'skyepayments/payment/complete?orderId='.$orderId.'&transaction=[TRANSACTIONID]';
    }

    /**
     * @return string
     */
    public static function getCancelledUrl($orderId) {
        return Mage::getBaseUrl() . 'skyepayments/payment/cancel?orderId='.$orderId.'&transaction=[TRANSACTIONID]';
    }

    /**
     * @return string
     */
    public static function getDeclinedUrl($orderId) {
        return Mage::getBaseUrl() . 'skyepayments/payment/decline?orderId='.$orderId.'&transaction=[TRANSACTIONID]';
    }

    /**
     * @return string
     */
    public static function getReferUrl($orderId) {
        return Mage::getBaseUrl() . 'skyepayments/payment/refer?orderId='.$orderId.'&transaction=[TRANSACTIONID]';
    }
}
\Skye\Skyepayments\Helper\Data::init();