<?php

declare(strict_types=1);

namespace Yi\CrmBlueprint\Service;

use Yi\CrmBlueprint\Http\JsonDownloader;
use Yi\CrmBlueprint\Storage\FileStorage;

final class ModuleFacade
{
    private FileStorage $storage;
    private JsonDownloader $downloader;

    public function __construct(?FileStorage $storage = null, ?JsonDownloader $downloader = null)
    {
        $this->storage = $storage ?? new FileStorage();
        $this->downloader = $downloader ?? new JsonDownloader($this->storage);
    }

    public function runExport(): array
    {
        $exportPath = $this->storage->generateFilePath('exports', 'crm-blueprint-export', 'json');
        $exporter = new BlueprintExporter();
        $result = $exporter->export($exportPath);
        $result['output_url'] = $this->storage->toWebPath($result['output_path']);

        return $result;
    }

    public function runImportFromUrl(string $url, string $mode, bool $replaceAutomation = true): array
    {
        $download = $this->downloader->downloadToLocalFile($url);
        $reportPath = $this->storage->generateFilePath('reports', $mode === 'apply' ? 'import-apply-report' : 'import-dry-report', 'json');

        $importer = new BlueprintImporter();
        $result = $importer->import($download['local_path'], [
            'mode' => $mode,
            'replace_automation' => $replaceAutomation,
            'report' => $reportPath,
        ]);

        $result['source_url'] = $download['source_url'];
        $result['source_local_path'] = $download['local_path'];
        $result['source_local_url'] = $download['local_url'];
        $result['report_url'] = $this->storage->toWebPath($result['report_path']);
        $result['report_data'] = $this->readJsonFile($result['report_path']);

        return $result;
    }

    private function readJsonFile(string $path): array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents) || $contents === '')
        {
            return [];
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : [];
    }
}
