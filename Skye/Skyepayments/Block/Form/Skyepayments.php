<?php
namespace Skye\Skyepayments\Block\Form;


/**
 * Class Skye_Skyepayments_Block_Form_Skyepayments
 * @Description Code behind for the custom Skye payment form.
 * @Remarks Invokes the view template in a distant folder structure: ~/app/design/frontend/base/default/template/skyepayments/form.phtml
 *
 */
class Skyepayments extends \Magento\Payment\Block\Form
{
    const LOG_FILE = 'skye.log';

    /**
     * @var \Magento\Checkout\Model\CartFactory
     */
    protected $checkoutCartFactory;

    public function __construct(
        \Magento\Checkout\Model\CartFactory $checkoutCartFactory
    ) {
        $this->checkoutCartFactory = $checkoutCartFactory;
    }
    protected function _construct()
    {    	
        $mark = Mage::getConfig()->getBlockClassName('core/template');
        $mark = new $mark;
        $mark->setTemplate('skyepayments/mark.phtml');
        $this->setMethodLabelAfterHtml($mark->toHtml());
        parent::_construct();
        $this->setTemplate('skyepayments/form.phtml');           
    }

    public function getDefaultProductOffer()
    {
        $defaultOffer = \Skye\Skyepayments\Helper\Data::getDefaultProductOffer();
        $merchantId = \Skye\Skyepayments\Helper\Data::getMerchantNumber();
        $orderTotalAmount = $this->checkoutCartFactory->create()->getQuote()->getGrandTotal();
        $service_url = 'https://um1fnbwix7.execute-api.ap-southeast-2.amazonaws.com/dev/?id='.$merchantId.'&amount='.$orderTotalAmount.'&callback=jsonpCallback';          
        $data = file_get_contents($service_url);
         if($data[0] !== '[' && $data[0] !== '{') { // we have JSONP
            $data = substr($data, strpos($data, '('));
        }
        $result = json_decode(trim($data,'();'), true);
        $data = trim($data,'();');
        return $data;
    }       

    public function getCustomerProductOffer()
    {
        $customerProductOffer = \Skye\Skyepayments\Helper\Data::getCustomerProductOption();
        return $customerProductOffer;
    }

    public function getDefaultProductDescription()
    {
        $defaultOfferDescription = \Skye\Skyepayments\Helper\Data::getDefaultProductDescription();
        return $defaultOfferDescription;
    }

    public function getOrderTotalAmount()
    {
        $grandTotal = $this->checkoutCartFactory->create()->getQuote()->getGrandTotal();
        return $grandTotal;
    }
}