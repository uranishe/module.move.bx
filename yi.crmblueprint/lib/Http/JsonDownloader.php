<?php

declare(strict_types=1);

namespace Yi\CrmBlueprint\Http;

use Bitrix\Main\Web\HttpClient;
use Yi\CrmBlueprint\Storage\FileStorage;

final class JsonDownloader
{
    private FileStorage $storage;

    public function __construct(?FileStorage $storage = null)
    {
        $this->storage = $storage ?? new FileStorage();
    }

    public function downloadToLocalFile(string $url): array
    {
        $url = trim($url);
        if ($url === '')
        {
            throw new \RuntimeException('Не указан URL JSON-файла.');
        }

        $httpClient = new HttpClient([
            'socketTimeout' => 30,
            'streamTimeout' => 30,
            'disableSslVerification' => false,
        ]);

        $body = $httpClient->get($url);
        if (!is_string($body) || $body === '')
        {
            throw new \RuntimeException('Не удалось скачать JSON по ссылке: ' . $url);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded))
        {
            throw new \RuntimeException('По ссылке получен невалидный JSON: ' . json_last_error_msg());
        }

        $localPath = $this->storage->generateFilePath('sources', 'source', 'json');
        if (file_put_contents($localPath, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)) === false)
        {
            throw new \RuntimeException('Не удалось сохранить скачанный JSON во временный файл.');
        }

        return [
            'source_url' => $url,
            'local_path' => $localPath,
            'local_url' => $this->storage->toWebPath($localPath),
        ];
    }
}
