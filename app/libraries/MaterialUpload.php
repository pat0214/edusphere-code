<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class MaterialUploadException extends RuntimeException
{
    private $statusCode;

    public function __construct(string $message, int $statusCode)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}

class MaterialUpload
{
    private const MAX_BYTES = 25 * 1024 * 1024;
    private const CONTENT_TYPES = [
        'pdf' => 'application/pdf',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'zip' => 'application/zip',
    ];

    public function store($file, string $storageRoot): array
    {
        $details = $this->inspect($file);
        if (!is_dir($storageRoot) && !mkdir($storageRoot, 0750, true) && !is_dir($storageRoot)) {
            throw new MaterialUploadException('Material storage is unavailable.', 500);
        }

        $storageKey = uuid4();
        $destination = $storageRoot . DIRECTORY_SEPARATOR . $storageKey;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new MaterialUploadException('The uploaded file could not be stored.', 500);
        }

        return $details + ['storageKey' => $storageKey];
    }

    private function inspect($file): array
    {
        if (!is_array($file)) throw new MaterialUploadException('Choose a file to upload.', 422);
        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadError !== UPLOAD_ERR_OK) {
            $status = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 422;
            throw new MaterialUploadException(
                $status === 413 ? 'The uploaded file exceeds the server upload limit.' : 'The file could not be uploaded.',
                $status
            );
        }

        $bytes = (int)($file['size'] ?? 0);
        if ($bytes < 1 || $bytes > self::MAX_BYTES) {
            throw new MaterialUploadException('Files must be between 1 byte and 25 MB.', 413);
        }
        $name = basename(str_replace('\\', '/', (string)($file['name'] ?? '')));
        $name = trim((string)preg_replace('/[\x00-\x1f\x7f]/', '', $name));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::CONTENT_TYPES[$extension]) || !$this->validContent($file['tmp_name'] ?? '', $extension)) {
            throw new MaterialUploadException('Unsupported file type. Upload PDF, PowerPoint, Word, Excel, CSV, text, image, or ZIP files.', 415);
        }

        return [
            'name' => substr($name ?: "material.$extension", 0, 200),
            'bytes' => $bytes,
            'contentType' => self::CONTENT_TYPES[$extension],
        ];
    }

    private function validContent(string $path, string $extension): bool
    {
        $handle = fopen($path, 'rb');
        if (!$handle) return false;
        $header = fread($handle, 16);
        fclose($handle);
        if ($header === false) return false;

        if ($extension === 'pdf') return str_starts_with($header, '%PDF-');
        if ($extension === 'png') return str_starts_with($header, "\x89PNG\r\n\x1a\n");
        if (in_array($extension, ['jpg', 'jpeg'], true)) return str_starts_with($header, "\xff\xd8\xff");
        if ($extension === 'gif') return str_starts_with($header, 'GIF87a') || str_starts_with($header, 'GIF89a');
        if ($extension === 'webp') return substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP';
        if (in_array($extension, ['ppt', 'doc', 'xls'], true)) return str_starts_with($header, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1");
        if (in_array($extension, ['zip', 'pptx', 'docx', 'xlsx'], true)) {
            if (!str_starts_with($header, "PK\x03\x04") && !str_starts_with($header, "PK\x05\x06")) return false;
            if (!class_exists('ZipArchive') || $extension === 'zip') return true;
            $archive = new ZipArchive();
            if ($archive->open($path) !== true) return false;
            $requiredEntry = [
                'pptx' => 'ppt/presentation.xml',
                'docx' => 'word/document.xml',
                'xlsx' => 'xl/workbook.xml',
            ][$extension];
            $valid = $archive->locateName('[Content_Types].xml') !== false
                && $archive->locateName($requiredEntry) !== false;
            $archive->close();
            return $valid;
        }
        if (in_array($extension, ['csv', 'txt', 'md'], true)) {
            $contents = file_get_contents($path);
            return is_string($contents) && strpos($contents, "\0") === false;
        }
        return false;
    }
}
