<?php
/**
 * 2007-2016 PrestaShop
 *
 * thirty bees is an extension to the PrestaShop e-commerce software developed by PrestaShop SA
 * Copyright (C) 2017 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2017 thirty bees
 * @copyright 2007-2016 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  PrestaShop is an internationally registered trademark & property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Class DateOfDelivery
 */
class DateOfDelivery extends Module
{
    // @codingStandardsIgnoreStart
    /** @var string $moduleHtml */
    protected $moduleHtml = '';
    /** @var array $fields_form */
    protected $fields_form = [];
    // @codingStandardsIgnoreEnd

    /**
     * DateOfDelivery constructor.
     */
    public function __construct()
    {
        $this->name = 'dateofdelivery';
        $this->tab = 'shipping_logistics';
        $this->version = '2.0.0';
        $this->author = 'thirty bees';
        $this->need_instance = 0;

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('Date of delivery');
        $this->description = $this->l('Displays an approximate date of delivery');
    }

    /**
     * Install this module
     *
     * @return bool
     */
    public function install()
    {
        if (!parent::install()
            || !$this->registerHook('beforeCarrier')
            || !$this->registerHook('orderDetailDisplayed')
            || !$this->registerHook('actionCarrierUpdate')
            || !$this->registerHook('displayPDFInvoice')
        ) {
            return false;
        }

        if (!Db::getInstance()->execute(
            '
		CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'dateofdelivery_carrier_rule` (
			`id_carrier_rule`   INT        NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`id_carrier`        INT        NOT NULL,
			`minimal_time`      INT        NOT NULL,
			`maximal_time`      INT        NOT NULL,
			`delivery_saturday` TINYINT(1) NOT NULL,
			`delivery_sunday`   TINYINT(1) NOT NULL
		) ENGINE ='._MYSQL_ENGINE_.';
		'
        )) {
            return false;
        }

        Configuration::updateValue('DOD_EXTRA_TIME_PRODUCT_OOS', 0);
        Configuration::updateValue('DOD_EXTRA_TIME_PREPARATION', 1);
        Configuration::updateValue('DOD_PREPARATION_SATURDAY', 1);
        Configuration::updateValue('DOD_PREPARATION_SUNDAY', 1);
        Configuration::updateValue('DOD_DATE_FORMAT', 'l j F Y');

        return true;
    }

    /**
     * Uninstall the module
     *
     * @return bool
     */
    public function uninstall()
    {
        Configuration::deleteByName('DOD_EXTRA_TIME_PRODUCT_OOS');
        Configuration::deleteByName('DOD_EXTRA_TIME_PREPARATION');
        Configuration::deleteByName('DOD_PREPARATION_SATURDAY');
        Configuration::deleteByName('DOD_PREPARATION_SUNDAY');
        Configuration::deleteByName('DOD_DATE_FORMAT');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'dateofdelivery_carrier_rule`');

        return parent::uninstall();
    }

    /**
     * Module configuration page
     *
     * @return string
     */
    public function getContent()
    {
        $this->moduleHtml .= '';

        $this->postProcess();
        if (Tools::isSubmit('addCarrierRule') || (Tools::isSubmit('updatedateofdelivery') && Tools::isSubmit('id_carrier_rule'))) {
            $this->moduleHtml .= $this->renderAddForm();
        } else {
            $this->moduleHtml .= $this->renderList();
            $this->moduleHtml .= $this->renderForm();
        }

        return $this->moduleHtml;
    }

    /**
     * @return string
     */
    public function renderAddForm()
    {
        $carriers = Carrier::getCarriers($this->context->language->id, true, false, false, null, Carrier::ALL_CARRIERS);

        foreach ($carriers as $key => $val) {
            $carriers[$key]['name'] = (!$val['name'] ? Configuration::get('PS_SHOP_NAME') : $val['name']);
        }

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Settings'),
                    'icon'  => 'icon-cogs',
                ],
                'input'  => [
                    [
                        'type'    => 'select',
                        'label'   => $this->l('Carrier :'),
                        'name'    => 'id_carrier',
                        'options' => [
                            'query' => $carriers,
                            'id'    => 'id_carrier',
                            'name'  => 'name',
                        ],
                    ],
                    [
                        'type'   => 'text',
                        'label'  => $this->l('Delivery between'),
                        'name'   => 'minimal_time',
                        'suffix' => $this->l('day(s)'),
                    ],
                    [
                        'type'   => 'text',
                        'label'  => $this->l(''),
                        'name'   => 'maximal_time',
                        'suffix' => $this->l('day(s)'),
                    ],
                    [
                        'type'   => 'checkbox',
                        'label'  => $this->l('Delivery option'),
                        'name'   => 'preparation_day',
                        'values' => [
                            'id'    => 'id',
                            'name'  => 'name',
                            'query' => [
                                [
                                    'id'   => 'delivery_saturday',
                                    'name' => $this->l('Delivery on Saturday'),
                                    'val'  => 1,
                                ],
                                [
                                    'id'   => 'delivery_sunday',
                                    'name' => $this->l('Delivery on Sunday'),
                                    'val'  => 1,
                                ],
                            ],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right',
                    'name'  => 'submitCarrierRule',
                ],
            ],
        ];

        if (Tools::getValue('id_carrier_rule') && $this->carrierRuleExists(Tools::getValue('id_carrier_rule'))) {
            $fieldsForm['form']['input'][] = ['type' => 'hidden', 'name' => 'id_carrier_rule'];
        }

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = [];

        $helper->identifier = $this->identifier;

        if (Tools::getValue('id_carrier_rule')) {
            $helper->submit_action = 'updatedateofdelivery';
        } else {
            $helper->submit_action = 'addCarrierRule';
        }
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getCarrierRuleFieldsValues(),
            'languages'    => $this->context->controller->getLanguages(),
            'id_language'  => $this->context->language->id,
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    /**
     * @return array
     */
    public function getCarrierRuleFieldsValues()
    {
        $fields = [
            'id_carrier_rule'   => Tools::getValue('id_carrier_rule'),
            'id_carrier'        => Tools::getValue('id_carrier'),
            'minimal_time'      => Tools::getValue('minimal_time'),
            'maximal_time'      => Tools::getValue('maximal_time'),
            'delivery_saturday' => Tools::getValue('delivery_saturday'),
            'delivery_sunday'   => Tools::getValue('delivery_sunday'),
        ];

        if (Tools::isSubmit('updatedateofdelivery') && $this->carrierRuleExists(Tools::getValue('id_carrier_rule'))) {
            $carrierRule = $this->getCarrierRule(Tools::getValue('id_carrier_rule'));

            $fields['id_carrier_rule'] = Tools::getValue('id_carrier_rule', $carrierRule['id_carrier_rule']);
            $fields['id_carrier'] = Tools::getValue('id_carrier', $carrierRule['id_carrier']);
            $fields['minimal_time'] = Tools::getValue('minimal_time', $carrierRule['minimal_time']);
            $fields['maximal_time'] = Tools::getValue('maximal_time', $carrierRule['maximal_time']);
            $fields['preparation_day_delivery_saturday'] = Tools::getValue('preparation_day_delivery_saturday', $carrierRule['delivery_saturday']);
            $fields['preparation_day_delivery_sunday'] = Tools::getValue('preparation_day_delivery_sunday', $carrierRule['delivery_sunday']);
        }

        return $fields;
    }

    /**
     * @return string
     */
    public function renderList()
    {
        $addUrl = $this->context->link->getAdminLink('AdminModules').'&configure='.$this->name.'&addCarrierRule=1';

        $fieldsList = [
            'name'              => [
                'title' => $this->l('Name of carrier'),
                'type'  => 'text',
            ],
            'delivery_between'  => [
                'title' => $this->l('Delivery between'),
                'type'  => 'text',
            ],
            'delivery_saturday' => [
                'title'  => $this->l('Saturday delivery'),
                'type'   => 'bool',
                'align'  => 'center',
                'active' => 'saturdaystatus',
            ],
            'delivery_sunday'   => [
                'title'  => $this->l('Sunday delivery'),
                'type'   => 'bool',
                'align'  => 'center',
                'active' => 'sundaystatus',
            ],
        ];
        $list = $this->getCarrierRulesWithCarrierName();

        foreach ($list as $key => $val) {
            if (!$val['name']) {
                $list[$key]['name'] = Configuration::get('PS_SHOP_NAME');
            }
            $list[$key]['delivery_between'] = sprintf($this->l('%1$d day(s) and %2$d day(s)'), $val['minimal_time'], $val['maximal_time']);
        }

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = true;
        $helper->identifier = 'id_carrier_rule';
        $helper->actions = ['edit', 'delete'];
        $helper->show_toolbar = false;

        $helper->title = $this->l('Link list');
        $helper->table = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

        $this->context->smarty->assign(['add_url' => $addUrl]);

        return $this->display(__FILE__, 'button.tpl').$helper->generateList($list, $fieldsList).$this->display(__FILE__, 'button.tpl');
    }

    /**
     * @return string
     */
    public function renderForm()
    {
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Settings'),
                    'icon'  => 'icon-cogs',
                ],
                'input'  => [
                    [
                        'type'   => 'text',
                        'label'  => $this->l('Extra time when a product is out of stock'),
                        'name'   => 'extra_time_product_oos',
                        'suffix' => $this->l('day(s)'),
                    ],
                    [
                        'type'   => 'text',
                        'label'  => $this->l('Extra time for preparation of the order'),
                        'name'   => 'extra_time_preparation',
                        'suffix' => $this->l('day(s)'),
                    ],
                    [
                        'type'   => 'checkbox',
                        'label'  => $this->l('Preparation option'),
                        'name'   => 'preparation_day',
                        'values' => [
                            'id'    => 'id',
                            'name'  => 'name',
                            'query' => [
                                [
                                    'id'   => 'preparation_saturday',
                                    'name' => $this->l('Saturday preparation'),
                                    'val'  => 1,
                                ],
                                [
                                    'id'   => 'preparation_sunday',
                                    'name' => $this->l('Sunday preparation'),
                                    'val'  => 1,
                                ],
                            ],
                        ],
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('Date format:'),
                        'name'  => 'date_format',
                        'desc'  => $this->l('You can see all parameters available at:').' <a href="http://www.php.net/manual/en/function.date.php">http://www.php.net/manual/en/function.date.php</a>',
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = [];

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMoreOptions';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages'    => $this->context->controller->getLanguages(),
            'id_language'  => $this->context->language->id,
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    /**
     * @return array
     */
    public function getConfigFieldsValues()
    {
        return [
            'extra_time_product_oos'               => Tools::getValue('extra_time_product_oos', Configuration::get('DOD_EXTRA_TIME_PRODUCT_OOS')),
            'extra_time_preparation'               => Tools::getValue('extra_time_preparation', Configuration::get('DOD_EXTRA_TIME_PREPARATION')),
            'preparation_day_preparation_saturday' => Tools::getValue('preparation_day_preparation_saturday', Configuration::get('DOD_PREPARATION_SATURDAY')),
            'preparation_day_preparation_sunday'   => Tools::getValue('preparation_day_preparation_sunday', Configuration::get('DOD_PREPARATION_SUNDAY')),
            'date_format'                          => Tools::getValue('date_format', Configuration::get('DOD_DATE_FORMAT')),
            'id_carrier'                           => Tools::getValue('id_carrier'),
        ];
    }

    /**
     * @param array $params
     */
    public function hookActionCarrierUpdate($params)
    {
        $newCarrier = $params['carrier'];
        Db::getInstance()->execute('UPDATE `'._DB_PREFIX_.'dateofdelivery_carrier_rule` SET `id_carrier` = '.(int) $newCarrier->id.' WHERE `id_carrier` = '.(int) $params['id_carrier']);
    }

    /**
     * @param array $params
     *
     * @return bool|string
     */
    public function hookBeforeCarrier($params)
    {
        if (!isset($params['delivery_option_list']) || !count($params['delivery_option_list'])) {
            return false;
        }

        $packageList = $params['cart']->getPackageList();

        $datesDelivery = [];
        foreach ($params['delivery_option_list'] as $idAddress => $byAddress) {
            $datesDelivery[$idAddress] = [];
            foreach ($byAddress as $key => $deliveryOption) {
                $dateFrom = null;
                $dateTo = null;
                $datesDelivery[$idAddress][$key] = [];

                foreach ($deliveryOption['carrier_list'] as $idCarrier => $carrier) {
                    foreach ($carrier['package_list'] as $idPackage) {
                        if (isset($packageList[$idAddress][$idPackage])) {
                            $package = $packageList[$idAddress][$idPackage];
                        }

                        $oos = false;
                        if (isset($package['product_list']) && is_array($package['product_list'])) {
                            foreach ($package['product_list'] as $product) {
                                if (StockAvailable::getQuantityAvailableByProduct($product['id_product'], ($product['id_product_attribute'] ? (int) $product['id_product_attribute'] : null), (int) $this->context->shop->id) <= 0) {
                                    $oos = true;
                                }

                                $availableDate = Product::getAvailableDate($product['id_product'], ($product['id_product_attribute'] ? (int) $product['id_product_attribute'] : null));

                                $dateRange = $this->getDatesOfDelivery($idCarrier, $oos, $availableDate);

                                if (isset($dateRange) && (is_null($dateFrom) || $dateFrom < $dateRange[0][1])) {
                                    $dateFrom = $dateRange[0][1];
                                    $datesDelivery[$idAddress][$key][0] = $dateRange[0];
                                }
                                if (isset($dateRange) && (is_null($dateTo) || $dateTo < $dateRange[1][1])) {
                                    $dateTo = $dateRange[1][1];
                                    $datesDelivery[$idAddress][$key][1] = $dateRange[1];
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->smarty->assign(
            [
                'nbPackages'      => $params['cart']->getNbOfPackages(),
                'datesDelivery'   => $datesDelivery,
                'delivery_option' => $params['delivery_option'],
            ]
        );

        return $this->display(__FILE__, 'beforeCarrier.tpl');
    }

    /**
     * @param array $params
     *
     * @return string
     */
    public function hookOrderDetailDisplayed($params)
    {
        $oos = false; // For out of stock management
        /** @var Order $order */
        $order = $params['order'];
        foreach ($order->getProducts() as $product) {
            if ($product['product_quantity_in_stock'] < 1) {
                $oos = true;
            }
        }

        $deliveryDates = $this->getDatesOfDelivery((int) ($order->id_carrier), $oos, $order->date_add);

        if (!is_array($deliveryDates) || !count($deliveryDates)) {
            return '';
        }

        $this->smarty->assign('datesDelivery', $deliveryDates);

        return $this->display(__FILE__, 'orderDetail.tpl');
    }

    /**
     * Displays the delivery dates on the invoice
     *
     * @param array $params contains an instance of OrderInvoice
     *
     * @return string
     *
     */
    public function hookDisplayPDFInvoice($params)
    {
        $orderInvoice = $params['object'];
        if (!($orderInvoice instanceof OrderInvoice)) {
            return '';
        }

        $order = new Order((int) $orderInvoice->id_order);

        $oos = false; // For out of stock management
        foreach ($order->getProducts() as $product) {
            if ($product['product_quantity_in_stock'] < 1) {
                $oos = true;
            }
        }

        $idCarrier = (int) OrderInvoice::getCarrierId($orderInvoice->id);
        $return = '';
        if (($datesDelivery = $this->getDatesOfDelivery($idCarrier, $oos, $orderInvoice->date_add)) && isset($datesDelivery[0][0]) && isset($datesDelivery[1][0])) {
            $return = sprintf($this->l('Approximate date of delivery is between %1$s and %2$s'), $datesDelivery[0][0], $datesDelivery[1][0]);
        }

        return $return;
    }

    /**
     * Post process
     */
    protected function postProcess()
    {
        if (Tools::isSubmit('saturdaystatusdateofdelivery') && $idCarrierRule = Tools::getValue('id_carrier_rule')) {
            if ($this->updateSaturdayStatus($idCarrierRule)) {
                Tools::redirectAdmin(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&conf=4');
            } else {
                Tools::redirectAdmin(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&conf=1');
            }
        }

        if (Tools::isSubmit('sundaystatusdateofdelivery') && $idCarrierRule = Tools::getValue('id_carrier_rule')) {
            if ($this->updateSundayStatus($idCarrierRule)) {
                Tools::redirectAdmin(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&conf=4');
            } else {
                Tools::redirectAdmin(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&conf=1');
            }
        }

        $errors = [];
        if (Tools::isSubmit('submitMoreOptions')) {
            if (Tools::getValue('date_format') == '' || !Validate::isCleanHtml(Tools::getValue('date_format'))) {
                $errors[] = $this->l('Date format is invalid');
            }

            if (!count($errors)) {
                Configuration::updateValue('DOD_EXTRA_TIME_PRODUCT_OOS', (int) Tools::getValue('extra_time_product_oos'));
                Configuration::updateValue('DOD_EXTRA_TIME_PREPARATION', (int) Tools::getValue('extra_time_preparation'));
                Configuration::updateValue('DOD_PREPARATION_SATURDAY', (int) Tools::getValue('preparation_day_preparation_saturday'));
                Configuration::updateValue('DOD_PREPARATION_SUNDAY', (int) Tools::getValue('preparation_day_preparation_sunday'));
                Configuration::updateValue('DOD_DATE_FORMAT', Tools::getValue('date_format'));
                $this->moduleHtml .= $this->displayConfirmation($this->l('Settings are updated'));
            } else {
                $this->moduleHtml .= $this->displayError(implode('<br />', $errors));
            }
        }

        if (Tools::isSubmit('submitCarrierRule')) {
            if (!Validate::isUnsignedInt(Tools::getValue('minimal_time'))) {
                $errors[] = $this->l('Minimum time is invalid');
            }
            if (!Validate::isUnsignedInt(Tools::getValue('maximal_time'))) {
                $errors[] = $this->l('Maximum time is invalid');
            }
            if (($carrier = new Carrier((int) Tools::getValue('id_carrier'))) && !Validate::isLoadedObject($carrier)) {
                $errors[] = $this->l('Carrier is invalid');
            }
            if ($this->isAlreadyDefinedForCarrier((int) ($carrier->id), (int) (Tools::getValue('id_carrier_rule', 0)))) {
                $errors[] = $this->l('This rule has already been defined for this carrier.');
            }

            if (!count($errors)) {
                if (Tools::isSubmit('addCarrierRule')) {
                    if (Db::getInstance()->insert(
                        'dateofdelivery_carrier_rule',
                        [
                            'id_carrier' => (int) $carrier->id,
                            'minimal_time' => (int) Tools::getValue('minimal_time'),
                            'maximal_time' => (int) Tools::getValue('maximal_time'),
                            'delivery_saturday' => (int) Tools::isSubmit('preparation_day_delivery_saturday'),
                            'delivery_sunday' => (int) Tools::isSubmit('preparation_day_delivery_sunday'),
                        ]
                    )) {
                        Tools::redirectAdmin(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&confirmAddCarrierRule');
                    } else {
                        $this->moduleHtml .= $this->displayError($this->l('An error occurred on adding of carrier rule.'));
                    }
                } else {
                    if (Db::getInstance()->execute(
                        '
					UPDATE `'._DB_PREFIX_.'dateofdelivery_carrier_rule`
					SET `id_carrier` = '.(int) $carrier->id.', `minimal_time` = '.(int) Tools::getValue('minimal_time').', `maximal_time` = '.(int) Tools::getValue('maximal_time').', `delivery_saturday` = '.(int) Tools::isSubmit('preparation_day_delivery_saturday').', `delivery_sunday` = '.(int) Tools::isSubmit('preparation_day_delivery_sunday').'
					WHERE `id_carrier_rule` = '.(int) Tools::getValue('id_carrier_rule')
                    )
                    ) {
                        Tools::redirectAdmin(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&confirmupdatedateofdelivery');
                    } else {
                        $this->moduleHtml .= $this->displayError($this->l('An error occurred on updating of carrier rule.'));
                    }
                }

            } else {
                $this->moduleHtml .= $this->displayError(implode('<br />', $errors));
            }
        }

        if (Tools::isSubmit('deletedateofdelivery') && Tools::isSubmit('id_carrier_rule') && (int) Tools::getValue('id_carrier_rule') && $this->carrierRuleExists((int) Tools::getValue('id_carrier_rule'))) {
            $this->deleteByIdCarrierRule((int) Tools::getValue('id_carrier_rule'));
            $this->moduleHtml .= $this->displayConfirmation($this->l('Carrier rule deleted successfully'));
        }

        if (Tools::isSubmit('confirmAddCarrierRule')) {
            $this->moduleHtml = $this->displayConfirmation($this->l('Carrier rule added successfully'));
        }

        if (Tools::isSubmit('confirmupdatedateofdelivery')) {
            $this->moduleHtml = $this->displayConfirmation($this->l('Carrier rule updated successfully'));
        }
    }

    /**
     * @param int $idCarrierRule
     *
     * @return bool
     */
    protected function updateSaturdayStatus($idCarrierRule)
    {
        if (!$this->carrierRuleExists($idCarrierRule)) {
            return false;
        }

        return Db::getInstance()->update(
            'dateofdelivery_carrierrule',
            ['delivery_saturday' => ['type' => 'sql', 'value' => 'NOT `delivery_saturday`']],
            '`id_carrier_rule` = '.(int) $idCarrierRule
        );
    }

    /**
     * Toggle sunday delivery status
     *
     * @param int $idCarrierRule
     *
     * @return bool
     */
    protected function updateSundayStatus($idCarrierRule)
    {
        if (!$this->carrierRuleExists($idCarrierRule)) {
            return false;
        }

        return Db::getInstance()->update(
            'dateofdelivery_carrier_rule',
            ['delivery_sunday' => ['type' => 'sql', 'value' => 'NOT `delivery_sunday`']],
            '`id_carrier_rule` = '.(int) $idCarrierRule
        );
    }

    /**
     * @param int $idCarrierRule
     *
     * @return bool
     */
    protected function carrierRuleExists($idCarrierRule)
    {
        if (!(int) ($idCarrierRule)) {
            return false;
        }

        return (bool) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            (new DbQuery())
                ->select('COUNT(*)')
                ->from('dateofdelivery_carrier_rule')
                ->where('`id_carrier_rule` = '.(int) $idCarrierRule)
        );
    }

    /**
     * @param int $idCarrier
     * @param int $idCarrierRule
     *
     * @return bool
     */
    protected function isAlreadyDefinedForCarrier($idCarrier, $idCarrierRule = 0)
    {
        if (!(int) $idCarrier) {
            return false;
        }

        return (bool) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            (new DbQuery())
                ->select('COUNT(*)')
                ->from('dateofdelivery_carrier_rule')
                ->where('`id_carrier` = '.(int) $idCarrier)
                ->where((int) $idCarrierRule !== 0 ? '`id_carrier_rule` != '.(int) $idCarrierRule : '')
        );
    }

    /**
     * @param int $idCarrierRule
     *
     * @return bool
     */
    protected function deleteByIdCarrierRule($idCarrierRule)
    {
        if (!(int) $idCarrierRule) {
            return false;
        }

        return Db::getInstance()->delete('dateofdelivery_carrier_rule', '`id_carrier_rule` = '.(int) $idCarrierRule);
    }

    /**
     * @param int $idCarrierRule
     *
     * @return array|bool|null|object
     */
    protected function getCarrierRule($idCarrierRule)
    {
        if (!(int) $idCarrierRule) {
            return false;
        }

        return Db::getInstance()->getRow(
            (new DbQuery())
                ->select('*')
                ->from('dateofdelivery_carrier_rule')
                ->where('`id_carrier_rule` = '.(int) $idCarrierRule)
        );
    }

    /**
     * @return array|false|null|PDOStatement
     */
    protected function getCarrierRulesWithCarrierName()
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            (new DbQuery())
                ->select('*')
                ->from('dateofdelivery_carrier_rule', 'dcr')
                ->leftJoin('carrier', 'c', 'c.`id_carrier` = dcr.`id_carrier`')
        );
    }

    /**
     * @param int    $idCarrier
     * @param bool   $productOos
     * @param string $date
     *
     * @return array|bool returns the min & max delivery date
     */
    protected function getDatesOfDelivery($idCarrier, $productOos = false, $date = null)
    {
        if (!(int) $idCarrier) {
            return false;
        }
        $carrierRule = $this->getCarrierRuleWithIdCarrier((int) $idCarrier);
        if (empty($carrierRule)) {
            return false;
        }

        if ($date != null && Validate::isDate($date) && strtotime($date) > time()) {
            $dateNow = strtotime($date);
        } else {
            $dateNow = time();
        } // Date on timestamp format
        if ($productOos) {
            $dateNow += (int) Configuration::get('DOD_EXTRA_TIME_PRODUCT_OOS') * 24 * 3600;
        }
        if (!Configuration::get('DOD_PREPARATION_SATURDAY') && date('l', $dateNow) == 'Saturday') {
            $dateNow += 24 * 3600;
        }
        if (!Configuration::get('DOD_PREPARATION_SUNDAY') && date('l', $dateNow) == 'Sunday') {
            $dateNow += 24 * 3600;
        }

        $dateMinimalTime = $dateNow + ($carrierRule['minimal_time'] * 24 * 3600) + ((int) Configuration::get('DOD_EXTRA_TIME_PREPARATION') * 24 * 3600);
        $dateMaximalTime = $dateNow + ($carrierRule['maximal_time'] * 24 * 3600) + ((int) Configuration::get('DOD_EXTRA_TIME_PREPARATION') * 24 * 3600);

        if (!$carrierRule['delivery_saturday'] && date('l', $dateMinimalTime) == 'Saturday') {
            $dateMinimalTime += 24 * 3600;
            $dateMaximalTime += 24 * 3600;
        }
        if (!$carrierRule['delivery_saturday'] && date('l', $dateMaximalTime) == 'Saturday') {
            $dateMaximalTime += 24 * 3600;
        }

        if (!$carrierRule['delivery_sunday'] && date('l', $dateMinimalTime) == 'Sunday') {
            $dateMinimalTime += 24 * 3600;
            $dateMaximalTime += 24 * 3600;
        }
        if (!$carrierRule['delivery_sunday'] && date('l', $dateMaximalTime) == 'Sunday') {
            $dateMaximalTime += 24 * 3600;
        }

        /*

        // Do not remove this commentary, it's usefull to allow translations of months and days in the translator tool

        $this->l('Sunday');
        $this->l('Monday');
        $this->l('Tuesday');
        $this->l('Wednesday');
        $this->l('Thursday');
        $this->l('Friday');
        $this->l('Saturday');

        $this->l('January');
        $this->l('February');
        $this->l('March');
        $this->l('April');
        $this->l('May');
        $this->l('June');
        $this->l('July');
        $this->l('August');
        $this->l('September');
        $this->l('October');
        $this->l('November');
        $this->l('December');
        */

        $dateMinimalString = '';
        $dateMaximalString = '';
        $dateFormat = preg_split('/([a-z])/Ui', Configuration::get('DOD_DATE_FORMAT'), null, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($dateFormat as $elmt) {
            if ($elmt == 'l' || $elmt == 'F') {
                $dateMinimalString .= $this->l(date($elmt, $dateMinimalTime));
                $dateMaximalString .= $this->l(date($elmt, $dateMaximalTime));
            } elseif (preg_match('/[a-z]/Ui', $elmt)) {
                $dateMinimalString .= date($elmt, $dateMinimalTime);
                $dateMaximalString .= date($elmt, $dateMaximalTime);
            } else {
                $dateMinimalString .= $elmt;
                $dateMaximalString .= $elmt;
            }
        }

        return [
            [
                $dateMinimalString,
                $dateMinimalTime,
            ],
            [
                $dateMaximalString,
                $dateMaximalTime,
            ],
        ];
    }

    /**
     * @param int $idCarrier
     *
     * @return array|bool|null|object
     */
    protected function getCarrierRuleWithIdCarrier($idCarrier)
    {
        if (!(int) $idCarrier) {
            return false;
        }

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow(
            (new DbQuery())
                ->select('*')
                ->from('dateofdelivery_carrier_rule')
                ->where('`id_carrier` = '.(int) $idCarrier)
        );
    }
}
