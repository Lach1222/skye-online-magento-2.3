<?php
namespace Skye\Skyepayments\Block\Adminhtml;


/**
 * Class Skye_Skyepayments_Block_Form_Skyepayments
 * @Description Code behind for the custom Skye payment form.
 * @Remarks Invokes the view template in a distant folder structure: ~/app/design/frontend/base/default/template/skyepayments/form.phtml
 *
 */
class Version extends \Magento\Config\Block\System\Config\Form\Field
{
    protected function _getElementHtml(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        return (string)Mage::getConfig()->getNode()->modules->Skye_Skyepayments->version;
    }
}