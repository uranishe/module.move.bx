<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Yi\CrmBlueprint\Service\ModuleFacade;
use Yi\CrmBlueprint\Storage\FileStorage;

Loc::loadMessages(__FILE__);

$moduleId = 'yi.crmblueprint';
$right = $APPLICATION->GetGroupRight($moduleId);
if ($right < 'R')
{
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}

if (!Loader::includeModule($moduleId))
{
    echo BeginNote();
    echo 'Модуль не установлен.';
    echo EndNote();
    return;
}

$storage = new FileStorage();
$facade = new ModuleFacade($storage);
$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
$result = null;
$exceptionMessage = '';

$sourceUrl = trim((string)Option::get($moduleId, 'source_url', ''));
$replaceAutomation = Option::get($moduleId, 'replace_automation', 'Y') === 'Y';

if ($request->isPost() && check_bitrix_sessid())
{
    $sourceUrl = trim((string)$request->getPost('source_url'));
    $replaceAutomation = $request->getPost('replace_automation') === 'Y';

    Option::set($moduleId, 'source_url', $sourceUrl);
    Option::set($moduleId, 'replace_automation', $replaceAutomation ? 'Y' : 'N');

    try
    {
        if ($request->getPost('movebx_export') !== null)
        {
            $result = $facade->runExport();
            Option::set($moduleId, 'last_export_path', (string)$result['output_path']);
            Option::set($moduleId, 'last_export_url', (string)$result['output_url']);
        }
        elseif ($request->getPost('movebx_import_dry') !== null)
        {
            if ($sourceUrl === '')
            {
                throw new \RuntimeException(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_ERROR_NO_URL'));
            }

            $result = $facade->runImportFromUrl($sourceUrl, 'dry-run', $replaceAutomation);
            Option::set($moduleId, 'last_import_mode', 'dry-run');
            Option::set($moduleId, 'last_import_report_path', (string)$result['report_path']);
            Option::set($moduleId, 'last_import_report_url', (string)$result['report_url']);
            Option::set($moduleId, 'last_import_source_local_url', (string)$result['source_local_url']);
        }
        elseif ($request->getPost('movebx_import_apply') !== null)
        {
            if ($sourceUrl === '')
            {
                throw new \RuntimeException(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_ERROR_NO_URL'));
            }

            $result = $facade->runImportFromUrl($sourceUrl, 'apply', $replaceAutomation);
            Option::set($moduleId, 'last_import_mode', 'apply');
            Option::set($moduleId, 'last_import_report_path', (string)$result['report_path']);
            Option::set($moduleId, 'last_import_report_url', (string)$result['report_url']);
            Option::set($moduleId, 'last_import_source_local_url', (string)$result['source_local_url']);
        }
    }
    catch (\Throwable $e)
    {
        $exceptionMessage = $e->getMessage();
    }
}

$tabs = [[
    'DIV' => 'movebx_crm_blueprint_main',
    'TAB' => Loc::getMessage('MOVEBX_CRM_BLUEPRINT_TAB_MAIN'),
    'TITLE' => Loc::getMessage('MOVEBX_CRM_BLUEPRINT_TAB_MAIN_TITLE'),
]];

function movebxCrmBlueprintRenderLinkRow(string $title, string $url): void
{
    if ($url === '')
    {
        return;
    }

    echo '<tr>';
    echo '<td width="40%">' . htmlspecialcharsbx($title) . '</td>';
    echo '<td width="60%"><a href="' . htmlspecialcharsbx($url) . '" target="_blank">' . htmlspecialcharsbx($url) . '</a></td>';
    echo '</tr>';
}

function movebxCrmBlueprintCollectSummary(array $reportData): array
{
    $summary = [
        'warnings' => (int)($reportData['summary']['warnings_count'] ?? 0),
        'errors' => (int)($reportData['summary']['errors_count'] ?? 0),
        'field_collisions' => 0,
        'fields_created' => 0,
        'fields_updated' => 0,
        'bp_created' => 0,
        'bp_updated' => 0,
    ];

    foreach ((array)($reportData['entities'] ?? []) as $entityData)
    {
        $summary['field_collisions'] += count((array)($entityData['fields']['collisions'] ?? []));
        $summary['fields_created'] += count((array)($entityData['fields']['created'] ?? []));
        $summary['fields_updated'] += count((array)($entityData['fields']['updated'] ?? []));
        $summary['bp_created'] += count((array)($entityData['business_processes']['created'] ?? []));
        $summary['bp_updated'] += count((array)($entityData['business_processes']['updated'] ?? []));
    }

    return $summary;
}

if ($exceptionMessage !== '')
{
    CAdminMessage::ShowMessage($exceptionMessage);
}
elseif (is_array($result))
{
    if (isset($result['output_path']))
    {
        CAdminMessage::ShowNote(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_RESULT_EXPORT_OK'));
    }
    elseif (!empty($result['success']))
    {
        CAdminMessage::ShowNote(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_RESULT_IMPORT_OK'));
    }
    else
    {
        CAdminMessage::ShowMessage(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_RESULT_IMPORT_FAIL'));
    }
}

echo BeginNote();
echo htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_NOTE'));
echo EndNote();

$tabControl = new CAdminTabControl('movebxCrmBlueprintTabControl', $tabs);
$tabControl->Begin();
?>
<form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPageParam()) ?>">
    <?php
    echo bitrix_sessid_post();
    $tabControl->BeginNextTab();
    ?>
    <tr class="heading">
        <td colspan="2"><?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_SECTION_EXPORT')) ?></td>
    </tr>
    <tr>
        <td width="40%">&nbsp;</td>
        <td width="60%">
            <input type="submit" name="movebx_export" value="<?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_EXPORT_BUTTON')) ?>" class="adm-btn-save">
        </td>
    </tr>
    <?php
    movebxCrmBlueprintRenderLinkRow(
        Loc::getMessage('MOVEBX_CRM_BLUEPRINT_LAST_EXPORT'),
        (string)Option::get($moduleId, 'last_export_url', '')
    );
    ?>
    <tr class="heading">
        <td colspan="2"><?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_SECTION_IMPORT')) ?></td>
    </tr>
    <tr>
        <td width="40%"><?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_SOURCE_URL')) ?></td>
        <td width="60%">
            <input type="text" name="source_url" value="<?= htmlspecialcharsbx($sourceUrl) ?>" size="80">
        </td>
    </tr>
    <tr>
        <td width="40%"><?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_REPLACE_AUTOMATION')) ?></td>
        <td width="60%">
            <input type="checkbox" name="replace_automation" value="Y"<?= $replaceAutomation ? ' checked' : '' ?>>
        </td>
    </tr>
    <tr>
        <td width="40%">&nbsp;</td>
        <td width="60%">
            <input type="submit" name="movebx_import_dry" value="<?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_IMPORT_DRY_BUTTON')) ?>" class="adm-btn">
            <input type="submit" name="movebx_import_apply" value="<?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_IMPORT_APPLY_BUTTON')) ?>" class="adm-btn-save" onclick="return confirm('Будет выполнен реальный импорт данных в портал. Продолжить?');">
        </td>
    </tr>
    <?php
    movebxCrmBlueprintRenderLinkRow(
        Loc::getMessage('MOVEBX_CRM_BLUEPRINT_SOURCE_LINK'),
        (string)Option::get($moduleId, 'last_import_source_local_url', '')
    );
    movebxCrmBlueprintRenderLinkRow(
        Loc::getMessage('MOVEBX_CRM_BLUEPRINT_LAST_IMPORT'),
        (string)Option::get($moduleId, 'last_import_report_url', '')
    );
    ?>
<?php
$tabControl->End();
?>
</form>
<?php

if (is_array($result) && !empty($result['report_data']))
{
    $summary = movebxCrmBlueprintCollectSummary((array)$result['report_data']);
    ?>
    <div style="margin-top: 20px;">
        <table class="adm-detail-content-table edit-table">
            <tr class="heading">
                <td colspan="2"><?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_SUMMARY')) ?></td>
            </tr>
            <tr>
                <td width="40%"><?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_SUMMARY_WARNINGS')) ?></td>
                <td width="60%"><?= (int)$summary['warnings'] ?></td>
            </tr>
            <tr>
                <td><?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_SUMMARY_ERRORS')) ?></td>
                <td><?= (int)$summary['errors'] ?></td>
            </tr>
            <tr>
                <td><?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_SUMMARY_FIELD_COLLISIONS')) ?></td>
                <td><?= (int)$summary['field_collisions'] ?></td>
            </tr>
            <tr>
                <td><?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_SUMMARY_FIELDS_CREATED')) ?></td>
                <td><?= (int)$summary['fields_created'] ?></td>
            </tr>
            <tr>
                <td><?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_SUMMARY_FIELDS_UPDATED')) ?></td>
                <td><?= (int)$summary['fields_updated'] ?></td>
            </tr>
            <tr>
                <td><?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_SUMMARY_BP_CREATED')) ?></td>
                <td><?= (int)$summary['bp_created'] ?></td>
            </tr>
            <tr>
                <td><?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_SUMMARY_BP_UPDATED')) ?></td>
                <td><?= (int)$summary['bp_updated'] ?></td>
            </tr>
            <?php if (!empty($result['report_url'])): ?>
                <tr>
                    <td><?= htmlspecialcharsbx(Loc::getMessage('MOVEBX_CRM_BLUEPRINT_REPORT_LINK')) ?></td>
                    <td><a href="<?= htmlspecialcharsbx((string)$result['report_url']) ?>" target="_blank"><?= htmlspecialcharsbx((string)$result['report_url']) ?></a></td>
                </tr>
            <?php endif; ?>
        </table>
    </div>
    <?php
}
