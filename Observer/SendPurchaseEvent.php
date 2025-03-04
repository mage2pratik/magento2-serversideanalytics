<?php

namespace Elgentos\ServerSideAnalytics\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;
use Magento\Framework\Event\ManagerInterface;
use Elgentos\ServerSideAnalytics\Model\GAClient;
use Elgentos\ServerSideAnalytics\Model\SalesOrderRepository;
use Elgentos\ServerSideAnalytics\Model\ResourceModel\SalesOrder\CollectionFactory;

class SendPurchaseEvent implements ObserverInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Emulation $emulation,
        private readonly LoggerInterface $logger,
        private readonly GAClient $gaclient,
        private readonly ManagerInterface $event,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CollectionFactory $elgentosSalesOrderCollectionFactory,
        private readonly SalesOrderRepository $elgentosSalesOrderRepository
    ) {
    }

    /**
     * @param $observer
     */
    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $observer->getPayment();
        $invoice = $observer->getInvoice();

        $order = $payment->getOrder();
        $orderStoreId = $order->getStoreId();

        $gaUserDatabaseId = $order->getId();

        if (!$gaUserDatabaseId) {
            $gaUserDatabaseId = $payment->getOrder()->getQuoteId();
        }

        if (!$gaUserDatabaseId) {
            return;
        }

        $this->emulation->startEnvironmentEmulation($orderStoreId, 'adminhtml');

        if (!$this->scopeConfig->getValue(GAClient::GOOGLE_ANALYTICS_SERVERSIDE_ENABLED, ScopeInterface::SCOPE_STORE)
        ) {
            $this->emulation->stopEnvironmentEmulation();
            return;
        }

        /** @var \Magento\Sales\Model\Order\Invoice $invoice */

        $elgentosSalesOrderCollection = $this->elgentosSalesOrderCollectionFactory->create();
        $elgentosSalesOrder = $elgentosSalesOrderCollection
            ->addFieldToFilter(
                ['quote_id', 'order_id'],
                [
                    ['eq' => $gaUserDatabaseId],
                    ['eq' => $gaUserDatabaseId]
                ]
            )
            ->getFirstItem();

        if (!$elgentosSalesOrder->getGaUserId()
                ||
            !$elgentosSalesOrder->getGaSessionId()
        ) {
            $this->emulation->stopEnvironmentEmulation();
            return;
        }

        $products = [];

        if ($this->scopeConfig->isSetFlag(GAClient::GOOGLE_ANALYTICS_SERVERSIDE_ENABLE_LOGGING, ScopeInterface::SCOPE_STORE)) {
            $this->logger->info('elgentos_serversideanalytics_requests: GA UserID: ' . $elgentosSalesOrder->getGaUserId());
        }

        /** @var \Magento\Sales\Model\Order\Invoice\Item $item */
        foreach ($invoice->getAllItems() as $item) {
            if (!$item->isDeleted() && !$item->getOrderItem()->getParentItemId()) {
                $product = new DataObject([
                    'sku' => $item->getSku(),
                    'name' => $item->getName(),
                    'price' => $this->getPaidProductPrice($item->getOrderItem()),
                    'quantity' => $item->getOrderItem()->getQtyOrdered(),
                    'position' => $item->getId()
                ]);

                $this->event->dispatch(
                    'elgentos_serversideanalytics_product_item_transport_object',
                    ['product' => $product, 'item' => $item]
                );

                $products[] = $product;
            }
        }

        $trackingDataObject = new DataObject([
            'client_id' => $elgentosSalesOrder->getGaUserId(),
            'ip_override' => $order->getRemoteIp(),
            'document_path' => '/checkout/onepage/success/'
        ]);

        $transactionDataObject = $this->getTransactionDataObject($order, $invoice, $elgentosSalesOrder);

        $this->sendPurchaseEvent($this->gaclient, $transactionDataObject, $products, $trackingDataObject);

        $this->emulation->stopEnvironmentEmulation();
    }

    /**
     * @param $order
     * @param $invoice
     *
     * @return DataObject
     */
    public function getTransactionDataObject($order, $invoice, $elgentosSalesOrder): DataObject
    {
        $transactionDataObject = new DataObject(
            [
                'transaction_id' => $order->getIncrementId(),
                'affiliation' => $order->getStoreName(),
                'currency' => $invoice->getGlobalCurrencyCode(),
                'revenue' => $invoice->getBaseGrandTotal(),
                'tax' => $invoice->getBaseTaxAmount(),
                'shipping' => ($this->getPaidShippingCosts($invoice) ?? 0),
                'coupon_code' => $order->getCouponCode(),
                'session_id' => $elgentosSalesOrder->getGaSessionId(),
                'timestamp_micros' => time()
            ]
        );

        $this->event->dispatch(
            'elgentos_serversideanalytics_transaction_data_transport_object',
            ['transaction_data_object' => $transactionDataObject]
        );

        return $transactionDataObject;
    }

    /**
     * @param $client
     * @param DataObject $transactionDataObject
     * @param array $products
     * @param DataObject $trackingDataObject
     */
    public function sendPurchaseEvent($client, DataObject $transactionDataObject, array $products, DataObject $trackingDataObject)
    {
        try {
            $client->setTransactionData($transactionDataObject);

            $client->addProducts($products);
        } catch (\Exception $e) {
            $this->logger->info($e);
            return;
        }

        try {
            $this->event->dispatch(
                'elgentos_serversideanalytics_tracking_data_transport_object',
                ['tracking_data_object' => $trackingDataObject]
            );

            $client->setTrackingData($trackingDataObject);

            $client->firePurchaseEvent();
        } catch (\Exception $e) {
            $this->logger->info($e);
        }
    }

    /**
     * Get the actual price the customer also saw in it's cart.
     *
     * @param \Magento\Sales\Model\Order\Item $orderItem
     *
     * @return float
     */
    private function getPaidProductPrice(\Magento\Sales\Model\Order\Item $orderItem)
    {
        return $this->scopeConfig->getValue('tax/display/type') == \Magento\Tax\Model\Config::DISPLAY_TYPE_EXCLUDING_TAX
            ? $orderItem->getBasePrice()
            : $orderItem->getBasePriceInclTax();
    }

    /**
     * @param \Magento\Sales\Model\Order\Invoice $invoice
     *
     * @return float
     */
    private function getPaidShippingCosts(\Magento\Sales\Model\Order\Invoice $invoice)
    {
        return $this->scopeConfig->getValue('tax/display/type') == \Magento\Tax\Model\Config::DISPLAY_TYPE_EXCLUDING_TAX
            ? $invoice->getBaseShippingAmount()
            : $invoice->getBaseShippingInclTax();
    }
}
