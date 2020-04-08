<?php
namespace Skye\Skyepayments\Model;


class Observer
{
    const LOG_FILE = 'skye.log';

    const JOB_PROCESSING_LIMIT = 50;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\CollectionFactory
     */
    protected $salesResourceModelOrderCollectionFactory;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $salesOrderFactory;

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $salesResourceModelOrderCollectionFactory,
        \Magento\Sales\Model\OrderFactory $salesOrderFactory
    ) {
        $this->logger = $logger;
        $this->salesResourceModelOrderCollectionFactory = $salesResourceModelOrderCollectionFactory;
        $this->salesOrderFactory = $salesOrderFactory;
    }
    /**
     * Cron job to cancel Pending Payment Oxipay orders
     *
     * @param \Magento\Cron\Model\Schedule $schedule
     */
    public function cancelSkyePendingOrders(\Magento\Cron\Model\Schedule $schedule)
    {
        $this->logger->log(\Monolog\Logger::DEBUG, '[skye][cron][cancelSkyePendingOrders]Start');

        $orderCollection = $this->salesResourceModelOrderCollectionFactory->create();
        $orderCollection->join(
            array('p' => 'sales/order_payment'),
            'main_table.entity_id = p.parent_id',
            array()
        );
        $orderCollection
            ->addFieldToFilter('main_table.state', \Skye\Skyepayments\Helper\OrderStatus::STATUS_PENDING_PAYMENT)
            ->addFieldToFilter('p.method', array('like' => 'skye%'))
            ->addFieldToFilter('main_table.created_at', array('lt' =>  new \Zend_Db_Expr("DATE_ADD('".now()."', INTERVAL -'90:00' HOUR_MINUTE)")));

        $orderCollection->setOrder('main_table.updated_at', \Magento\Framework\Data\Collection::SORT_ORDER_ASC);
        $orderCollection->setPageSize(self::JOB_PROCESSING_LIMIT);

        $orders ="";
        foreach($orderCollection->getItems() as $order)
        {
            $orderModel = $this->salesOrderFactory->create();
            $orderModel->load($order['entity_id']);

            if(!$orderModel->canCancel()) {
                continue;
            }

            $orderModel->cancel();

            $history = $orderModel->addStatusHistoryComment('Skye payment was not received for this order after 90 minutes');
            $history->save();

            $orderModel->save();
        }

    }

}