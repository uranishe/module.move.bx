<?php

\Bitrix\Main\Loader::registerAutoLoadClasses(
    'yi.crmblueprint',
    [
        'Yi\\CrmBlueprint\\Storage\\FileStorage' => 'lib/Storage/FileStorage.php',
        'Yi\\CrmBlueprint\\Http\\JsonDownloader' => 'lib/Http/JsonDownloader.php',
        'Yi\\CrmBlueprint\\Service\\BlueprintExporter' => 'lib/Service/BlueprintExporter.php',
        'Yi\\CrmBlueprint\\Service\\BlueprintImporter' => 'lib/Service/BlueprintImporter.php',
        'Yi\\CrmBlueprint\\Service\\ModuleFacade' => 'lib/Service/ModuleFacade.php',
    ]
);
