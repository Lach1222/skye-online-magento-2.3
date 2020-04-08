<?php
/**
 * Class Skye_Skyepayments_Info_Form_Skyepayments
 * @Description Code behind for the custom Skye payment info block.
 * @Remarks Invokes the view template in a distant folder structure: ~/app/design/frontend/base/default/template/skyepayments/info.phtml
 *
 */
namespace Skye\Skyepayments\Block\Info;

class Skyepayments extends \Magento\Payment\Block\Info
{

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('skyepayments/info.phtml');
    }
}