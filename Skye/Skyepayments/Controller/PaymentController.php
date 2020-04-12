<?php
namespace Skye\Skyepayments\Controller\;


class PaymentController extends \Magento\Framework\App\Action\Action
{
    const LOG_FILE = 'skye.log';
    const SKYE_AU_CURRENCY_CODE = 'AUD';
    const SKYE_AU_COUNTRY_CODE = 'AU';

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order
     */
    protected $salesResourceModelOrder;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Sales\Model\Order\StatusFactory
     */
    protected $salesOrderStatusFactory;

    /**
     * @var \Magento\Framework\DB\TransactionFactory
     */
    protected $transactionFactory;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $salesOrderFactory;

    /**
     * @var \Magento\Quote\Model\QuoteFactory
     */
    protected $quoteQuoteFactory;

    /**
     * @var \Magento\CatalogInventory\Model\Stock
     */
    protected $catalogInventoryStock;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Sales\Model\ResourceModel\Order $salesResourceModelOrder,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\Order\StatusFactory $salesOrderStatusFactory,
        \Magento\Framework\DB\TransactionFactory $transactionFactory,
        \Magento\Sales\Model\OrderFactory $salesOrderFactory,
        \Magento\Quote\Model\QuoteFactory $quoteQuoteFactory,
        \Magento\CatalogInventory\Model\Stock $catalogInventoryStock
    ) {
        $this->salesResourceModelOrder = $salesResourceModelOrder;
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
        $this->scopeConfig = $scopeConfig;
        $this->checkoutSession = $checkoutSession;
        $this->salesOrderStatusFactory = $salesOrderStatusFactory;
        $this->transactionFactory = $transactionFactory;
        $this->salesOrderFactory = $salesOrderFactory;
        $this->quoteQuoteFactory = $quoteQuoteFactory;
        $this->catalogInventoryStock = $catalogInventoryStock;
        parent::__construct(
            $context
        );
    }


    /**
     * GET: /skyepayments/payment/start
     *
     * Begin processing payment via skye
     */
    public function startAction()
    {
        if($this->validateQuote()) {
            try {

                $order = $this->getLastRealOrder();
                $payload = $this->buildBeginIplTransactionFields($order);
                $soapUrl  = \Skye\Skyepayments\Helper\Data::getSkyeSoapUrl();
                $transactionId  = $this->beginIplTransaction($soapUrl, $payload);

                if ($transactionId != '')
                {
                    $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT, true, 'Skye authorisation underway.');
                    $order->setStatus(\Skye\Skyepayments\Helper\OrderStatus::STATUS_PENDING_PAYMENT);
                    $order->save();
                    $merchantId = \Skye\Skyepayments\Helper\Data::getMerchantNumber();
                    $this->postToCheckout(\Skye\Skyepayments\Helper\Data::getSkyeOnlineUrl(), $merchantId, $transactionId);
                } else {
                    $this->restoreCart($order, 'Skye transaction error.');
                    $this->_redirect('checkout/cart');
                    $this ->cancelOrder($order);
                    $this->salesResourceModelOrder->delete($order);
                }
            } catch (Exception $ex) {
                $this->logger->critical($ex);
                $this->logger->log(\Monolog\Logger::ERROR, 'An exception was encountered in skyepayments/paymentcontroller: '.$ex->getMessage());
                $this->logger->log(\Monolog\Logger::ERROR, $ex->getTraceAsString());
                $this->getCheckoutSession()->addError($this->__('Unable to start Skye Checkout.'));
            }
        } else {
            $this->restoreCart($this->getLastRealOrder(), 'Not a valid quote');
            $this->_redirect('checkout/cart');
            // cancel order (restore stock) and delete order
            $order = $this->getLastRealOrder();
            $this -> cancelOrder($order);
            $this->salesResourceModelOrder->delete($order);
        }
    }

    /**
     * GET: /skyepayments/payment/cancel
     * Cancel an order given an order id
     */
    public function cancelAction()
    {
        $orderId = $this->getRequest()->get('orderId');
        $order =  $this->getOrderById($orderId);
        $transactionId = $this->getRequest()->get('transaction');

        if ($order && $order->getId()) {
            $this->logger->log(\Monolog\Logger::DEBUG, 'Requested order cancellation by customer. OrderId: '.$order->getIncrementId());
            $this->cancelOrder($order);
            $this->restoreCart($order, 'Requested order cancellation by customer.');
            $order->save();
        }
        $this->_redirect('checkout/cart');
    }

    /**
     * GET: skyepayments/payment/decline
     * Order declined.
     *
     */
    public function declineAction() {
        $orderId = $this->getRequest()->get('orderId');
        $order =  $this->getOrderById($orderId);
        $transactionId = $this->getRequest()->get('transaction');

        if ($order && $order->getId()) {
            $this->logger->log(\Monolog\Logger::DEBUG, 'Requested order declined. OrderId: '.$order->getIncrementId());
            $this->declineOrder($order);
            $this->restoreCart($order, 'Requested order declined');
            $order->save();
        }
        $this->_redirect('checkout/cart');
    }

    /**
     * GET: skyepayments/payment/refer
     *
     *
     */
    public function referAction() {
        $orderId = $this->getRequest()->get('orderId');
        $order =  $this->getOrderById($orderId);
        $transactionId = $this->getRequest()->get('transaction');

        if ($order && $order->getId()) {
            $this->logger->log(\Monolog\Logger::DEBUG, 'Requested order Referred. OrderId: '.$order->getIncrementId());
            $this->referOrder($order);
            $this->restoreCart($order, 'Requested order Referred');
            $order->save();
        }
        $this->_redirect('checkout/cart');
    }
    /**
     * GET: skyepayments/payment/complete
     *
     * callback - skye calls this once the payment process has been completed.
     */
    public function completeAction() {

        $orderId = $this->getRequest()->get("orderId");
        $transactionId = $this->getRequest()->get("transaction");
        $soapUrl  = \Skye\Skyepayments\Helper\Data::getSkyeSoapUrl();
        $merchantId = \Skye\Skyepayments\Helper\Data::getMerchantNumber();

        if(!$orderId) {
            $this->logger->log(\Monolog\Logger::ERROR, "Skye returned a null order id. This may indicate an issue with the Skye payment gateway.");
            $this->_redirect('checkout/onepage/error', array('_secure'=> false));
            return;
        }

        $order = $this->getOrderById($orderId);

        if(!$order) {
            $this->logger->log(\Monolog\Logger::ERROR, "Skye returned an id for an order that could not be retrieved: $orderId");
            $this->_redirect('checkout/onepage/error', array('_secure'=> false));
            return;
        }

        // ensure that we have a Mage_Sales_Model_Order
        if (get_class($order) !== 'Mage_Sales_Model_Order') {
            $this->logger->log(\Monolog\Logger::ERROR, "The instance of order returned is an unexpected type.");
        }

        $getIplTransaction = array (
                'TransactionID' => str_replace(PHP_EOL, ' ',$transactionId),
                'MerchantId' => str_replace(PHP_EOL, ' ',$merchantId)
            );
        $applicationStatus = $this->getIplTransaction($soapUrl, $getIplTransaction);

        $resource = $this->resourceConnection;
        $write = $resource->getConnection('core_write');
        $table = $resource->getTableName('sales_flat_order');

        if ($applicationStatus == 'ACCEPTED')
        {
            $commitedTransaction = $this->commitIPLTransaction($soapUrl, $getIplTransaction);
        }else{
            $commitedTransaction = false;
        };

        try {
            $write->beginTransaction();

            $select = $write->select()
                            ->forUpdate()
                            ->from(array('t' => $table),
                                   array('state'))
                            ->where('increment_id = ?', $orderId);

            $state = $write->fetchOne($select);
            if ($state === \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) {
                $this->logger->log(\Monolog\Logger::ALERT, "Pending payment");
                $whereQuery = array('increment_id = ?' => $orderId);

                if ($commitedTransaction)
                    $dataQuery = array('state' => \Magento\Sales\Model\Order::STATE_PROCESSING);
                else
                    $dataQuery = array('state' => \Magento\Sales\Model\Order::STATE_CANCELED);

                $write->update($table, $dataQuery, $whereQuery);
            } else {
                $this->logger->log(\Monolog\Logger::ALERT, "Not Pending payment ".$getIplTransaction);
                $write->commit();

                if ($commitedTransaction)
                    $this->_redirect('checkout/onepage/success', array('_secure'=> false));
                else
                    $this->_redirect('checkout/onepage/failure', array('_secure'=> false));

                return;
            }

            $write->commit();
        } catch (Exception $e) {
            $write->rollback();
            $this->logger->log(\Monolog\Logger::ERROR, "Transaction failed. Order status not updated");
            $this->_redirect('checkout/onepage/error', array('_secure'=> false));
            return;
        }

        $order = $this->getOrderById($orderId);

        if ($commitedTransaction) {
            $this->logger->log(\Monolog\Logger::ALERT, "Committed transaction ".$getIplTransaction);
            $orderState = \Skye\Skyepayments\Helper\OrderStatus::STATUS_PROCESSING;
            $orderStatus = $this->scopeConfig->getValue('payment/skyepayments/skyepay_approved_order_status', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            $emailCustomer = $this->scopeConfig->getValue('payment/skyepayments/email_customer', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            if (!$this->statusExists($orderStatus)) {
                $orderStatus = $order->getConfig()->getStateDefaultStatus($orderState);
            }

            $order->setState($orderState, $orderStatus ? $orderStatus : true, $this->__("Skye authorisation success. Transaction #$transactionId"), $emailCustomer);
            $order->save();

            if ($emailCustomer) {
                $order->sendNewOrderEmail();
            }

            $invoiceAutomatically = $this->scopeConfig->getValue('payment/skyepayments/automatic_invoice', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            if ($invoiceAutomatically) {
                $this->invoiceOrder($order);
            }
        } else {
            $this->logger->log(\Monolog\Logger::ALERT, "Not committed transaction ".$getIplTransaction);
            $order->addStatusHistoryComment($this->__("Order #".($order->getId())." was declined by skye. Transaction #$transactionId."));
            $order
                ->cancel()
                ->setStatus(\Skye\Skyepayments\Helper\OrderStatus::STATUS_DECLINED)
                ->addStatusHistoryComment($this->__("Order #".($order->getId())." was canceled by customer."));

            $order->save();
            $this->restoreCart($order, 'Not committed transaction.');
        }
        $this->checkoutSession->unsQuoteId();

        if($commitedTransaction){
            $this->_redirect('checkout/onepage/success', array('_secure'=> false));
        }else{
            $this->_redirect('checkout/onepage/failure', array('_secure'=> false));
        }
        return;
    }

     private function getIplTransaction($checkoutUrl, $params)
    {
        $soapclient = new \SoapClient($checkoutUrl, ['trace' => true, 'exceptions' => true]);
        try{
            $response = $soapclient->__soapCall('GetIPLTransactionStatus',[$params]);
            $iplTransactionResult = $response->GetIPLTransactionStatusResult;
        }catch(Exception $ex){
           $this->logger->log(\Monolog\Logger::ERROR, "An exception was encountered in getIPLTransaction: ".($e->getMessage()));
            $this->logger->log(\Monolog\Logger::ERROR, "An exception was encountered in getIPLTransaction: ".($soapclient->__getLastRequest()));
        }
        $this->logger->log(\Monolog\Logger::ALERT, "Finish getIplTransaction!!!");
        return $iplTransactionResult;
    }

    private function commitIPLTransaction($checkoutUrl, $params)
    {
        $commitIplTransactionResult = false;
        $soapclient = new \SoapClient($checkoutUrl, ['trace' => true, 'exceptions' => true]);
        try{
            $response = $soapclient->__soapCall('CommitIPLTransaction',[$params]);
            $commitIplTransactionResult = $response->CommitIPLTransactionResult;
            $this->logger->log(\Monolog\Logger::ALERT, "Response->".($soapclient->__getLastResponse()));
            $this->logger->log(\Monolog\Logger::ALERT, "Request->".($soapclient->__getLastRequest()));

        }catch(Exception $ex){
            $this->logger->log(\Monolog\Logger::ERROR, "An exception was encountered in commitIPLTransaction: ".($e->getMessage()));
            $this->logger->log(\Monolog\Logger::ERROR, "An exception was encountered in commitIPLTransaction: ".($soapclient->__getLastRequest()));
        }

        return $commitIplTransactionResult;
    }

    private function statusExists($orderStatus) {
        try {
            $orderStatusModel = $this->salesOrderStatusFactory->create();
            if ($orderStatusModel) {
                $statusesResCol = $orderStatusModel->getResourceCollection();
                if ($statusesResCol) {
                    $statuses = $statusesResCol->getData();
                    foreach ($statuses as $status) {
                        if ($orderStatus === $status["status"]) return true;
                    }
                }
            }
        } catch(Exception $e) {
            $this->logger->log(\Monolog\Logger::ERROR, "Exception searching statuses: ".($e->getMessage()));
        }
        return false;
    }

    private function sendResponse($isFromAsyncCallback, $result, $orderId){
        if($isFromAsyncCallback){
            // if from POST request (from asynccallback)
            $jsonData = json_encode(["result"=>$result, "order_id"=> $orderId]);
            $this->getResponse()->setHeader('Content-type', 'application/json');
            $this->getResponse()->setBody($jsonData);
        } else {
            // if from GET request (from browser redirect)
            if($result=="completed"){
                $this->_redirect('checkout/onepage/success', array('_secure'=> false));
            }else{
                $this->_redirect('checkout/onepage/failure', array('_secure'=> false));
            }

        }
        return;
    }

    private function invoiceOrder(\Magento\Sales\Model\Order $order) {

        if(!$order->canInvoice()){
            throw new \Magento\Framework\Exception\LocalizedException(Mage::helper('core')->__('Cannot create an invoice.'));
        }

        $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

        if (!$invoice->getTotalQty()) {
            throw new \Magento\Framework\Exception\LocalizedException(Mage::helper('core')->__('Cannot create an invoice without products.'));
        }

        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
        $invoice->register();
        $transactionSave = $this->transactionFactory->create()
        ->addObject($invoice)
        ->addObject($invoice->getOrder());

        $transactionSave->save();
    }

    /**
     * Constructs a SOAP request to Skye IFOL
     * @param $order
     * @return array
     */
    private function buildBeginIplTransactionFields($order){
        $this->logger->log(\Monolog\Logger::ALERT, 'buildBeginIplTransactionFields');
        if($order == null)
        {
            $this->logger->log(\Monolog\Logger::ALERT, 'Unable to get order from last lodged order id. Possibly related to a failed database call.');
            $this->_redirect('checkout/onepage/error', array('_secure'=> false));
        }

        $shippingAddress = $order->getShippingAddress();
        $shippingAddressParts = explode(PHP_EOL, $shippingAddress->getData('street'));
        $shippingAddressGroup = $this->formatAddress($shippingAddressParts, $shippingAddress);

        $billingAddress = $order->getBillingAddress();
        $billingAddressParts = explode(PHP_EOL, $billingAddress->getData('street'));
        $billingAddressGroup = $this->formatAddress($billingAddressParts, $billingAddress);

        $orderId = (int)$order->getRealOrderId();
        $orderTotalAmt = str_replace(PHP_EOL, ' ', $order->getTotalDue());
        if ($order->getPayment()->getAdditionalData() != null)
        {
            $skyeTermCode = $order->getPayment()->getAdditionalData();
        } else {
            $skyeTermCode = \Skye\Skyepayments\Helper\Data::getDefaultProductOffer();
        }
        $transactionInformation = array(
            'MerchantId' => str_replace(PHP_EOL, ' ', \Skye\Skyepayments\Helper\Data::getMerchantNumber()),
            'OperatorId' => str_replace(PHP_EOL, ' ', \Skye\Skyepayments\Helper\Data::getOperatorId()),
            'Password' => str_replace(PHP_EOL, ' ', \Skye\Skyepayments\Helper\Data::getOperatorPassword()),
            'EncPassword' => '',
            'Offer' => str_replace(PHP_EOL, ' ',$skyeTermCode),
            'CreditProduct'=> str_replace(PHP_EOL, ' ', \Skye\Skyepayments\Helper\Data::getCreditProduct()),
            'NoApps' => '',
            'OrderNumber' => str_replace(PHP_EOL, ' ', $orderId),
            'ApplicationId' => '',
            'Description' => '',
            'Amount' => str_replace(PHP_EOL, ' ', $order->getTotalDue()),
            'ExistingCustomer' => '0',
            'Title' => '',
            'FirstName' => str_replace(PHP_EOL, ' ', $order->getCustomerFirstname()),
            'MiddleName' => '',
            'Surname' => str_replace(PHP_EOL, ' ', $order->getCustomerLastname()),
            'Gender' => '',
            'BillingAddress' => $billingAddressGroup,
            'DeliveryAddress' => $shippingAddressGroup,
            'WorkPhoneArea' => '',
            'WorkPhoneNumber' => '',
            'HomePhoneArea' => '',
            'HomePhoneNumber' => '',
            'MobilePhoneNumber' => preg_replace('/\D+/', '', $billingAddress->getData('telephone')),
            'EmailAddress' => str_replace(PHP_EOL, ' ', $order->getData('customer_email')),
            'Status' => '',
            'ReturnApprovedUrl' => str_replace(PHP_EOL, ' ', \Skye\Skyepayments\Helper\Data::getCompleteUrl($orderId)),
            'ReturnDeclineUrl' => str_replace(PHP_EOL, ' ', \Skye\Skyepayments\Helper\Data::getDeclinedUrl($orderId)),
            'ReturnWithdrawUrl' => str_replace(PHP_EOL, ' ', \Skye\Skyepayments\Helper\Data::getCancelledUrl($orderId)),
            'ReturnReferUrl' => str_replace(PHP_EOL, ' ', \Skye\Skyepayments\Helper\Data::getReferUrl($orderId)),
            'SuccessPurch' => '',
            'SuccessAmt' => '',
            'DateLastPurch' => '',
            'PayLastPurch' => '',
            'DateFirstPurch' => '',
            'AcctOpen' => '',
            'CCDets' => '',
            'CCDeclines' => '',
            'CCDeclineNum' => '',
            'DeliveryAddressVal' => '',
            'Fraud' => '',
            'EmailVal' => '',
            'MobileVal' => '',
            'PhoneVal' => '',
            'TransType' => '',
            'UserField1' => '',
            'UserField2' => '',
            'UserField3' => '',
            'UserField4' => '',
            'SMSCustLink' => '',
            'EmailCustLink' => '',
            'SMSCustTemplate' => '',
            'EmailCustTemplate' => '',
            'SMSCustTemplate' => '',
            'EmailDealerTemplate' => '',
            'EmailDealerSubject' => '',
            'EmailCustSubject' => '',
            'DealerEmail' => '',
            'DealerSMS' => '',
            'CreditLimit' => ''
        );

        $params = array(
            'TransactionInformation' => $transactionInformation,
            'SecretKey' => \Skye\Skyepayments\Helper\Data::getApiKey()
        );

        return $params;

    }

    private function formatAddress($addressParts, $address)
    {
        $addressStreet0 = explode(' ', $addressParts[0]);
        $addressStreetCount = count($addressStreet0);

            foreach ($addressStreet0 as $addressValue0) {

                if (is_numeric($addressValue0))
                {
                    $addressNoStr = $addressValue0;
                }
            }

            if ($addressStreetCount == 3)
            {
             $addressNameStr = $addressStreet0[$addressStreetCount - 2];
             $addressTypeStr = $addressStreet0[$addressStreetCount -1];

            }

        if (count($addressParts) > 1)
        {
            $addressStreet1 = explode(' ', $addressParts[1]);
            $addressStreetCount = count($addressStreet1);

            foreach ($addressStreet1 as $addressValue1) {

                if (is_numeric($addressValue1))
                {
                    $addressNoStr = $addressValue1;
                } else {
                    $addressNameStr = $addressValue1;
                }
            }
            if ($addressStreetCount == 1)
            {
                $addressTypeStr = $addressStreet1[0];
            } else {
                $addressTypeStr = $addressStreet1[$addressStreetCount -1];
            }

        }
        $addressTypeStrFmt = strtolower($addressTypeStr);

        if ($address->getData('region') === 'Australia Capital Territory') {
            $state = 'ACT';
        } else if ($address->getData('region') === 'New South Wales') {
            $state ='NSW';
        } else if ($address->getData('region') === 'Northern Territory') {
            $state ='NT';
        } else if ($address->getData('region') === 'Queensland') {
            $state ='QLD';
        } else if ($address->getData('region') === 'South Australia') {
            $state ='SA';
        } else if ($address->getData('region') === 'Tasmania') {
            $state ='TAS';
        } else if ($address->getData('region') === 'Victoria') {
            $state ='VIC';
        } else if ($address->getData('region') === 'Western Australia') {
            $state ='WA';
        } else {
            $state = '';
        }

        $formattedAddress = array(
            'AddressType' => 'Residential',
            'UnitNumber' => '',
            'StreetNumber' => $addressNoStr,
            'StreetName' => $addressNameStr,
            'StreetType' => ucfirst($addressTypeStrFmt),
            'Suburb' => str_replace(PHP_EOL, ' ', $address->getData('city')),
            'City' => str_replace(PHP_EOL, ' ', $address->getData('city')),
            'State' => str_replace(PHP_EOL, ' ', $state),
            'Postcode' => str_replace(PHP_EOL, ' ', $address->getData('postcode')),
            'DPID' => ''
        );

        return $formattedAddress;
    }

        //convert state full name as abbrivation

    /**
     * Calls the SOAP service
     * @param $checkoutUrl
     * @param $params
     * @return $transactionId
     */
    private function beginIplTransaction($checkoutUrl, $params)
    {
        $transactionId = '';
        $soapclient = new \SoapClient($checkoutUrl, ['trace' => true, 'exceptions' => true]);
        try{
            $response = $soapclient->__soapCall('BeginIPLTransaction',[$params]);
            $transactionId = $response->BeginIPLTransactionResult;
        }catch(Exception $ex){
            $this->logger->log(\Monolog\Logger::ERROR, "Exception: response->".$transactionId.$ex->getMessage());
            preg_match('/Validation(.+)|Error(.+)/', $ex->getMessage() , $arrMatches);
            $this->logger->log(\Monolog\Logger::ERROR, "Exception: request->".$soapclient->__getLastRequest());
            $this->logger->log(\Monolog\Logger::ERROR, "Exception: response->".$soapclient->__getLastResponse());
            //$this->_redirect('checkout/onepage/error', array('_secure'=> false));
            $this->getCheckoutSession()->addError($this->__('Unable to start Skye Checkout: '.$soapclient->__getLastResponse()));
            //return;
        }
        return $transactionId;
    }


    /**
     * checks the quote for validity
     * @throws Mage_Api_Exception
     */
    private function validateQuote()
    {
        $specificCurrency = null;

        if ($this->getSpecificCountry() == self::SKYE_AU_COUNTRY_CODE) {
            $specificCurrency = self::SKYE_AU_CURRENCY_CODE;
        }

        $order = $this->getLastRealOrder();
        $minOrderAmount = str_replace(PHP_EOL, ' ', \Skye\Skyepayments\Helper\Data::getMerchantNumber());
        if($order->getTotalDue() < (int)$minOrderAmount) {
            $this->checkoutSession->addError("Skye doesn't support purchases less than $".$minOrderAmount.".");
            return false;
        }

        if($order->getBillingAddress()->getCountry() != $this->getSpecificCountry() || $order->getOrderCurrencyCode() != $specificCurrency ) {
            $this->checkoutSession->addError("Orders from this country are not supported by Skye. Please select a different payment option.");
            return false;
        }

        if( !$order->isVirtual && $order->getShippingAddress()->getCountry() != $this->getSpecificCountry()) {
            $this->checkoutSession->addError("Orders shipped to this country are not supported by Skye. Please select a different payment option.");
            return false;
        }

        return true;
    }

    /**
     * Get current checkout session
     * @return \Magento\Framework\Model\AbstractModel
     */
    private function getCheckoutSession() {
        return $this->checkoutSession;
    }

    /**
     * Injects a self posting form to the page in order to kickoff skye checkout process
     * @param $checkoutUrl
     * @param $payload
     */
    private function postToCheckout($checkoutUrl, $merchantId, $transactionId)
    {
        echo
        "<html>
            <body>
            <form id='form' action='$checkoutUrl' method='get'>";
            echo "<input type='hidden' id='seller' name='seller' value='$merchantId'/>";
            echo "<input type='hidden' id='ifol' name='ifol' value='true'/>";
            echo "<input type='hidden' id='transactionId' name='transactionId' value='$transactionId'/>";
        echo
        '</form>
            </body>';
        echo
        '<script>
                var form = document.getElementById("form");
                form.submit();
            </script>
        </html>';
    }

    /**
     * returns an Order object based on magento's internal order id
     * @param $orderId
     * @return \Magento\Sales\Model\Order
     */
    private function getOrderById($orderId)
    {
        return $this->salesOrderFactory->create()->loadByIncrementId($orderId);
    }

    /**
     * retrieve the merchants skye api key
     * @return mixed
     */
    private function getApiKey()
    {
        return $this->scopeConfig->getValue('payment/skyepayments/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
    * Get specific country
    *
    * @return string
    */
    public function getSpecificCountry()
    {
      return $this->scopeConfig->getValue('payment/skyepayments/specificcountry', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * retrieve the last order created by this session
     * @return null
     */
    private function getLastRealOrder()
    {
        $orderId = $this->checkoutSession->getLastRealOrderId();

        $order =
            ($orderId)
                ? $this->getOrderById($orderId)
                : null;
        return $order;
    }

    /**
     * Method is called when an order is cancelled by a customer. As an Oxipay reference is only passed back to
     * Magento upon a success or decline outcome, the method will return a message with a Magento reference only.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return $this
     * @throws \Exception
     */
    private function cancelOrder(\Magento\Sales\Model\Order $order)
    {
        if (!$order->isCanceled()) {
            $order
                ->cancel()
                ->addStatusHistoryComment($this->__("Order #".($order->getId())." was canceled by customer."));
        }
        return $this;
    }

     /**
     * Method is called when an order is declined by Skye. As an Oxipay reference is only passed back to
     * Magento upon a success or decline outcome, the method will return a message with a Magento reference only.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return $this
     * @throws \Exception
     */
    private function declineOrder(\Magento\Sales\Model\Order $order)
    {
        if (!$order->isCanceled()) {
            $order
                ->cancel()
                ->addStatusHistoryComment($this->__("Order #".($order->getId())." was declined by Skye."));
        }
        return $this;
    }

    /**
     * Method is called when an order is declined by Skye. As an Oxipay reference is only passed back to
     * Magento upon a success or decline outcome, the method will return a message with a Magento reference only.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return $this
     * @throws \Exception
     */
    private function referOrder(\Magento\Sales\Model\Order $order)
     {
        if (!$order->isCanceled()) {
            $order
                ->cancel()
                ->addStatusHistoryComment($this->__("Order #".($order->getId())." is in refer status on Skye."));
        }
        return $this;
    }
    /**
     * Loads the cart with items from the order
     * @param \Magento\Sales\Model\Order $order
     * @param $message
     * @return $this
     */
    private function restoreCart(\Magento\Sales\Model\Order $order, $message = '', $refillStock = false)
    {
        // return all products to shopping cart
        $quoteId = $order->getQuoteId();
        $quote   = $this->quoteQuoteFactory->create()->load($quoteId);

        if ($quote->getId()) {
            $quote->setIsActive(1);
            if ($refillStock) {
                $items = $this->_getProductsQty($quote->getAllItems());
                if ($items != null ) {
                    $this->catalogInventoryStock->revertProductsSale($items);
                }
            }

            $quote->setReservedOrderId(null);
            $quote->save();
            $this->getCheckoutSession()->replaceQuote($quote);
            $this->checkoutSession->addNotice($message);
        }
        return $this;
    }

    /**
     * Prepare array with information about used product qty and product stock item
     * result is:
     * array(
     *  $productId  => array(
     *      'qty'   => $qty,
     *      'item'  => $stockItems|null
     *  )
     * )
     * @param array $relatedItems
     * @return array
     */
    protected function _getProductsQty($relatedItems)
    {
        $items = array();
        foreach ($relatedItems as $item) {
            $productId  = $item->getProductId();
            if (!$productId) {
                continue;
            }
            $children = $item->getChildrenItems();
            if ($children) {
                foreach ($children as $childItem) {
                    $this->_addItemToQtyArray($childItem, $items);
                }
            } else {
                $this->_addItemToQtyArray($item, $items);
            }
        }
        return $items;
    }


    /**
     * Adds stock item qty to $items (creates new entry or increments existing one)
     * $items is array with following structure:
     * array(
     *  $productId  => array(
     *      'qty'   => $qty,
     *      'item'  => $stockItems|null
     *  )
     * )
     *
     * @param \Magento\Quote\Model\Quote\Item $quoteItem
     * @param array &$items
     */
    protected function _addItemToQtyArray($quoteItem, &$items)
    {
        $productId = $quoteItem->getProductId();
        if (!$productId)
            return;
        if (isset($items[$productId])) {
            $items[$productId]['qty'] += $quoteItem->getTotalQty();
        } else {
            $stockItem = null;
            if ($quoteItem->getProduct()) {
                $stockItem = $quoteItem->getProduct()->getStockItem();
            }
            $items[$productId] = array(
                'item' => $stockItem,
                'qty'  => $quoteItem->getTotalQty()
            );
        }
    }
}
