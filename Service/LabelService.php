<?php

namespace DpdLabel\Service;

use DpdLabel\DpdLabel;
use DpdLabel\Model\DpdlabelLabels;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Core\Translation\Translator;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CountryQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderAddressQuery;
use Thelia\Model\OrderQuery;
use Thelia\Tools\URL;

class LabelService
{
    protected $dispatcher;

    /**
     * UpdateDeliveryAddressListener constructor.
     * @param EventDispatcherInterface|null $dispatcher
     */
    public function __construct(EventDispatcherInterface $dispatcher = null)
    {
        $this->dispatcher = $dispatcher;
    }

    public function generateLabel($data)
    {
        $orderId = $data['order_id'];
        $weight = $data['weight'];

        if (!$orderId) {
            return new JsonResponse([
                'error' => "order_id argument not found"
            ]);
        }

        if (!$weight) {
            return new JsonResponse([
                'error' => "weight argument not found"
            ]);
        }

        $order = OrderQuery::create()->filterById($orderId)->findOne();
        $labelName = DpdLabel::DPD_LABEL_DIR . DS . $order->getRef() . ".pdf";

        $label = $this->createLabel($order, $labelName, $weight);

        if (is_string($label)) {
            return new JsonResponse([
                'error' => $label
            ]);
        }

        return new JsonResponse([
            'id' => $label->getId(),
            'url' => URL::getInstance()->absoluteUrl('/admin/module/DpdLabel/getLabel/' . $order->getRef()),
            'number' => $order->getRef(),
            'order' => [
                'id' => $order->getId(),
                'status' => [
                    'id' => $order->getOrderStatus()->getId()
                ]
            ]
        ]);
    }

    /**
     * @param Order $order
     * @param $labelName
     * @param $weight
     * @param int $retour
     * @return DpdlabelLabels|string
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function createLabel(Order $order, $labelName, $weight, $retour = null)
    {
        $data = $this->writeData($order, $weight, $retour);

        $DpdWSD = DpdLabel::DPD_WSDL;

        if (1 === (int)DpdLabel::getConfigValue(DpdLabel::API_IS_TEST)) {
            $DpdWSD = DpdLabel::DPD_WSDL_TEST;
        }

        $client = new \SoapClient($DpdWSD, array("trace" => 1, "exception" => 1, 'encoding' => 'ISO-8859-1'));

        try {
            $header = new \SoapHeader('http://www.cargonet.software', 'UserCredentials', $data["Header"]);
            $client->__setSoapHeaders($header);
            if ($retour) {
                $response = $client->CreateReverseInverseShipmentWithLabels(["request" => $data["Body"]]);
            } else {
                $response = $client->CreateShipmentWithLabels(["request" => $data["Body"]]);
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        if ($retour) {
            $shipments = $response->CreateReverseInverseShipmentWithLabelsResult->shipment;
            $labels = $response->CreateReverseInverseShipmentWithLabelsResult->labels->Label;
        } else {
            $shipments = $response->CreateShipmentWithLabelsResult->shipments->Shipment;
            $labels = $response->CreateShipmentWithLabelsResult->labels->Label;
        }

        if (false === @file_put_contents($labelName, $labels[0]->label)) {
            return Translator::getInstance()->trans("L'étiquette n'a pas pu être sauvegardée dans $labelName", DpdLabel::DOMAIN_NAME);
        }


        $label = new DpdlabelLabels();
        $label
            ->setOrderId($order->getId())
            ->setLabelNumber($shipments->parcelnumber)
            ->save();

        return $label;
    }

    /**
     * @param Order $order
     * @param $weight
     * @param null $retour
     * @return mixed
     * @throws \Propel\Runtime\Exception\PropelException
     */
    protected function writeData(Order $order, $weight, $retour = null)
    {

        $data = DpdLabel::getApiConfig();

        $shopCountry = CountryQuery::create()->filterById(ConfigQuery::create()->filterByName("store_country")->findOne()->getValue())->findOne();

        $ApiData["Header"] = [
            "userid" => $data['userId'],
            "password" => $data['password']
        ];

        $deliveryAddress = OrderAddressQuery::create()->filterById($order->getDeliveryOrderAddressId())->findOne();

        $receiveraddress = [
            'name' => $deliveryAddress->getFirstname() . ' ' . $deliveryAddress->getLastname(),
            'countryPrefix' => $deliveryAddress->getCountry()->getIsoalpha2(),
            'city' => $deliveryAddress->getCity(),
            'zipCode' => $deliveryAddress->getZipcode(),
            'street' => $deliveryAddress->getAddress1(),
            'phoneNumber' => $deliveryAddress->getPhone() ?: "x",
            'faxNumber' => '',
            'geoX' => '',
            'geoY' => ''
        ];

        $shipperaddress = [
            'name' => $data['shipperName'],
            'countryPrefix' => $data['shipperCountry'],
            'city' => $data['shipperCity'],
            'zipCode' => $data['shipperZipCode'],
            'street' => $data['shipperAddress1'],
            'phoneNumber' => $data['shipperPhone'],
            'faxNumber' => $data['shipperFax'],
            'geoX' => '',
            'geoY' => ''
        ];

        $label = array(
            'type' => 'PDF',
        );

        $ApiData["Body"] = [
            "customer_countrycode" => (int)$shopCountry->getIsocode(),
            "customer_centernumber" => (int)$data['center_number'],
            "customer_number" => (int)$data['customer_number'],
            "receiveraddress" => $receiveraddress,
            "shipperaddress" => $shipperaddress,
            "weight" => $weight,
            "referencenumber" => $order->getRef(),
            "labelType" => $label
        ];

        if ($retour) {
            $ApiData["Body"]["expire_offset"] = 30;
            $ApiData["Body"]["refasbarcode"] = false;
        }

        return $ApiData;
    }
}