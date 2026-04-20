<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

require_once __DIR__ . '/version.php';

final class yi_crmblueprint extends CModule
{
    public $MODULE_ID = 'yi.crmblueprint';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = Loc::getMessage('MOVEBX_CRM_BLUEPRINT_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('MOVEBX_CRM_BLUEPRINT_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = Loc::getMessage('MOVEBX_CRM_BLUEPRINT_PARTNER_NAME');
        $this->PARTNER_URI = Loc::getMessage('MOVEBX_CRM_BLUEPRINT_PARTNER_URI');
    }

    public function DoInstall(): void
    {
        RegisterModule($this->MODULE_ID);
    }

    public function DoUninstall(): void
    {
        Option::delete($this->MODULE_ID);
        UnRegisterModule($this->MODULE_ID);
    }
}
