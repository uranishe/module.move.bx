<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', 'Y');
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

function crmBlueprintImporterFindDocumentRoot(): ?string
{
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string)$_SERVER['DOCUMENT_ROOT']) : '';
    if ($documentRoot !== '' && is_file($documentRoot . '/bitrix/modules/main/include/prolog_before.php'))
    {
        return rtrim($documentRoot, '/');
    }

    $directory = __DIR__;
    while ($directory !== '' && $directory !== '/' && $directory !== '.')
    {
        if (is_file($directory . '/bitrix/modules/main/include/prolog_before.php'))
        {
            return $directory;
        }

        $parent = dirname($directory);
        if ($parent === $directory)
        {
            break;
        }

        $directory = $parent;
    }

    return null;
}

final class CrmPortalBlueprintImporter
{
    private const SCHEMA_VERSION = '1.0.0';

    private const ENTITY_MAP = [
        'lead' => [
            'title' => 'Лид',
            'entity_type_id' => 1,
            'user_field_entity_id' => 'CRM_LEAD',
            'document_type' => ['crm', 'CCrmDocumentLead', 'LEAD'],
            'status_entity_id' => 'STATUS',
            'has_funnels' => true,
        ],
        'deal' => [
            'title' => 'Сделка',
            'entity_type_id' => 2,
            'user_field_entity_id' => 'CRM_DEAL',
            'document_type' => ['crm', 'CCrmDocumentDeal', 'DEAL'],
            'status_entity_id' => 'DEAL_STAGE',
            'has_funnels' => true,
        ],
        'contact' => [
            'title' => 'Контакт',
            'entity_type_id' => 3,
            'user_field_entity_id' => 'CRM_CONTACT',
            'document_type' => ['crm', 'CCrmDocumentContact', 'CONTACT'],
            'status_entity_id' => null,
            'has_funnels' => false,
        ],
        'company' => [
            'title' => 'Компания',
            'entity_type_id' => 4,
            'user_field_entity_id' => 'CRM_COMPANY',
            'document_type' => ['crm', 'CCrmDocumentCompany', 'COMPANY'],
            'status_entity_id' => null,
            'has_funnels' => false,
        ],
    ];

    private array $blueprint = [];
    private array $options = [];
    private array $warnings = [];
    private array $errors = [];
    private array $report = [];
    private array $fieldNameMap = [];
    private array $stageMap = [];
    private array $statusEntityMap = [];
    private array $categoryMap = [];
    private array $enumMap = [];
    private array $tableColumnsCache = [];
    private int $dryRunNextDealCategoryId = 0;

    public function import(string $inputPath, array $options = []): array
    {
        $this->options = $options;
        $this->assertEnvironment();

        if (!Loader::includeModule('crm'))
        {
            throw new RuntimeException('Не удалось подключить модуль crm.');
        }

        if (!Loader::includeModule('bizproc'))
        {
            $this->warnings[] = 'Модуль bizproc не подключен. Импорт БП будет пропущен.';
        }

        $this->blueprint = $this->loadBlueprint($inputPath);
        $reportPath = $this->resolveReportPath($options['report'] ?? null);

        $this->initializeReport($inputPath, $reportPath);

        foreach (['lead', 'deal', 'contact', 'company'] as $entityCode)
        {
            $this->importEntity($entityCode);
        }

        $this->report['warnings'] = $this->warnings;
        $this->report['errors'] = $this->errors;
        $this->report['success'] = $this->errors === [];
        $this->report['summary'] = [
            'warnings_count' => count($this->warnings),
            'errors_count' => count($this->errors),
        ];
        $this->report['replacements'] = [
            'fields' => $this->fieldNameMap,
            'stages' => $this->stageMap,
            'status_entities' => $this->statusEntityMap,
            'enum_ids' => $this->enumMap,
        ];

        $this->saveJson($reportPath, $this->report);

        return [
            'success' => $this->errors === [],
            'mode' => $this->isApplyMode() ? 'apply' : 'dry-run',
            'input_path' => $inputPath,
            'report_path' => $reportPath,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }

    private function assertEnvironment(): void
    {
        global $USER;

        if (PHP_SAPI === 'cli')
        {
            return;
        }

        if (!is_object($USER) || !method_exists($USER, 'IsAdmin') || !$USER->IsAdmin())
        {
            throw new RuntimeException('Импорт нужно запускать под администратором портала.');
        }
    }

    private function initializeReport(string $inputPath, string $reportPath): void
    {
        $this->report = [
            'success' => false,
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => date(DATE_ATOM),
            'mode' => $this->isApplyMode() ? 'apply' : 'dry-run',
            'input_path' => $inputPath,
            'report_path' => $reportPath,
            'portal' => [
                'site_name' => defined('SITE_SERVER_NAME') ? SITE_SERVER_NAME : ($_SERVER['SERVER_NAME'] ?? ''),
                'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
                'php_version' => PHP_VERSION,
                'bitrix_version' => defined('SM_VERSION') ? SM_VERSION : null,
            ],
            'entities' => [],
            'warnings' => [],
            'errors' => [],
            'summary' => [],
            'replacements' => [],
        ];

        foreach (self::ENTITY_MAP as $entityCode => $definition)
        {
            $this->report['entities'][$entityCode] = [
                'title' => $definition['title'],
                'funnels' => [],
                'fields' => [
                    'created' => [],
                    'updated' => [],
                    'collisions' => [],
                    'skipped' => [],
                ],
                'business_processes' => [
                    'created' => [],
                    'updated' => [],
                    'skipped' => [],
                ],
                'automation' => [
                    'robots' => [],
                    'triggers' => [],
                ],
            ];
        }
    }

    private function importEntity(string $entityCode): void
    {
        $definition = self::ENTITY_MAP[$entityCode];
        $entityData = $this->blueprint['entities'][$entityCode] ?? null;
        if (!is_array($entityData))
        {
            $this->warnings[] = 'В blueprint отсутствует секция сущности ' . $entityCode . '.';
            return;
        }

        try
        {
            if ($definition['has_funnels'])
            {
                $this->importFunnels($entityCode, $entityData, $definition);
            }

            $this->importUserFields($entityCode, $entityData, $definition);
            $this->importBusinessProcesses($entityCode, $entityData, $definition);
            $this->importAutomation($entityCode, $entityData, $definition);
        }
        catch (\Throwable $e)
        {
            $this->errors[] = 'Ошибка импорта сущности ' . $entityCode . ': ' . $e->getMessage();
        }
    }

    private function importFunnels(string $entityCode, array $entityData, array $definition): void
    {
        $funnels = $entityData['funnels'] ?? [];
        if (!is_array($funnels) || $funnels === [])
        {
            $this->warnings[] = 'Для сущности ' . $entityCode . ' в blueprint нет воронок/статусов.';
            return;
        }

        if ($entityCode === 'lead')
        {
            $statuses = (array)($funnels[0]['statuses'] ?? []);
            $this->importStatuses($entityCode, 0, (string)$definition['status_entity_id'], $statuses);
            return;
        }

        if ($entityCode !== 'deal')
        {
            return;
        }

        foreach ($funnels as $funnel)
        {
            $sourceCategoryId = (int)($funnel['id'] ?? 0);
            $targetCategoryId = $this->resolveDealCategoryTargetId($funnel);

            $this->categoryMap[$sourceCategoryId] = $targetCategoryId;
            $sourceStatusEntityId = (string)($funnel['status_entity_id'] ?? ($sourceCategoryId > 0 ? 'DEAL_STAGE_' . $sourceCategoryId : 'DEAL_STAGE'));
            $targetStatusEntityId = $this->convertDealCategoryToStatusEntityId($targetCategoryId);
            $this->statusEntityMap[$sourceStatusEntityId] = $targetStatusEntityId;

            $this->report['entities']['deal']['funnels'][] = [
                'source_category_id' => $sourceCategoryId,
                'source_name' => $funnel['name'] ?? '',
                'target_category_id' => $targetCategoryId,
                'target_status_entity_id' => $targetStatusEntityId,
            ];

            $this->importStatuses('deal', $targetCategoryId, $targetStatusEntityId, (array)($funnel['statuses'] ?? []), $sourceCategoryId);
        }
    }

    private function resolveDealCategoryTargetId(array $funnel): int
    {
        $sourceCategoryId = (int)($funnel['id'] ?? 0);
        $name = trim((string)($funnel['name'] ?? ''));
        $sort = isset($funnel['sort']) ? (int)$funnel['sort'] : 0;

        if ($sourceCategoryId === 0)
        {
            if ($name !== '' && $this->isApplyMode() && class_exists('\\Bitrix\\Crm\\Category\\DealCategory'))
            {
                try
                {
                    \Bitrix\Crm\Category\DealCategory::update(0, ['NAME' => $name, 'SORT' => $sort]);
                }
                catch (\Throwable $e)
                {
                    $this->warnings[] = 'Не удалось обновить название/сортировку дефолтного направления сделок: ' . $e->getMessage();
                }
            }

            return 0;
        }

        $existingCategories = $this->getDealCategories();

        foreach ($existingCategories as $existingCategory)
        {
            if ($name !== '' && isset($existingCategory['NAME']) && (string)$existingCategory['NAME'] === $name)
            {
                $targetId = (int)$existingCategory['ID'];
                if ($this->isApplyMode() && class_exists('\\Bitrix\\Crm\\Category\\DealCategory'))
                {
                    try
                    {
                        \Bitrix\Crm\Category\DealCategory::update($targetId, ['NAME' => $name, 'SORT' => $sort]);
                    }
                    catch (\Throwable $e)
                    {
                        $this->warnings[] = 'Не удалось обновить существующее направление сделок #' . $targetId . ': ' . $e->getMessage();
                    }
                }

                return $targetId;
            }
        }

        if (!$this->isApplyMode())
        {
            if ($this->dryRunNextDealCategoryId <= 0)
            {
                $this->dryRunNextDealCategoryId = max(array_merge([0], array_map(static function(array $item): int { return (int)$item['ID']; }, $existingCategories))) + 1;
            }

            return $this->dryRunNextDealCategoryId++;
        }

        if (!class_exists('\\Bitrix\\Crm\\Category\\DealCategory'))
        {
            throw new RuntimeException('Класс DealCategory недоступен, нельзя создать направление сделок.');
        }

        try
        {
            $newId = (int)\Bitrix\Crm\Category\DealCategory::add([
                'NAME' => $name !== '' ? $name : ('Воронка ' . $sourceCategoryId),
                'SORT' => $sort,
            ]);
            \Bitrix\Crm\Category\DealCategory::createDefaultStages($newId);

            return $newId;
        }
        catch (\Throwable $e)
        {
            throw new RuntimeException('Не удалось создать направление сделок "' . $name . '": ' . $e->getMessage(), 0, $e);
        }
    }

    private function getDealCategories(): array
    {
        $categories = [];

        if (class_exists('\\Bitrix\\Crm\\Category\\DealCategory'))
        {
            try
            {
                $iterator = \Bitrix\Crm\Category\DealCategory::getList([
                    'select' => ['*'],
                    'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
                ]);

                while ($row = $iterator->fetch())
                {
                    $categories[] = $row;
                }
            }
            catch (\Throwable $e)
            {
                $this->warnings[] = 'Не удалось получить список направлений сделок: ' . $e->getMessage();
            }
        }

        $hasDefault = false;
        foreach ($categories as $category)
        {
            if ((int)($category['ID'] ?? -1) === 0)
            {
                $hasDefault = true;
                break;
            }
        }

        if (!$hasDefault)
        {
            $categories[] = ['ID' => 0, 'NAME' => 'Общая', 'SORT' => 0];
        }

        return $categories;
    }

    private function convertDealCategoryToStatusEntityId(int $categoryId): string
    {
        if ($categoryId > 0 && class_exists('\\Bitrix\\Crm\\Category\\DealCategory'))
        {
            try
            {
                return (string)\Bitrix\Crm\Category\DealCategory::convertToStatusEntityID($categoryId);
            }
            catch (\Throwable $e)
            {
                $this->warnings[] = 'Не удалось получить ENTITY_ID статусов для направления ' . $categoryId . ': ' . $e->getMessage();
            }
        }

        return $categoryId > 0 ? 'DEAL_STAGE_' . $categoryId : 'DEAL_STAGE';
    }

    private function importStatuses(
        string $entityCode,
        int $targetCategoryId,
        string $targetStatusEntityId,
        array $statuses,
        ?int $sourceCategoryId = null
    ): void {
        if ($statuses === [])
        {
            return;
        }

        $sourceCategoryId = $sourceCategoryId ?? $targetCategoryId;
        $existing = $this->getStatusesByEntityId($targetStatusEntityId);

        foreach ($statuses as $status)
        {
            if (!is_array($status))
            {
                continue;
            }

            $sourceStatusId = (string)($status['STATUS_ID'] ?? '');
            if ($sourceStatusId === '')
            {
                continue;
            }

            $targetStatusId = $this->resolveTargetStatusId($entityCode, $sourceStatusId, $targetCategoryId);
            $this->stageMap[$entityCode][$sourceStatusId] = $targetStatusId;

            $payload = $this->sanitizeStatusRow($status, $targetStatusEntityId, $targetStatusId, $entityCode);
            $action = isset($existing[$targetStatusId]) ? 'update' : 'create';

            if ($this->isApplyMode())
            {
                if ($action === 'update')
                {
                    $this->updateRow('b_crm_status', (int)$existing[$targetStatusId]['ID'], $payload);
                }
                else
                {
                    $newId = $this->insertRow('b_crm_status', $payload);
                    $existing[$targetStatusId] = ['ID' => $newId] + $payload;
                }
            }

            $this->report['entities'][$entityCode]['funnels'][] = [
                'status_action' => $action,
                'source_category_id' => $sourceCategoryId,
                'target_category_id' => $targetCategoryId,
                'source_status_id' => $sourceStatusId,
                'target_status_id' => $targetStatusId,
                'entity_id' => $targetStatusEntityId,
            ];
        }
    }

    private function resolveTargetStatusId(string $entityCode, string $sourceStatusId, int $targetCategoryId): string
    {
        if ($entityCode !== 'deal' || $targetCategoryId <= 0)
        {
            return $sourceStatusId;
        }

        $suffix = preg_replace('/^C\d+:/', '', $sourceStatusId) ?? $sourceStatusId;

        if (class_exists('\\Bitrix\\Crm\\Category\\DealCategory'))
        {
            try
            {
                return (string)\Bitrix\Crm\Category\DealCategory::prepareStageID($targetCategoryId, $suffix);
            }
            catch (\Throwable $e)
            {
                $this->warnings[] = 'Не удалось вычислить новый STATUS_ID для стадии ' . $sourceStatusId . ': ' . $e->getMessage();
            }
        }

        return 'C' . $targetCategoryId . ':' . $suffix;
    }

    private function getStatusesByEntityId(string $entityId): array
    {
        $rows = [];
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $sql = 'SELECT * FROM b_crm_status WHERE ENTITY_ID = \'' . $helper->forSql($entityId) . '\' ORDER BY SORT ASC, STATUS_ID ASC';
        $recordset = $connection->query($sql);

        while ($row = $recordset->fetch())
        {
            $rows[(string)$row['STATUS_ID']] = $row;
        }

        return $rows;
    }

    private function sanitizeStatusRow(array $row, string $entityId, string $statusId, string $entityCode): array
    {
        $columns = $this->getTableColumns('b_crm_status');
        $allowed = array_fill_keys($columns, true);

        unset($row['ID'], $row['LID']);

        $row['ENTITY_ID'] = $entityId;
        $row['STATUS_ID'] = $statusId;

        $payload = [];
        foreach ($row as $key => $value)
        {
            if (!isset($allowed[$key]))
            {
                continue;
            }

            $payload[$key] = $this->applyReplacements($value, $entityCode);
        }

        return $payload;
    }

    private function importUserFields(string $entityCode, array $entityData, array $definition): void
    {
        $sourceFields = $entityData['custom_fields'] ?? [];
        if (!is_array($sourceFields))
        {
            return;
        }

        $existingFields = $this->getExistingUserFields($definition['user_field_entity_id']);
        $byFieldName = [];
        $byXmlId = [];

        foreach ($existingFields as $field)
        {
            $byFieldName[(string)$field['FIELD_NAME']] = $field;
            $xmlId = trim((string)($field['XML_ID'] ?? ''));
            if ($xmlId !== '')
            {
                $byXmlId[$xmlId] = $field;
            }
        }

        foreach ($sourceFields as $sourceField)
        {
            if (!is_array($sourceField))
            {
                continue;
            }

            $sourceFieldName = (string)($sourceField['FIELD_NAME'] ?? '');
            if ($sourceFieldName === '')
            {
                continue;
            }

            $sourceXmlId = trim((string)($sourceField['XML_ID'] ?? ''));
            $existingField = $sourceXmlId !== '' && isset($byXmlId[$sourceXmlId]) ? $byXmlId[$sourceXmlId] : null;
            $action = 'create';
            $targetFieldName = $sourceFieldName;
            $targetFieldId = 0;

            if (is_array($existingField))
            {
                $action = 'update';
                $targetFieldName = (string)$existingField['FIELD_NAME'];
                $targetFieldId = (int)$existingField['ID'];
            }
            elseif (isset($byFieldName[$sourceFieldName]))
            {
                $targetFieldName = $this->generateUniqueFieldName($sourceFieldName, $byFieldName);
                $this->report['entities'][$entityCode]['fields']['collisions'][] = [
                    'source_field_name' => $sourceFieldName,
                    'target_field_name' => $targetFieldName,
                    'reason' => 'FIELD_NAME already exists in target portal',
                ];
            }

            $this->fieldNameMap[$entityCode][$sourceFieldName] = $targetFieldName;

            $payload = $this->sanitizeUserFieldForSave($sourceField, $definition['user_field_entity_id'], $targetFieldName, $entityCode);

            if ($this->isApplyMode())
            {
                $entity = new \CUserTypeEntity();

                if ($action === 'update')
                {
                    if (!$entity->Update($targetFieldId, $payload))
                    {
                        throw new RuntimeException('Не удалось обновить пользовательское поле ' . $targetFieldName . ': ' . $this->getApplicationExceptionText());
                    }
                }
                else
                {
                    $targetFieldId = (int)$entity->Add($payload);
                    if ($targetFieldId <= 0)
                    {
                        throw new RuntimeException('Не удалось создать пользовательское поле ' . $targetFieldName . ': ' . $this->getApplicationExceptionText());
                    }
                }

                if (($sourceField['USER_TYPE_ID'] ?? '') === 'enumeration')
                {
                    $this->syncUserFieldEnumValues($targetFieldId, (array)($sourceField['ENUM'] ?? []), $entityCode, $sourceFieldName, $targetFieldName);
                }
            }

            $this->report['entities'][$entityCode]['fields'][$action === 'update' ? 'updated' : 'created'][] = [
                'source_field_name' => $sourceFieldName,
                'target_field_name' => $targetFieldName,
                'target_field_id' => $targetFieldId,
                'user_type_id' => $sourceField['USER_TYPE_ID'] ?? null,
            ];

            $byFieldName[$targetFieldName] = ['FIELD_NAME' => $targetFieldName, 'ID' => $targetFieldId, 'XML_ID' => $payload['XML_ID'] ?? ''];
            if (!empty($payload['XML_ID']))
            {
                $byXmlId[(string)$payload['XML_ID']] = $byFieldName[$targetFieldName];
            }
        }
    }

    private function getExistingUserFields(string $entityId): array
    {
        $rows = [];
        if (!class_exists('CUserTypeEntity'))
        {
            return $rows;
        }

        $iterator = \CUserTypeEntity::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['ENTITY_ID' => $entityId]
        );

        while ($row = $iterator->Fetch())
        {
            $rows[] = $row;
        }

        return $rows;
    }

    private function sanitizeUserFieldForSave(array $sourceField, string $entityId, string $targetFieldName, string $entityCode): array
    {
        $payload = [
            'ENTITY_ID' => $entityId,
            'FIELD_NAME' => $targetFieldName,
            'USER_TYPE_ID' => (string)($sourceField['USER_TYPE_ID'] ?? 'string'),
            'XML_ID' => trim((string)($sourceField['XML_ID'] ?? '')) !== ''
                ? (string)$sourceField['XML_ID']
                : 'MOVEBX_' . strtoupper($entityCode) . '_' . $sourceField['FIELD_NAME'],
            'SORT' => isset($sourceField['SORT']) ? (int)$sourceField['SORT'] : 100,
            'MULTIPLE' => (string)($sourceField['MULTIPLE'] ?? 'N'),
            'MANDATORY' => (string)($sourceField['MANDATORY'] ?? 'N'),
            'SHOW_FILTER' => (string)($sourceField['SHOW_FILTER'] ?? 'N'),
            'SHOW_IN_LIST' => (string)($sourceField['SHOW_IN_LIST'] ?? 'N'),
            'EDIT_IN_LIST' => (string)($sourceField['EDIT_IN_LIST'] ?? 'N'),
            'IS_SEARCHABLE' => (string)($sourceField['IS_SEARCHABLE'] ?? 'N'),
            'SETTINGS' => is_array($sourceField['SETTINGS'] ?? null) ? $this->applyReplacements($sourceField['SETTINGS'], $entityCode) : [],
        ];

        foreach (['EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL', 'ERROR_MESSAGE', 'HELP_MESSAGE'] as $fieldKey)
        {
            if (!array_key_exists($fieldKey, $sourceField))
            {
                continue;
            }

            $payload[$fieldKey] = $this->normalizeLanguageMap($sourceField[$fieldKey]);
        }

        return $payload;
    }

    private function normalizeLanguageMap($value): array
    {
        if (is_array($value))
        {
            return $value;
        }

        $languageId = defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru';

        return [$languageId => (string)$value];
    }

    private function generateUniqueFieldName(string $sourceFieldName, array $existingFields): string
    {
        $base = strtoupper($sourceFieldName);
        $maxLength = 50;
        $index = 1;

        do
        {
            $suffix = '_X' . $index;
            $candidate = substr($base, 0, $maxLength - strlen($suffix)) . $suffix;
            $index++;
        }
        while (isset($existingFields[$candidate]));

        return $candidate;
    }

    private function syncUserFieldEnumValues(
        int $targetFieldId,
        array $sourceEnums,
        string $entityCode,
        string $sourceFieldName,
        string $targetFieldName
    ): void {
        $tableName = 'b_user_field_enum';
        $fieldEnums = $this->getUserFieldEnumRows($targetFieldId);
        $fieldEnumsByXml = [];
        $fieldEnumsByValue = [];

        foreach ($fieldEnums as $row)
        {
            $xmlId = trim((string)($row['XML_ID'] ?? ''));
            $value = (string)($row['VALUE'] ?? '');
            if ($xmlId !== '')
            {
                $fieldEnumsByXml[$xmlId] = $row;
            }

            if ($value !== '')
            {
                $fieldEnumsByValue[$value] = $row;
            }
        }

        foreach ($sourceEnums as $sourceEnum)
        {
            if (!is_array($sourceEnum))
            {
                continue;
            }

            $sourceEnumId = (int)($sourceEnum['ID'] ?? 0);
            $sourceXmlId = trim((string)($sourceEnum['XML_ID'] ?? ''));
            $sourceValue = (string)($sourceEnum['VALUE'] ?? '');
            $targetEnum = null;

            if ($sourceXmlId !== '' && isset($fieldEnumsByXml[$sourceXmlId]))
            {
                $targetEnum = $fieldEnumsByXml[$sourceXmlId];
            }
            elseif ($sourceValue !== '' && isset($fieldEnumsByValue[$sourceValue]))
            {
                $targetEnum = $fieldEnumsByValue[$sourceValue];
            }

            $payload = [
                'USER_FIELD_ID' => $targetFieldId,
                'VALUE' => $sourceValue,
                'DEF' => (string)($sourceEnum['DEF'] ?? 'N'),
                'SORT' => isset($sourceEnum['SORT']) ? (int)$sourceEnum['SORT'] : 100,
                'XML_ID' => $sourceXmlId,
            ];

            if (is_array($targetEnum))
            {
                $targetEnumId = (int)$targetEnum['ID'];
                $this->updateRow($tableName, $targetEnumId, $payload);
            }
            else
            {
                $desiredId = $sourceEnumId > 0 ? $sourceEnumId : null;
                $targetEnumId = $this->insertUserFieldEnumRow($payload, $desiredId);
            }

            $this->enumMap[$entityCode][$sourceFieldName][(string)$sourceEnumId] = (string)$targetEnumId;

            if ($sourceEnumId > 0 && $sourceEnumId !== $targetEnumId)
            {
                $this->warnings[] = 'Для поля ' . $targetFieldName . ' значение списка "' . $sourceValue . '" получило новый ID ' . $targetEnumId . ' вместо ' . $sourceEnumId . '. Если шаблоны БП используют именно ID значений списка, проверьте их после импорта.';
            }
        }
    }

    private function getUserFieldEnumRows(int $userFieldId): array
    {
        $connection = Application::getConnection();
        $sql = 'SELECT * FROM b_user_field_enum WHERE USER_FIELD_ID = ' . $userFieldId . ' ORDER BY SORT ASC, ID ASC';
        $recordset = $connection->query($sql);
        $rows = [];

        while ($row = $recordset->fetch())
        {
            $rows[] = $row;
        }

        return $rows;
    }

    private function insertUserFieldEnumRow(array $payload, ?int $desiredId = null): int
    {
        $tableColumns = $this->getTableColumns('b_user_field_enum');
        $payload = array_intersect_key($payload, array_fill_keys($tableColumns, true));

        if ($desiredId !== null && $desiredId > 0 && !$this->rowExistsById('b_user_field_enum', $desiredId))
        {
            $payloadWithId = ['ID' => $desiredId] + $payload;
            $this->insertRow('b_user_field_enum', $payloadWithId);

            return $desiredId;
        }

        return $this->insertRow('b_user_field_enum', $payload);
    }

    private function rowExistsById(string $tableName, int $id): bool
    {
        $connection = Application::getConnection();
        $sql = 'SELECT ID FROM ' . $tableName . ' WHERE ID = ' . $id;
        $row = $connection->query($sql)->fetch();

        return is_array($row);
    }

    private function importBusinessProcesses(string $entityCode, array $entityData, array $definition): void
    {
        if (!Loader::includeModule('bizproc'))
        {
            return;
        }

        $bpData = $entityData['business_processes'] ?? [];
        $templates = $bpData['templates'] ?? [];
        if (!is_array($templates) || $templates === [])
        {
            return;
        }

        $existingTemplates = $this->getExistingBizprocTemplates($definition['document_type']);

        foreach ($templates as $template)
        {
            if (!is_array($template))
            {
                continue;
            }

            if (!isset($template['TEMPLATE']) && !isset($template['PARAMETERS']) && !isset($template['VARIABLES']))
            {
                $this->report['entities'][$entityCode]['business_processes']['skipped'][] = [
                    'name' => $template['NAME'] ?? '',
                    'reason' => 'В blueprint нет сериализованных данных шаблона. Пересоздайте JSON новым экспортёром.',
                ];
                continue;
            }

            $preparedTemplate = $this->sanitizeBizprocTemplateRow($this->applyReplacements($template, $entityCode), $definition['document_type']);
            $existingTemplate = $this->findMatchingBizprocTemplate($existingTemplates, $preparedTemplate);
            $action = $existingTemplate !== null ? 'update' : 'create';

            if ($this->isApplyMode())
            {
                if ($existingTemplate !== null)
                {
                    $this->updateRow('b_bp_workflow_template', (int)$existingTemplate['ID'], $preparedTemplate);
                }
                else
                {
                    $this->insertRow('b_bp_workflow_template', $preparedTemplate);
                }
            }

            $this->report['entities'][$entityCode]['business_processes'][$action === 'update' ? 'updated' : 'created'][] = [
                'name' => $preparedTemplate['NAME'] ?? '',
                'auto_execute' => $preparedTemplate['AUTO_EXECUTE'] ?? null,
                'system_code' => $preparedTemplate['SYSTEM_CODE'] ?? null,
            ];
        }
    }

    private function getExistingBizprocTemplates(array $documentType): array
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $sql = sprintf(
            "SELECT * FROM b_bp_workflow_template WHERE MODULE_ID = '%s' AND ENTITY = '%s' AND DOCUMENT_TYPE = '%s' ORDER BY ID ASC",
            $helper->forSql((string)$documentType[0]),
            $helper->forSql((string)$documentType[1]),
            $helper->forSql((string)$documentType[2])
        );

        $rows = [];
        $recordset = $connection->query($sql);
        while ($row = $recordset->fetch())
        {
            $rows[] = $row;
        }

        return $rows;
    }

    private function findMatchingBizprocTemplate(array $existingTemplates, array $preparedTemplate): ?array
    {
        $systemCode = trim((string)($preparedTemplate['SYSTEM_CODE'] ?? ''));
        $name = (string)($preparedTemplate['NAME'] ?? '');
        $autoExecute = (string)($preparedTemplate['AUTO_EXECUTE'] ?? '0');

        foreach ($existingTemplates as $existingTemplate)
        {
            $existingSystemCode = trim((string)($existingTemplate['SYSTEM_CODE'] ?? ''));
            if ($systemCode !== '' && $existingSystemCode === $systemCode)
            {
                return $existingTemplate;
            }
        }

        foreach ($existingTemplates as $existingTemplate)
        {
            if ((string)($existingTemplate['NAME'] ?? '') === $name
                && (string)($existingTemplate['AUTO_EXECUTE'] ?? '0') === $autoExecute)
            {
                return $existingTemplate;
            }
        }

        return null;
    }

    private function sanitizeBizprocTemplateRow(array $template, array $documentType): array
    {
        $columns = $this->getTableColumns('b_bp_workflow_template');
        $allowed = array_fill_keys($columns, true);

        unset($template['ID']);

        $template['MODULE_ID'] = (string)$documentType[0];
        $template['ENTITY'] = (string)$documentType[1];
        $template['DOCUMENT_TYPE'] = (string)$documentType[2];
        $template['ACTIVE'] = (string)($template['ACTIVE'] ?? 'Y');
        $template['AUTO_EXECUTE'] = (int)($template['AUTO_EXECUTE'] ?? 0);
        $template['IS_MODIFIED'] = (string)($template['IS_MODIFIED'] ?? 'Y');

        $payload = [];
        foreach ($template as $key => $value)
        {
            if (!isset($allowed[$key]))
            {
                continue;
            }

            $payload[$key] = is_array($value) ? serialize($value) : $value;
        }

        return $payload;
    }

    private function importAutomation(string $entityCode, array $entityData, array $definition): void
    {
        $automation = $entityData['automation'] ?? [];
        if (!is_array($automation))
        {
            return;
        }

        $this->importAutomationTableRows(
            $entityCode,
            'robots',
            (array)(($automation['robots']['raw'] ?? [])),
            $this->resolveAutomationTableName('template')
        );

        $this->importAutomationTableRows(
            $entityCode,
            'triggers',
            (array)(($automation['triggers']['raw'] ?? [])),
            $this->resolveAutomationTableName('trigger')
        );
    }

    private function resolveAutomationTableName(string $kind): ?string
    {
        if ($kind === 'template')
        {
            $className = '\\Bitrix\\Crm\\Automation\\Engine\\Entity\\TemplateTable';
            if (class_exists($className) && method_exists($className, 'getTableName'))
            {
                return (string)$className::getTableName();
            }

            return 'b_crm_automation_template';
        }

        $className = '\\Bitrix\\Crm\\Automation\\Trigger\\Entity\\TriggerTable';
        if (class_exists($className) && method_exists($className, 'getTableName'))
        {
            return (string)$className::getTableName();
        }

        return 'b_crm_automation_trigger';
    }

    private function importAutomationTableRows(string $entityCode, string $reportKey, array $rows, ?string $tableName): void
    {
        if ($tableName === null || $rows === [])
        {
            return;
        }

        $entityTypeId = (int)self::ENTITY_MAP[$entityCode]['entity_type_id'];

        if ($this->isApplyMode() && $this->getOptionBool('replace_automation', true))
        {
            $this->deleteByFilter($tableName, 'ENTITY_TYPE_ID = ' . $entityTypeId);
        }

        foreach ($rows as $row)
        {
            if (!is_array($row))
            {
                continue;
            }

            $preparedRow = $this->sanitizeAutomationRow($entityCode, $row, $tableName);
            if ($preparedRow === [])
            {
                continue;
            }

            if ($this->isApplyMode())
            {
                $this->insertRow($tableName, $preparedRow);
            }

            $this->report['entities'][$entityCode]['automation'][$reportKey][] = [
                'table' => $tableName,
                'document_status' => $preparedRow['DOCUMENT_STATUS'] ?? null,
                'category_id' => $preparedRow['CATEGORY_ID'] ?? null,
                'name' => $preparedRow['NAME'] ?? null,
            ];
        }
    }

    private function sanitizeAutomationRow(string $entityCode, array $row, string $tableName): array
    {
        $columns = $this->getTableColumns($tableName);
        if ($columns === [])
        {
            return [];
        }

        unset($row['ID']);
        $row = $this->applyReplacements($row, $entityCode);

        if ($entityCode === 'deal' && isset($row['CATEGORY_ID']))
        {
            $sourceCategoryId = (int)$row['CATEGORY_ID'];
            if (isset($this->categoryMap[$sourceCategoryId]))
            {
                $row['CATEGORY_ID'] = $this->categoryMap[$sourceCategoryId];
            }
        }

        foreach (['DOCUMENT_STATUS', 'STATUS_ID', 'STAGE_ID', 'DOCUMENT_STATUS_ID', 'TRIGGER_STATUS'] as $statusField)
        {
            if (!isset($row[$statusField]) || !is_scalar($row[$statusField]))
            {
                continue;
            }

            $sourceStatusId = (string)$row[$statusField];
            if (isset($this->stageMap[$entityCode][$sourceStatusId]))
            {
                $row[$statusField] = $this->stageMap[$entityCode][$sourceStatusId];
            }
        }

        $payload = [];
        $allowed = array_fill_keys($columns, true);
        foreach ($row as $key => $value)
        {
            if (!isset($allowed[$key]))
            {
                continue;
            }

            $payload[$key] = is_array($value) ? serialize($value) : $value;
        }

        return $payload;
    }

    private function applyReplacements($value, ?string $entityCode = null)
    {
        if (is_array($value))
        {
            $result = [];
            foreach ($value as $key => $item)
            {
                $newKey = is_string($key) ? $this->replaceInString($key, $entityCode) : $key;
                $result[$newKey] = $this->applyReplacements($item, $entityCode);
            }

            return $result;
        }

        if (is_string($value))
        {
            return $this->replaceInString($value, $entityCode);
        }

        return $value;
    }

    private function replaceInString(string $value, ?string $entityCode = null): string
    {
        $map = $this->getStringReplacementMap($entityCode);
        if ($map === [])
        {
            return $value;
        }

        uksort($map, static function(string $left, string $right): int {
            return strlen($right) <=> strlen($left);
        });

        foreach (array_keys($map) as $from)
        {
            $to = $map[$from];
            if ($from === $to || $from === '')
            {
                continue;
            }

            $pattern = '/(?<![A-Z0-9_])' . preg_quote($from, '/') . '(?![A-Z0-9_])/u';
            $value = preg_replace($pattern, $to, $value) ?? $value;
        }

        return $value;
    }

    private function getStringReplacementMap(?string $entityCode = null): array
    {
        $map = [];

        if ($entityCode !== null && isset($this->fieldNameMap[$entityCode]))
        {
            foreach ($this->fieldNameMap[$entityCode] as $sourceFieldName => $targetFieldName)
            {
                if ($sourceFieldName !== $targetFieldName)
                {
                    $map[$sourceFieldName] = $targetFieldName;
                }
            }
        }

        if ($entityCode !== null && isset($this->stageMap[$entityCode]))
        {
            foreach ($this->stageMap[$entityCode] as $sourceStageId => $targetStageId)
            {
                if ($sourceStageId !== $targetStageId)
                {
                    $map[$sourceStageId] = $targetStageId;
                }
            }
        }

        foreach ($this->statusEntityMap as $sourceStatusEntityId => $targetStatusEntityId)
        {
            if ($sourceStatusEntityId !== $targetStatusEntityId)
            {
                $map[$sourceStatusEntityId] = $targetStatusEntityId;
            }
        }

        return $map;
    }

    private function getTableColumns(string $tableName): array
    {
        if (isset($this->tableColumnsCache[$tableName]))
        {
            return $this->tableColumnsCache[$tableName];
        }

        $columns = [];
        try
        {
            $connection = Application::getConnection();
            $recordset = $connection->query('SHOW COLUMNS FROM ' . $tableName);
            while ($row = $recordset->fetch())
            {
                $columns[] = (string)$row['Field'];
            }
        }
        catch (\Throwable $e)
        {
            $this->warnings[] = 'Не удалось получить структуру таблицы ' . $tableName . ': ' . $e->getMessage();
        }

        $this->tableColumnsCache[$tableName] = $columns;

        return $columns;
    }

    private function insertRow(string $tableName, array $payload): int
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        [$fields, $values] = $helper->prepareInsert($tableName, $payload);
        $sql = 'INSERT INTO ' . $tableName . ' (' . $fields . ') VALUES (' . $values . ')';
        $connection->queryExecute($sql);

        return (int)$connection->getInsertedId();
    }

    private function updateRow(string $tableName, int $id, array $payload): void
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $update = $helper->prepareUpdate($tableName, $payload);
        $sql = 'UPDATE ' . $tableName . ' SET ' . $update . ' WHERE ID = ' . $id;
        $connection->queryExecute($sql);
    }

    private function deleteByFilter(string $tableName, string $whereSql): void
    {
        $connection = Application::getConnection();
        $connection->queryExecute('DELETE FROM ' . $tableName . ' WHERE ' . $whereSql);
    }

    private function getApplicationExceptionText(): string
    {
        global $APPLICATION;

        if (is_object($APPLICATION) && method_exists($APPLICATION, 'GetException'))
        {
            $exception = $APPLICATION->GetException();
            if ($exception && method_exists($exception, 'GetString'))
            {
                return (string)$exception->GetString();
            }
        }

        return 'Неизвестная ошибка.';
    }

    private function loadBlueprint(string $inputPath): array
    {
        if (!is_file($inputPath))
        {
            throw new RuntimeException('Файл blueprint не найден: ' . $inputPath);
        }

        $contents = file_get_contents($inputPath);
        if (!is_string($contents) || $contents === '')
        {
            throw new RuntimeException('Не удалось прочитать blueprint: ' . $inputPath);
        }

        $data = json_decode($contents, true);
        if (!is_array($data))
        {
            throw new RuntimeException('JSON blueprint некорректен: ' . json_last_error_msg());
        }

        return $data;
    }

    private function resolveReportPath(?string $requestedPath): string
    {
        $requestedPath = trim((string)$requestedPath);
        if ($requestedPath === '')
        {
            $baseDirectory = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/upload/crm_blueprints';
            if (!is_dir($baseDirectory) && !mkdir($baseDirectory, 0775, true) && !is_dir($baseDirectory))
            {
                throw new RuntimeException('Не удалось создать каталог для отчета: ' . $baseDirectory);
            }

            return $baseDirectory . '/crm-blueprint-import-report-' . date('Y-m-d-H-i-s') . '.json';
        }

        if ($requestedPath[0] !== '/')
        {
            $requestedPath = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/' . ltrim($requestedPath, '/');
        }

        $directory = dirname($requestedPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory))
        {
            throw new RuntimeException('Не удалось создать каталог отчета: ' . $directory);
        }

        return $requestedPath;
    }

    private function saveJson(string $outputPath, array $data): void
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if (!is_string($json))
        {
            throw new RuntimeException('json_encode завершился ошибкой: ' . json_last_error_msg());
        }

        if (file_put_contents($outputPath, $json) === false)
        {
            throw new RuntimeException('Не удалось записать отчет: ' . $outputPath);
        }
    }

    private function isApplyMode(): bool
    {
        return strtolower((string)($this->options['mode'] ?? 'dry-run')) === 'apply';
    }

    private function getOptionBool(string $key, bool $default): bool
    {
        if (!array_key_exists($key, $this->options))
        {
            return $default;
        }

        $value = $this->options[$key];
        if (is_bool($value))
        {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 'y', 'yes', 'true'], true);
    }
}

function crmBlueprintImporterCollectOptions(): array
{
    $options = [
        'mode' => 'dry-run',
        'replace_automation' => true,
        'input' => null,
        'report' => null,
    ];

    if (PHP_SAPI === 'cli')
    {
        global $argv;

        if (is_array($argv))
        {
            foreach ($argv as $argument)
            {
                if (strpos((string)$argument, '--input=') === 0)
                {
                    $options['input'] = substr((string)$argument, 8);
                }
                elseif (strpos((string)$argument, '--report=') === 0)
                {
                    $options['report'] = substr((string)$argument, 9);
                }
                elseif (strpos((string)$argument, '--mode=') === 0)
                {
                    $options['mode'] = substr((string)$argument, 7);
                }
                elseif (strpos((string)$argument, '--replace-automation=') === 0)
                {
                    $options['replace_automation'] = substr((string)$argument, 21);
                }
            }
        }

        return $options;
    }

    if (isset($_REQUEST['input']))
    {
        $options['input'] = (string)$_REQUEST['input'];
    }

    if (isset($_REQUEST['report']))
    {
        $options['report'] = (string)$_REQUEST['report'];
    }

    if (isset($_REQUEST['mode']))
    {
        $options['mode'] = (string)$_REQUEST['mode'];
    }

    if (isset($_REQUEST['replace_automation']))
    {
        $options['replace_automation'] = (string)$_REQUEST['replace_automation'];
    }

    return $options;
}

function crmBlueprintImporterRespond(array $payload, int $statusCode = 200): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent())
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

try
{
    $documentRoot = crmBlueprintImporterFindDocumentRoot();
    if ($documentRoot === null)
    {
        throw new RuntimeException('Не найден /bitrix/modules/main/include/prolog_before.php. Скрипт должен запускаться внутри коробочного Битрикс24.');
    }

    $_SERVER['DOCUMENT_ROOT'] = $documentRoot;
    require_once $documentRoot . '/bitrix/modules/main/include/prolog_before.php';

    $options = crmBlueprintImporterCollectOptions();
    $inputPath = trim((string)($options['input'] ?? ''));
    if ($inputPath === '')
    {
        throw new RuntimeException('Нужно передать путь к blueprint JSON через --input=... или ?input=...');
    }

    if ($inputPath[0] !== '/')
    {
        $inputPath = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/' . ltrim($inputPath, '/');
    }

    $importer = new CrmPortalBlueprintImporter();
    $result = $importer->import($inputPath, $options);
    crmBlueprintImporterRespond($result);
}
catch (\Throwable $e)
{
    crmBlueprintImporterRespond([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], 500);
}
