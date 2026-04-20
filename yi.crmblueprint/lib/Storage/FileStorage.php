<?php

declare(strict_types=1);

namespace Yi\CrmBlueprint\Storage;

final class FileStorage
{
    public const MODULE_ID = 'yi.crmblueprint';
    public const UPLOAD_DIR = '/upload/yi.crmblueprint';

    public function ensureBaseDirectory(): string
    {
        return $this->ensureDirectory(self::UPLOAD_DIR);
    }

    public function ensureDirectory(string $relativeDirectory): string
    {
        $documentRoot = $this->getDocumentRoot();
        $relativeDirectory = '/' . ltrim($relativeDirectory, '/');
        $absoluteDirectory = rtrim($documentRoot, '/') . $relativeDirectory;

        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory))
        {
            throw new \RuntimeException('Не удалось создать каталог: ' . $absoluteDirectory);
        }

        return $absoluteDirectory;
    }

    public function generateFilePath(string $subDirectory, string $prefix, string $extension = 'json'): string
    {
        $absoluteDirectory = $this->ensureDirectory(self::UPLOAD_DIR . '/' . trim($subDirectory, '/'));

        return $absoluteDirectory . '/' . $prefix . '-' . date('Y-m-d-H-i-s') . '-' . substr(md5(uniqid('', true)), 0, 8) . '.' . $extension;
    }

    public function toWebPath(string $absolutePath): string
    {
        $documentRoot = rtrim($this->getDocumentRoot(), '/');

        if (strpos($absolutePath, $documentRoot) === 0)
        {
            return substr($absolutePath, strlen($documentRoot));
        }

        return $absolutePath;
    }

    public function toAbsolutePath(string $path): string
    {
        if ($path === '')
        {
            return $path;
        }

        if ($path[0] === '/')
        {
            $documentRoot = rtrim($this->getDocumentRoot(), '/');
            $candidate = $documentRoot . $path;
            if (is_file($candidate) || is_dir($candidate))
            {
                return $candidate;
            }

            return $path;
        }

        return rtrim($this->getDocumentRoot(), '/') . '/' . ltrim($path, '/');
    }

    public function getDocumentRoot(): string
    {
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string)$_SERVER['DOCUMENT_ROOT']) : '';
        if ($documentRoot === '')
        {
            throw new \RuntimeException('DOCUMENT_ROOT не определен.');
        }

        return $documentRoot;
    }
}
