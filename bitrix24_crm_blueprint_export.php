<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', 'Y');
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

function crmBlueprintExporterFindDocumentRoot(): ?string
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

final class CrmPortalBlueprintExporter
{
    private const SCHEMA_VERSION = '1.0.1';

    private const ENTITY_MAP = [
        'lead' => [
            'title' => 'Лид',
            'entity_type_id' => 1,
            'user_field_entity_id' => 'CRM_LEAD',
            'document_type' => ['crm', 'CCrmDocumentLead', 'LEAD'],
            'status_entity_id' => 'STATUS',
            'has_funnels' => true,
            'has_categories' => false,
        ],
        'deal' => [
            'title' => 'Сделка',
            'entity_type_id' => 2,
            'user_field_entity_id' => 'CRM_DEAL',
            'document_type' => ['crm', 'CCrmDocumentDeal', 'DEAL'],
            'status_entity_id' => 'DEAL_STAGE',
            'has_funnels' => true,
            'has_categories' => true,
        ],
        'contact' => [
            'title' => 'Контакт',
            'entity_type_id' => 3,
            'user_field_entity_id' => 'CRM_CONTACT',
            'document_type' => ['crm', 'CCrmDocumentContact', 'CONTACT'],
            'status_entity_id' => null,
            'has_funnels' => false,
            'has_categories' => false,
        ],
        'company' => [
            'title' => 'Компания',
            'entity_type_id' => 4,
            'user_field_entity_id' => 'CRM_COMPANY',
            'document_type' => ['crm', 'CCrmDocumentCompany', 'COMPANY'],
            'status_entity_id' => null,
            'has_funnels' => false,
            'has_categories' => false,
        ],
    ];

    private const DICTIONARY_MAP = [
        'contact_type' => [
            'title' => 'Тип контакта',
            'entity_id' => 'CONTACT_TYPE',
        ],
        'company_type' => [
            'title' => 'Тип компании',
            'entity_id' => 'COMPANY_TYPE',
        ],
        'employees' => [
            'title' => 'Количество сотрудников',
            'entity_id' => 'EMPLOYEES',
        ],
        'industry' => [
            'title' => 'Сфера деятельности',
            'entity_id' => 'INDUSTRY',
        ],
        'deal_type' => [
            'title' => 'Тип сделки',
            'entity_id' => 'DEAL_TYPE',
        ],
        'source' => [
            'title' => 'Источники',
            'entity_id' => 'SOURCE',
        ],
    ];

    private array $warnings = [];
    private array $runtime = [];

    public function export(?string $requestedOutputPath = null): array
    {
        $this->assertEnvironment();

        $modules = [
            'crm' => Loader::includeModule('crm'),
            'bizproc' => Loader::includeModule('bizproc'),
        ];

        if (!$modules['crm'])
        {
            throw new RuntimeException('Не удалось подключить модуль crm.');
        }

        $outputPath = $this->resolveOutputPath($requestedOutputPath);
        $data = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => date(DATE_ATOM),
            'generated_at_unix' => time(),
            'portal' => $this->buildPortalMetadata(),
            'modules' => $modules,
            'dictionaries' => $this->exportDictionaries(),
            'entities' => [],
            'warnings' => [],
        ];

        foreach (self::ENTITY_MAP as $entityCode => $entityDefinition)
        {
            $data['entities'][$entityCode] = $this->exportEntity($entityCode, $entityDefinition, $modules);
        }

        $data['warnings'] = $this->warnings;
        $this->saveJson($outputPath, $data);

        return [
            'success' => true,
            'output_path' => $outputPath,
            'warnings' => $this->warnings,
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
            throw new RuntimeException('Скрипт нужно запускать под администратором портала, иначе часть настроек может не выгрузиться.');
        }
    }

    private function buildPortalMetadata(): array
    {
        global $USER;

        $metadata = [
            'site_name' => defined('SITE_SERVER_NAME') ? SITE_SERVER_NAME : ($_SERVER['SERVER_NAME'] ?? ''),
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '',
            'php_version' => PHP_VERSION,
            'bitrix_version' => defined('SM_VERSION') ? SM_VERSION : null,
            'language_id' => defined('LANGUAGE_ID') ? LANGUAGE_ID : null,
            'is_admin_context' => is_object($USER) && method_exists($USER, 'IsAdmin') ? (bool)$USER->IsAdmin() : null,
        ];

        if (class_exists('\\Bitrix\\Main\\ModuleManager'))
        {
            $metadata['installed_modules'] = [
                'main' => \Bitrix\Main\ModuleManager::getVersion('main'),
                'crm' => \Bitrix\Main\ModuleManager::getVersion('crm'),
                'bizproc' => \Bitrix\Main\ModuleManager::getVersion('bizproc'),
            ];
        }

        return $metadata;
    }

    private function exportEntity(string $entityCode, array $definition, array $modules): array
    {
        $result = [
            'title' => $definition['title'],
            'entity_type_id' => $definition['entity_type_id'],
            'user_field_entity_id' => $definition['user_field_entity_id'],
            'document_type' => $definition['document_type'],
            'custom_fields' => $this->exportUserFields($definition['user_field_entity_id']),
            'business_processes' => $modules['bizproc']
                ? $this->exportBizprocTemplates($definition['document_type'])
                : ['supported' => false, 'templates' => []],
            'automation' => $this->exportAutomation($entityCode, $definition),
        ];

        if ($definition['has_funnels'])
        {
            $result['funnels'] = $this->exportFunnels($entityCode, $definition);
        }

        return $result;
    }

    private function exportDictionaries(): array
    {
        $result = [];

        foreach (self::DICTIONARY_MAP as $code => $dictionary)
        {
            $result[$code] = [
                'title' => $dictionary['title'],
                'entity_id' => $dictionary['entity_id'],
                'items' => $this->getCrmStatuses($dictionary['entity_id']),
            ];
        }

        return $result;
    }

    private function exportFunnels(string $entityCode, array $definition): array
    {
        if ($entityCode === 'lead')
        {
            return [
                [
                    'id' => 0,
                    'name' => 'Лиды',
                    'is_default' => true,
                    'status_entity_id' => (string)$definition['status_entity_id'],
                    'statuses' => $this->getCrmStatuses((string)$definition['status_entity_id']),
                ],
            ];
        }

        if ($entityCode !== 'deal')
        {
            return [];
        }

        $funnels = [];
        foreach ($this->getDealCategories() as $category)
        {
            $categoryId = (int)($category['ID'] ?? 0);
            $statusEntityId = $categoryId > 0 ? 'DEAL_STAGE_' . $categoryId : 'DEAL_STAGE';

            $funnels[] = [
                'id' => $categoryId,
                'name' => (string)($category['NAME'] ?? ('Воронка #' . $categoryId)),
                'code' => $category['CODE'] ?? null,
                'sort' => isset($category['SORT']) ? (int)$category['SORT'] : null,
                'is_default' => $categoryId === 0 || (string)($category['IS_DEFAULT'] ?? 'N') === 'Y',
                'status_entity_id' => $statusEntityId,
                'statuses' => $this->getCrmStatuses($statusEntityId),
            ];
        }

        if ($funnels === [])
        {
            $this->warnings[] = 'Не удалось определить направления сделок через API. Выгружена только воронка по умолчанию.';
            $funnels[] = [
                'id' => 0,
                'name' => 'Сделки',
                'is_default' => true,
                'status_entity_id' => 'DEAL_STAGE',
                'statuses' => $this->getCrmStatuses('DEAL_STAGE'),
            ];
        }

        return $funnels;
    }

    private function getDealCategories(): array
    {
        $categories = [];

        if (class_exists('\\Bitrix\\Crm\\Service\\Container') && class_exists('\\CCrmOwnerType'))
        {
            try
            {
                $factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory(\CCrmOwnerType::Deal);
                if ($factory && method_exists($factory, 'getCategories'))
                {
                    foreach ((array)$factory->getCategories() as $category)
                    {
                        $row = $this->extractCategoryFromObject($category);
                        if ($row !== [])
                        {
                            $categories[(int)$row['ID']] = $row;
                        }
                    }
                }
            }
            catch (\Throwable $e)
            {
                $this->warnings[] = 'Не удалось получить направления сделок через CRM Factory: ' . $e->getMessage();
            }
        }

        if (!isset($categories[0]))
        {
            $categories[0] = [
                'ID' => 0,
                'NAME' => 'Общая',
                'SORT' => 0,
                'IS_DEFAULT' => 'Y',
            ];
        }

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
                    $categoryId = (int)($row['ID'] ?? 0);
                    $row['IS_DEFAULT'] = $categoryId === 0 ? 'Y' : (string)($row['IS_DEFAULT'] ?? 'N');
                    $categories[$categoryId] = $this->normalizeValue($row);
                }
            }
            catch (\Throwable $e)
            {
                $this->warnings[] = 'Не удалось дочитать направления сделок через DealCategory::getList: ' . $e->getMessage();
            }
        }

        ksort($categories);

        return array_values($categories);
    }

    private function extractCategoryFromObject(object $category): array
    {
        $map = [
            'getId' => 'ID',
            'getName' => 'NAME',
            'getCode' => 'CODE',
            'getSort' => 'SORT',
            'isDefault' => 'IS_DEFAULT',
        ];

        $row = [];
        foreach ($map as $method => $field)
        {
            if (!method_exists($category, $method))
            {
                continue;
            }

            $value = $category->{$method}();
            if ($field === 'IS_DEFAULT')
            {
                $value = $value ? 'Y' : 'N';
            }

            $row[$field] = $this->normalizeValue($value);
        }

        if (!isset($row['ID']))
        {
            return [];
        }

        return $row;
    }

    private function getCrmStatuses(string $statusEntityId): array
    {
        $rows = [];

        if (class_exists('\\Bitrix\\Crm\\StatusTable'))
        {
            try
            {
                $iterator = \Bitrix\Crm\StatusTable::getList([
                    'select' => ['*'],
                    'filter' => ['=ENTITY_ID' => $statusEntityId],
                    'order' => ['SORT' => 'ASC', 'STATUS_ID' => 'ASC'],
                ]);

                while ($row = $iterator->fetch())
                {
                    $rows[] = $this->normalizeValue($row);
                }

                return $rows;
            }
            catch (\Throwable $e)
            {
                $this->warnings[] = 'Не удалось получить статусы через StatusTable для ENTITY_ID=' . $statusEntityId . ': ' . $e->getMessage();
            }
        }

        try
        {
            $connection = Application::getConnection();
            $helper = $connection->getSqlHelper();
            $sql = 'SELECT * FROM b_crm_status WHERE ENTITY_ID = \'' . $helper->forSql($statusEntityId) . '\' ORDER BY SORT ASC, STATUS_ID ASC';
            $recordset = $connection->query($sql);

            while ($row = $recordset->fetch())
            {
                $rows[] = $this->normalizeValue($row);
            }
        }
        catch (\Throwable $e)
        {
            $this->warnings[] = 'Не удалось получить статусы прямым запросом для ENTITY_ID=' . $statusEntityId . ': ' . $e->getMessage();
        }

        return $rows;
    }

    private function exportUserFields(string $userFieldEntityId): array
    {
        $result = [];

        if (!class_exists('CUserTypeEntity'))
        {
            $this->warnings[] = 'Класс CUserTypeEntity недоступен. Пользовательские поля не выгружены для ' . $userFieldEntityId . '.';
            return $result;
        }

        $filter = ['ENTITY_ID' => $userFieldEntityId];
        if (defined('LANGUAGE_ID'))
        {
            $filter['LANG'] = LANGUAGE_ID;
        }

        $iterator = \CUserTypeEntity::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            $filter
        );

        while ($field = $iterator->Fetch())
        {
            $field = $this->normalizeValue($field);
            $field['ENUM'] = $this->exportUserFieldEnumValues((int)$field['ID'], (string)($field['USER_TYPE_ID'] ?? ''));
            $result[] = $field;
        }

        return $result;
    }

    private function exportUserFieldEnumValues(int $userFieldId, string $userTypeId): array
    {
        if ($userFieldId <= 0 || $userTypeId !== 'enumeration' || !class_exists('CUserFieldEnum'))
        {
            return [];
        }

        $values = [];
        $iterator = \CUserFieldEnum::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['USER_FIELD_ID' => $userFieldId]
        );

        while ($row = $iterator->Fetch())
        {
            $values[] = $this->normalizeValue($row);
        }

        return $values;
    }

    private function exportBizprocTemplates(array $documentType): array
    {
        $result = [
            'supported' => true,
            'templates' => [],
            'document_type' => $documentType,
        ];

        if (!class_exists('CBPWorkflowTemplateLoader'))
        {
            $this->warnings[] = 'Класс CBPWorkflowTemplateLoader недоступен. Шаблоны БП не выгружены.';
            $result['supported'] = false;
            return $result;
        }

        try
        {
            $iterator = \CBPWorkflowTemplateLoader::GetList(
                ['ID' => 'ASC'],
                ['DOCUMENT_TYPE' => $documentType],
                false,
                false,
                [
                    'ID',
                    'MODULE_ID',
                    'ENTITY',
                    'DOCUMENT_TYPE',
                    'AUTO_EXECUTE',
                    'NAME',
                    'DESCRIPTION',
                    'TEMPLATE',
                    'PARAMETERS',
                    'VARIABLES',
                    'CONSTANTS',
                    'MODIFIED',
                    'USER_ID',
                    'ACTIVE',
                    'IS_MODIFIED',
                    'SYSTEM_CODE',
                ]
            );

            while ($row = $iterator->Fetch())
            {
                $row = $this->normalizeValue($row);

                if (isset($row['ID']) && method_exists('CBPWorkflowTemplateLoader', 'getTemplateConstants'))
                {
                    try
                    {
                        $row['CONSTANTS'] = $this->normalizeValue(\CBPWorkflowTemplateLoader::getTemplateConstants((int)$row['ID']));
                    }
                    catch (\Throwable $e)
                    {
                        $this->warnings[] = 'Не удалось загрузить константы шаблона БП #' . $row['ID'] . ': ' . $e->getMessage();
                    }
                }

                $result['templates'][] = $row;
            }

            if ($result['templates'] === [])
            {
                $result['templates'] = $this->exportBizprocTemplatesFromTable($documentType);
            }
        }
        catch (\Throwable $e)
        {
            $this->warnings[] = 'Ошибка выгрузки шаблонов БП для ' . implode(':', $documentType) . ': ' . $e->getMessage();
            $result['templates'] = $this->exportBizprocTemplatesFromTable($documentType);
            $result['supported'] = $result['templates'] !== [];
        }

        return $result;
    }

    private function exportBizprocTemplatesFromTable(array $documentType): array
    {
        $rows = [];

        try
        {
            $connection = Application::getConnection();
            $helper = $connection->getSqlHelper();
            $sql = sprintf(
                "SELECT * FROM b_bp_workflow_template WHERE MODULE_ID = '%s' AND ENTITY = '%s' AND DOCUMENT_TYPE = '%s' ORDER BY ID ASC",
                $helper->forSql((string)($documentType[0] ?? '')),
                $helper->forSql((string)($documentType[1] ?? '')),
                $helper->forSql((string)($documentType[2] ?? ''))
            );
            $recordset = $connection->query($sql);

            while ($row = $recordset->fetch())
            {
                $rows[] = $this->normalizeValue($row);
            }
        }
        catch (\Throwable $e)
        {
            $this->warnings[] = 'Не удалось выгрузить шаблоны БП прямым запросом для ' . implode(':', $documentType) . ': ' . $e->getMessage();
        }

        return $rows;
    }

    private function exportAutomation(string $entityCode, array $definition): array
    {
        $entityTypeId = (int)$definition['entity_type_id'];
        $templates = $this->loadAutomationTemplates($entityTypeId);
        $triggers = $this->loadAutomationTriggers($entityTypeId);

        return [
            'supported' => $templates !== null || $triggers !== null,
            'entity_type_id' => $entityTypeId,
            'robots' => [
                'raw' => $templates ?? [],
                'grouped_by_stage' => $this->groupAutomationRowsByStage($templates ?? []),
            ],
            'triggers' => [
                'raw' => $triggers ?? [],
                'grouped_by_stage' => $this->groupAutomationRowsByStage($triggers ?? []),
            ],
            'notes' => $entityCode === 'deal' || $entityCode === 'lead'
                ? []
                : ['Триггеры в CRM официально относятся прежде всего к лидам и сделкам; для контактов и компаний секция может оказаться пустой на части версий коробки.'],
        ];
    }

    private function loadAutomationTemplates(int $entityTypeId): ?array
    {
        $className = '\\Bitrix\\Crm\\Automation\\Engine\\Entity\\TemplateTable';
        if (!class_exists($className))
        {
            return null;
        }

        $rows = [];

        try
        {
            $iterator = $className::getList([
                'select' => ['*'],
                'filter' => ['=ENTITY_TYPE_ID' => $entityTypeId],
                'order' => ['ID' => 'ASC'],
            ]);

            while ($row = $iterator->fetch())
            {
                $rows[] = $this->normalizeAutomationTemplateRow($row);
            }

            return $rows;
        }
        catch (\Throwable $e)
        {
            $this->warnings[] = 'Не удалось выгрузить роботов через TemplateTable для ENTITY_TYPE_ID=' . $entityTypeId . ': ' . $e->getMessage();
        }

        try
        {
            $tableName = method_exists($className, 'getTableName') ? (string)$className::getTableName() : 'b_crm_automation_template';
            $connection = Application::getConnection();
            $sql = 'SELECT * FROM ' . $tableName . ' WHERE ENTITY_TYPE_ID = ' . (int)$entityTypeId . ' ORDER BY ID ASC';
            $recordset = $connection->query($sql);

            while ($row = $recordset->fetch())
            {
                $rows[] = $this->normalizeAutomationTemplateRow($row);
            }

            return $rows;
        }
        catch (\Throwable $e)
        {
            $this->warnings[] = 'Не удалось выгрузить роботов прямым запросом для ENTITY_TYPE_ID=' . $entityTypeId . ': ' . $e->getMessage();
        }

        return [];
    }

    private function normalizeAutomationTemplateRow(array $row): array
    {
        $row = $this->normalizeValue($row);

        if (class_exists('\\Bitrix\\Crm\\Automation\\Engine\\Template'))
        {
            try
            {
                $template = new \Bitrix\Crm\Automation\Engine\Template($row);
                $normalized = $template->toArray();
                if (is_array($normalized))
                {
                    $row['NORMALIZED_TEMPLATE'] = $this->normalizeValue($normalized);
                }
            }
            catch (\Throwable $e)
            {
                $this->warnings[] = 'Не удалось нормализовать шаблон роботов #' . ($row['ID'] ?? '?') . ': ' . $e->getMessage();
            }
        }

        return $row;
    }

    private function loadAutomationTriggers(int $entityTypeId): ?array
    {
        $className = '\\Bitrix\\Crm\\Automation\\Trigger\\Entity\\TriggerTable';
        if (!class_exists($className))
        {
            return null;
        }

        $rows = [];

        try
        {
            $iterator = $className::getList([
                'select' => ['*'],
                'filter' => ['=ENTITY_TYPE_ID' => $entityTypeId],
                'order' => ['ID' => 'ASC'],
            ]);

            while ($row = $iterator->fetch())
            {
                $rows[] = $this->normalizeValue($row);
            }

            return $rows;
        }
        catch (\Throwable $e)
        {
            $this->warnings[] = 'Не удалось выгрузить триггеры через TriggerTable для ENTITY_TYPE_ID=' . $entityTypeId . ': ' . $e->getMessage();
        }

        try
        {
            $tableName = method_exists($className, 'getTableName') ? (string)$className::getTableName() : 'b_crm_automation_trigger';
            $connection = Application::getConnection();
            $sql = 'SELECT * FROM ' . $tableName . ' WHERE ENTITY_TYPE_ID = ' . (int)$entityTypeId . ' ORDER BY ID ASC';
            $recordset = $connection->query($sql);

            while ($row = $recordset->fetch())
            {
                $rows[] = $this->normalizeValue($row);
            }

            return $rows;
        }
        catch (\Throwable $e)
        {
            $this->warnings[] = 'Не удалось выгрузить триггеры прямым запросом для ENTITY_TYPE_ID=' . $entityTypeId . ': ' . $e->getMessage();
        }

        return [];
    }

    private function groupAutomationRowsByStage(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row)
        {
            $stageKey = $this->findFirstFilledValue($row, [
                'DOCUMENT_STATUS',
                'STATUS_ID',
                'STAGE_ID',
                'DOCUMENT_STATUS_ID',
                'TRIGGER_STATUS',
            ]);

            $categoryKey = $this->findFirstFilledValue($row, [
                'CATEGORY_ID',
                'CATEGORY',
            ]);

            $groupKey = ($categoryKey !== null ? 'category:' . $categoryKey . '|' : '') . 'stage:' . ($stageKey ?? '__UNKNOWN__');
            $grouped[$groupKey][] = $row;
        }

        ksort($grouped);

        return $grouped;
    }

    private function findFirstFilledValue(array $row, array $fieldNames): ?string
    {
        foreach ($fieldNames as $fieldName)
        {
            if (!array_key_exists($fieldName, $row))
            {
                continue;
            }

            $value = $row[$fieldName];
            if ($value === null || $value === '')
            {
                continue;
            }

            if (is_scalar($value))
            {
                return (string)$value;
            }
        }

        return null;
    }

    private function resolveOutputPath(?string $requestedOutputPath): string
    {
        $requestedOutputPath = trim((string)$requestedOutputPath);
        if ($requestedOutputPath === '')
        {
            $baseDirectory = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/upload/crm_blueprints';
            if (!is_dir($baseDirectory) && !mkdir($baseDirectory, 0775, true) && !is_dir($baseDirectory))
            {
                throw new RuntimeException('Не удалось создать каталог для выгрузки: ' . $baseDirectory);
            }

            return $baseDirectory . '/crm-blueprint-' . date('Y-m-d-H-i-s') . '.json';
        }

        if ($requestedOutputPath[0] !== '/')
        {
            $requestedOutputPath = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/' . ltrim($requestedOutputPath, '/');
        }

        $directory = dirname($requestedOutputPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory))
        {
            throw new RuntimeException('Не удалось создать каталог назначения: ' . $directory);
        }

        return $requestedOutputPath;
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

        $bytes = file_put_contents($outputPath, $json);
        if ($bytes === false)
        {
            throw new RuntimeException('Не удалось записать файл: ' . $outputPath);
        }
    }

    private function normalizeValue($value)
    {
        if ($value instanceof \DateTimeInterface)
        {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value))
        {
            $normalized = [];
            foreach ($value as $key => $item)
            {
                $normalized[$key] = $this->normalizeValue($item);
            }

            return $normalized;
        }

        if (is_object($value))
        {
            if (method_exists($value, '__toString'))
            {
                return (string)$value;
            }

            if ($value instanceof \JsonSerializable)
            {
                return $this->normalizeValue($value->jsonSerialize());
            }

            return get_class($value);
        }

        return $value;
    }
}

function crmBlueprintExporterResolveRequestedOutputPath(): ?string
{
    if (PHP_SAPI === 'cli')
    {
        global $argv;

        if (!is_array($argv))
        {
            return null;
        }

        foreach ($argv as $argument)
        {
            if (strpos((string)$argument, '--output=') === 0)
            {
                return substr((string)$argument, 9);
            }
        }

        return null;
    }

    return isset($_REQUEST['output']) ? (string)$_REQUEST['output'] : null;
}

function crmBlueprintExporterRespond(array $payload, int $statusCode = 200): void
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
    $documentRoot = crmBlueprintExporterFindDocumentRoot();
    if ($documentRoot === null)
    {
        throw new RuntimeException('Не найден /bitrix/modules/main/include/prolog_before.php. Скрипт должен запускаться внутри коробочного Битрикс24.');
    }

    $_SERVER['DOCUMENT_ROOT'] = $documentRoot;
    require_once $documentRoot . '/bitrix/modules/main/include/prolog_before.php';

    $exporter = new CrmPortalBlueprintExporter();
    $result = $exporter->export(crmBlueprintExporterResolveRequestedOutputPath());
    crmBlueprintExporterRespond($result);
}
catch (\Throwable $e)
{
    crmBlueprintExporterRespond([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], 500);
}
