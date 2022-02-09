<?php

class AdminOmnivaAjaxController extends ModuleAdminController
{
    private $labelsMix = 4;

    public function __construct()
    {
        if (!Context::getContext()->employee->isLoggedBack()) {
            exit('Restricted.');
        }

        parent::__construct();
        $this->parseActions();
    }

    private function parseActions()
    {
        $action = Tools::getValue('action');

        switch ($action) {
            case 'saveorderinfo':
                $this->saveOrderInfo();
                break;
            case 'masssaveorderinfo':
                $this->massSaveorderinfo();
                break;
            case 'printlabels':
                $this->printLabels();
                break;
            case 'bulklabels':
                $this->printBulkLabels();
                break;
            case 'bulkmanifests':
                $this->printBulkManifests();
                break;
            case 'bulkmanifestsall':
                $this->saveManifest();
                break;

        }
    }


    protected function saveOrderInfo()
    {
        if (!empty($this->module->warning)) {
            return false;
        }

        $id_order = (int) Tools::getValue('order_id');

        $omnivaOrder = new OmnivaOrder($id_order);

        $packs = Tools::getValue('packs', 1);
        $weight = Tools::getValue('weight', 0);
        $isCod = Tools::getValue('is_cod', 0);
        $codAmount = Tools::getValue('cod_amount', 0);
        $carrier = Tools::getValue('carrier', 0);

        // Validate fields.
        if ($packs == NULL || !is_numeric($packs) || (int)$packs < 1) {
            return array('error' => 'Bad packs number.');
        }
        if ($weight == NULL || !Validate::isFloat($weight) || $weight <= 0) {
            return array('error' => 'Bad weight.');
        }
        if ($isCod != '0' && $isCod != '1') {
            return array('error' => 'Bad COD value.');
        }
        if ($isCod == '1' && ($codAmount == '' || !Validate::isFloat($codAmount))) {
            return array('error' => 'Bad COD amount.');
        }

        if (!$isCod) {
            $isCod = '0';
        }

        $order = new Order($id_order);
        if (!Validate::isLoadedObject($order)) {
            return array('error' => 'Could not find order.');
        }

        if(Tools::isSubmit('parcel_terminal') && ($id_terminal = (int) Tools::getValue('parcel_terminal')))
        {
            $omnivaCartTerminal = new OmnivaCartTerminal($order->id_cart);
            if(!Validate::isLoadedObject($omnivaCartTerminal))
            {
                $omnivaCartTerminal->force_id = true;
                $omnivaCartTerminal->id = $order->id_cart;
            }
            $omnivaCartTerminal->id_terminal = $id_terminal;
            $omnivaCartTerminal->save();
        }

        if(!Validate::isLoadedObject($omnivaOrder))
        {
            $omnivaOrder->force_id = true;
            $omnivaOrder->id = $order->id;
        }
        $omnivaOrder->packs = $packs;
        $omnivaOrder->weight = $weight;
        $omnivaOrder->cod = $isCod;
        $omnivaOrder->cod_amount = $codAmount;

        if($result = $omnivaOrder->save())
        {
            $selected_carrier = Carrier::getCarrierByReference($carrier);
            $order = new Order($id_order);
            $order_carrier = new OrderCarrier($order->getIdOrderCarrier());
            if ($selected_carrier->id != $order_carrier->id_carrier) {
                $order->id_carrier = $selected_carrier->id;
                $order_carrier->id_carrier = $selected_carrier->id;
                $order_carrier->update();
                $this->context->currency = isset($this->context->currency) ? $this->context->currency : new Currency(Configuration::get('PS_CURRENCY_DEFAULT'));
                $this->module->refreshShippingCost($order);
                $order->update();
            }
        }

        if ($result) {
            $this->module->changeOrderStatus($id_order, $this->module->getCustomOrderState());
            die(json_encode($this->module->l('Order info successfully saved')));
        } else {
            die(json_encode($this->module->l('Order info successfully saved')));
        }
    }

    /**
     * Call API to get label PDF.
     */
    protected function printLabels($orderId = false)
    {
        if (!($orderId = (int) Tools::getValue('order_id'))) {
            echo json_encode(array('error' => $this->module->l('No order ID provided.')));
            exit();
        }

        $order = new Order($orderId);
        $omnivaOrder = new OmnivaOrder($orderId);
        if (!Validate::isLoadedObject($omnivaOrder)) {
            echo json_encode(array('error' => $this->module->l('Order info not saved. Please save before generating labels')));
            exit();
        }

        $status = $this->module->api->createShipment($orderId);
        if (isset($status['barcodes']) && !empty($status['barcodes'])) {
            $order->setWsShippingNumber($status['barcodes'][0]);
            $order->save();
            $this->setOmnivaOrder($orderId);
            echo json_encode($status['barcodes']);
            $label_status = OmnivaltShipping::getShipmentLabels($status['barcodes'], $orderId);
            if (!$label_status['status']) {
                $omnivaOrder->error = addslashes($label_status['msg']);
                $omnivaOrder->update();
                $this->module->changeOrderStatus($orderId, $this->module->getErrorOrderState());
                echo json_encode(array('error' => $label_status['msg']));
                exit();
            }
        } else {
            $omnivaOrder->error = $status['msg'];
            $omnivaOrder->update();
            $this->module->changeOrderStatus($orderId, $this->module->getErrorOrderState());
            echo json_encode(array('error' => $status['msg']));
            exit();
        }
        $omnivaOrder->error = '';
        $omnivaOrder->update();
    }

    protected function printBulkLabels()
    {
        require_once(_PS_MODULE_DIR_ . 'omnivaltshipping/tcpdf/tcpdf.php');
        require_once(_PS_MODULE_DIR_ . 'omnivaltshipping/fpdi/autoload.php');
        $orderIds = trim($_REQUEST['order_ids'], ',');
        $orderIds = explode(',', $orderIds);
        OmnivaltShipping::checkForClass('OrderInfo');
        $object = '';
        $pdf = new \setasign\Fpdi\TcpdfFpdi('P');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        if (is_array($orderIds)) {
            $carrier_ids = OmnivaltShipping::getCarrierIds();
            foreach ($orderIds as $orderId) {
                $orderInfoObj = new OrderInfo();
                $orderInfo = $orderInfoObj->getOrderInfo($orderId);
                if (empty($orderInfo)) {
                    $OrderObj = new OrderInfo();
                    $saveResult = $OrderObj->saveOrderInfo($orderId);
                    $orderInfo = $orderInfoObj->getOrderInfo($orderId);
                }

                if (empty($orderInfo))
                    continue;
                $order = new Order((int)$orderId);
                if (!in_array($order->id_carrier, $carrier_ids))
                    continue;
                $track_number = $order->getWsShippingNumber();
                if ($track_number == '') {

                    $status = $this->module->api->createShipment($orderId);
                    if (isset($status['barcodes']) && !empty($status['barcodes'])) {
                        $order->setWsShippingNumber($status['barcodes'][0]);
                        $order->save();
                        $this->setOmnivaOrder($orderId);

                        $track_number = $status['barcodes'][0];
                        if (file_exists(_PS_MODULE_DIR_ . 'omnivaltshipping/pdf/' . $order->id . '.pdf')) {
                            unlink(_PS_MODULE_DIR_ . 'omnivaltshipping/pdf/' . $order->id . '.pdf');
                        }
                    } else {
                        $orderInfoObj->saveError($orderId, addslashes($status['msg']));
                        $this->module->changeOrderStatus($orderId, $this->module->getErrorOrderState());
                        if (count($orderIds) > 1) {
                            continue;
                        } else {
                            echo $status['msg'];
                            exit();
                        }
                    }
                }
                $label_url = '';
                if (file_exists(_PS_MODULE_DIR_ . 'omnivaltshipping/pdf/' . $order->id . '.pdf')) {
                    $label_url = _PS_MODULE_DIR_ . 'omnivaltshipping/pdf/' . $order->id . '.pdf';
                }
                if ($label_url == '') {
                    $label_status = OmnivaltShipping::getShipmentLabels(array($track_number), $orderId);
                    if ($label_status['status']) {
                        if (file_exists(_PS_MODULE_DIR_ . 'omnivaltshipping/pdf/' . $order->id . '.pdf')) {
                            $label_url = _PS_MODULE_DIR_ . 'omnivaltshipping/pdf/' . $order->id . '.pdf';
                        }
                    } else {
                        $orderInfoObj->saveError($orderId, addslashes($label_status['msg']));
                        $this->module->changeOrderStatus($orderId, $this->module->getErrorOrderState());
                    }
                    if ($label_url == '')
                        continue;
                }
                $this->module->changeOrderStatus($orderId, $this->module->getCustomOrderState());
                $pagecount = $pdf->setSourceFile($label_url);
                if (file_exists($label_url)) {
                    unlink($label_url);
                }

                $print_type = Configuration::get('omnivalt_print_type');
                if ($print_type === 'single') {
                    for ($i = 1; $i <= $pagecount; $i++) {
                        $tplidx = $pdf->ImportPage($i);
                        $s = $pdf->getTemplatesize($tplidx);
                        $pdf->AddPage('P', array($s['width'], $s['height']));
                        $pdf->useTemplate($tplidx);
                    }
                } else {
                    $newPG = array(0, 4, 8, 12, 16, 20, 24, 28, 32);
                    if ($this->labelsMix >= 4) {
                        $pdf->AddPage();
                        $page = 1;
                        $templateId = $pdf->importPage($page);
                        $this->labelsMix = 0;
                    }
                    $tplidx = $pdf->ImportPage(1);
                    if ($this->labelsMix == 0) {
                        $pdf->useTemplate($tplidx, 5, 15, 94.5, 108, false);
                    } else if ($this->labelsMix == 1) {
                        $pdf->useTemplate($tplidx, 110, 15, 94.5, 108, false);
                    } else if ($this->labelsMix == 2) {
                        $pdf->useTemplate($tplidx, 5, 160, 94.5, 108, false);
                    } else if ($this->labelsMix == 3) {
                        $pdf->useTemplate($tplidx, 110, 160, 94.5, 108, false);
                    } else {
                        echo $this->module->l('Problems with labels count, please, select one order!!!');
                        exit();
                    }
                    $this->labelsMix++;
                }
            }
        }
        $pdf->Output('Omnivalt_labels.pdf', 'I');
    }

    public function setOmnivaOrder($id_order = 0)
    {
        $omnivaOrder = new OmnivaOrder($id_order);
        if(!Validate::isLoadedObject($omnivaOrder))
        {
            return false;
        }

        if(!$omnivaOrder->manifest)
        {
            $omnivaOrder->manifest = (int) Configuration::get('omnivalt_manifest');
        }
    }

    public function saveManifest()
    {
        if (Tools::getValue('type') == 'new') {
            if (Tools::getValue('order_ids') == null) {
                print $this->module->l('Here is nothing to print!!!');
                exit();
            }
        }
        if (Tools::getValue('type') == 'skip') {
            $orderIds = trim(Tools::getValue('order_ids'), ',');
            $orderIds = explode(',', $orderIds);
            foreach ($orderIds as $order_id)
            {
                $omnivaOrder = new OmnivaOrder($order_id);
                if(Validate::isLoadedObject($omnivaOrder))
                {
                    $omnivaOrder->manifest = -1;
                    $omnivaOrder->update();
                }

            }
        }
        $this->printBulkManifests();

    }

    protected function printBulkManifests()
    {
        $cookie = $this->context->cookie;
        require_once(_PS_MODULE_DIR_ . 'omnivaltshipping/tcpdf/tcpdf.php');

        $lang = Configuration::get('omnivalt_manifest_lang');
        if (empty($lang)) $lang = 'en';
        $orderIds = trim($_REQUEST['order_ids'], ',');
        $orderIds = explode(',', $orderIds);
        OmnivaltShipping::checkForClass('OrderInfo');
        $orderInfoObj = new OrderInfo();
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $order_table = '';
        $count = 1;
        if (is_array($orderIds)) {
            $carrier_ids = OmnivaltShipping::getCarrierIds();
            $carrier_terminal_ids = OmnivaltShipping::getCarrierIds(['omnivalt_pt']);
            foreach ($orderIds as $orderId) {
                if (!$orderId)
                    continue;
                $orderInfo = new OmnivaOrder($orderId);
                if (!Validate::isLoadedObject($orderInfo))
                    continue;
                $order = new Order((int)$orderId);
                if (!in_array($order->id_carrier, $carrier_ids))
                    continue;
                $track_number = $order->getWsShippingNumber();
                if ($track_number == '') {
                    $status = $this->module->api->createShipment($orderId);
                    if (isset($status['barcodes']) && !empty($status['barcodes'])) {
                        $order->setWsShippingNumber($status['barcodes'][0]);
                        $order->save();
                        $track_number = $status['barcodes'][0];
                        if (file_exists(_PS_MODULE_DIR_ . 'omnivaltshipping/pdf/' . $order->id . '.pdf')) {
                            unlink(_PS_MODULE_DIR_ . 'omnivaltshipping/pdf/' . $order->id . '.pdf');
                        }
                    } else {
                        $orderInfoObj->saveError($orderId, addslashes($status['msg']));
                        $this->module->changeOrderStatus($orderId, $this->module->getErrorOrderState());
                        if (count($orderIds) > 1) {
                            continue;
                        } else {
                            echo $status['msg'];
                            exit();
                        }
                    }
                }
                $this->setOmnivaOrder($orderId);
                $pt_address = '';
                if (in_array($order->id_carrier, $carrier_terminal_ids)) {
                    $cart = new OmnivaCartTerminal($order->id_cart);
                    $pt_address = OmnivaltShipping::getTerminalAddress($cart->id_terminal);
                }

                $address = new Address($order->id_address_delivery);
                $client_address = $address->firstname . ' ' . $address->lastname . ', ' . $address->address1 . ', ' . $address->postcode . ', ' . $address->city . ' ' . $address->country;
                if ($pt_address != '')
                    $client_address = '';
                $order_table .= '<tr><td width = "40" align="right">' . $count . '.</td><td>' . $track_number . '</td><td width = "60">' . date('Y-m-d') . '</td><td width = "40">' . $orderInfo['packs'] . '</td><td width = "60">' . ($orderInfo['packs'] * $orderInfo['weight']) . '</td><td width = "210">' . $client_address . $pt_address . '</td></tr>';
                $count++;
                //make order shipped after creating manifest
                $history = new OrderHistory();
                $history->id_order = (int)$orderId;
                $history->id_employee = (int)$cookie->id_employee;
                $history->changeIdOrderState((int)Configuration::get('PS_OS_SHIPPING'), $order);
                $history->add();

            }
        }
        $pdf->SetFont('freeserif', '', 14);
        $id_lang = $cookie->id_lang;

        $shop_country = new Country(Country::getByIso(Configuration::get('omnivalt_countrycode')));

        $shop_addr = '<table cellspacing="0" cellpadding="1" border="0"><tr><td>' . date('Y-m-d H:i:s') . '</td><td>' . OmnivaltShipping::getTranslate('Sender address', $lang) . ':<br/>' . Configuration::get('omnivalt_company') . '<br/>' . Configuration::get('omnivalt_address') . ', ' . Configuration::get('omnivalt_postcode') . '<br/>' . Configuration::get('omnivalt_city') . ', ' . $shop_country->name[$id_lang] . '<br/></td></tr></table>';

        $pdf->writeHTML($shop_addr, true, false, false, false, '');
        $tbl = '
        <table cellspacing="0" cellpadding="4" border="1">
          <thead>
            <tr>
              <th width = "40" align="right">' . OmnivaltShipping::getTranslate('No.', $lang) . '</th>
              <th>' . OmnivaltShipping::getTranslate('Shipment number', $lang) . '</th>
              <th width = "60">' . OmnivaltShipping::getTranslate('Date', $lang) . '</th>
              <th width = "40">' . OmnivaltShipping::getTranslate('Amount', $lang) . '</th>
              <th width = "60">' . OmnivaltShipping::getTranslate('Weight (kg)', $lang) . '</th>
              <th width = "210">' . OmnivaltShipping::getTranslate('Recipient address', $lang) . '</th>
            </tr>
          </thead>
          <tbody>
            ' . $order_table . '
          </tbody>
        </table><br/><br/>
        ';
        $pdf->SetFont('freeserif', '', 9);
        $pdf->writeHTML($tbl, true, false, false, false, '');
        $pdf->SetFont('freeserif', '', 14);
        $sign = OmnivaltShipping::getTranslate('Courier name, surname, signature', $lang) . ' ________________________________________________<br/><br/>';
        $sign .= OmnivaltShipping::getTranslate('Sender name, surname, signature', $lang) . ' ________________________________________________';
        $pdf->writeHTML($sign, true, false, false, false, '');
        $pdf->Output('Omnivalt_manifest.pdf', 'I');


        if (Tools::getValue('type') == 'new') {

            $current = intval(Configuration::get('omnivalt_manifest'));
            $current++;
            Configuration::updateValue('omnivalt_manifest', $current);
        }
    }
}