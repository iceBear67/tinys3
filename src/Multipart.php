<?php

declare(strict_types=1);

final class MultipartApi
{
    private const XMLNS = 'http://s3.amazonaws.com/doc/2006-03-01/';
    private const MIN_PART = 5 * 1024 * 1024;
    private const MAX_PART = 5 * 1024 * 1024 * 1024;
    private const MAX_PARTS = 10000;
    private const AUTH_QUERY = ['X-Amz-Algorithm', 'X-Amz-Credential', 'X-Amz-Date', 'X-Amz-Expires', 'X-Amz-SignedHeaders', 'X-Amz-Signature'];
    private array $headers = [];
    private array $query = [];
    private string $bucket;
    private string $key;
    private string $id;

    private ?string $payloadHash;

    public function __construct(private Store $store, private PDO $db, private string $root, S3Request $request)
    {
        $this->headers = $request->headers;
        $this->query = $request->query;
        $this->bucket = $request->bucket;
        $this->key = $request->key;
        $this->payloadHash = $request->payloadHash;
        $this->id = $this->query['uploadId'] ?? '';
    }

    public function serve(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $operation = match (true) {
            isset($this->query['uploads']) && $method === 'POST' && $this->key !== '' => 'CreateMultipartUpload',
            isset($this->query['uploads']) && $method === 'GET' && $this->key === '' => 'ListMultipartUploads',
            isset($this->query['uploadId']) && $this->key !== '' => match ($method) {
                'PUT' => 'UploadPart', 'GET' => 'ListParts', 'POST' => 'CompleteMultipartUpload', 'DELETE' => 'AbortMultipartUpload',
                default => null,
            },
            default => null,
        };
        if ($operation === null) {
            throw new S3Error('InvalidRequest', 'Invalid multipart operation.', 400);
        }
        $this->validateOptions($operation);
        $body = $operation === 'UploadPart' ? '' : $this->smallBody($operation === 'CompleteMultipartUpload' ? 3 * 1024 * 1024 : 0);
        $this->store->listObjects($this->bucket, '', '', 0, null);
        if ($operation === 'CreateMultipartUpload') {
            $this->initiate();
            return;
        }
        if ($operation === 'ListMultipartUploads') {
            $this->listUploads();
            return;
        }
        if (!preg_match('/\A[0-9]{16}[0-9a-f]{32}\z/', $this->id)) {
            throw new S3Error('NoSuchUpload', 'The upload does not exist.', 404);
        }
        if ($operation === 'UploadPart') {
            $this->uploadPart();
            return;
        }
        $this->withUpload(function (array $upload) use ($operation, $body) {
            switch ($operation) {
                case 'ListParts':
                    $this->listParts($upload['crc32_type']);
                    break;
                case 'CompleteMultipartUpload':
                    $this->complete($upload, $body);
                    break;
                case 'AbortMultipartUpload':
                    $this->removeUpload();
                    http_response_code(204);
                    break;
            }
        }, $operation === 'CompleteMultipartUpload');
    }

    private function withUpload(Closure $operation, bool $requireActive): mixed
    {
        $lock = 'tinys3.mpu.' . $this->id;
        if ((int) $this->sql('SELECT GET_LOCK(?, 10)', [$lock])->fetchColumn() !== 1) {
            throw new S3Error('SlowDown', 'The upload is busy. Retry the request.', 503);
        }
        try {
            $upload = $this->sql('SELECT content_type, metadata, crc32_type, publishing FROM multipart_uploads
                WHERE upload_id = ? AND bucket = ? AND object_key = ?', [$this->id, $this->bucket, $this->key])->fetch(PDO::FETCH_ASSOC);
            if (!$upload) {
                throw new S3Error('NoSuchUpload', 'The upload does not exist.', 404);
            }
            if ($requireActive && $upload['publishing']) {
                throw new S3Error('ConditionalRequestConflict', 'Publication was interrupted. Check the destination object and abort this upload.', 409);
            }
            return $operation($upload);
        } finally {
            $this->sql('SELECT RELEASE_LOCK(?)', [$lock])->fetchColumn();
        }
    }

    private function sql(string $sql, array $parameters): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    private function validateOptions(string $operation): void
    {
        $queries = match ($operation) {
            'CreateMultipartUpload' => ['uploads'],
            'ListMultipartUploads' => ['uploads', 'prefix', 'key-marker', 'upload-id-marker', 'max-uploads', 'encoding-type'],
            'ListParts' => ['uploadId', 'part-number-marker', 'max-parts'],
            'UploadPart' => ['uploadId', 'partNumber'],
            default => ['uploadId'],
        };
        $headers = match ($operation) {
            'CreateMultipartUpload' => ['x-amz-checksum-algorithm', 'x-amz-checksum-type', 'x-amz-storage-class'],
            'UploadPart' => ['x-amz-sdk-checksum-algorithm', 'x-amz-checksum-crc32'],
            'CompleteMultipartUpload' => ['x-amz-checksum-type', 'x-amz-checksum-crc32', 'x-amz-mp-object-size'],
            default => [],
        };
        foreach ($this->query as $name => $value) {
            $name = (string) $name;
            if (isset($this->query['X-Amz-Algorithm']) && (in_array($name, $headers, true)
                || ($operation === 'CreateMultipartUpload' && str_starts_with($name, 'x-amz-meta-')))) {
                if (isset($this->headers[$name]) && $this->headers[$name] !== $value) {
                    throw new S3Error('InvalidRequest', 'Conflicting query and header values.', 400);
                }
                $this->headers[$name] = $value;
            } elseif (!in_array($name, [...self::AUTH_QUERY, 'x-id', ...$queries], true)) {
                self::unsupported($name);
            }
        }
        if ((isset($this->query['uploads']) && $this->query['uploads'] !== '') || ($this->query['x-id'] ?? $operation) !== $operation) {
            throw new S3Error('InvalidRequest', 'Inconsistent multipart operation parameters.', 400);
        }
        foreach ($this->headers as $name => $value) {
            if (str_starts_with($name, 'x-amz-meta-') && $operation === 'CreateMultipartUpload') {
                continue;
            }
            if (str_starts_with($name, 'x-amz-') && !in_array($name, ['x-amz-date', 'x-amz-user-agent', 'x-amz-content-sha256', ...$headers], true)) {
                self::unsupported($name);
            }
            if (str_starts_with($name, 'if-') || in_array($name, ['range', 'trailer', 'content-range'], true)) {
                self::unsupported($name);
            }
            if ($operation === 'CreateMultipartUpload' && in_array($name, ['cache-control', 'content-disposition', 'content-language', 'expires'], true)) {
                self::unsupported($name);
            }
        }
        if (strtolower($this->headers['content-encoding'] ?? 'identity') !== 'identity'
            || strtolower($this->headers['transfer-encoding'] ?? 'chunked') !== 'chunked'
            || isset($this->headers['transfer-encoding'], $this->headers['content-length'])) {
            self::unsupported('request body encoding');
        }
        foreach (['x-amz-checksum-algorithm', 'x-amz-sdk-checksum-algorithm'] as $name) {
            if (isset($this->headers[$name]) && $this->headers[$name] !== 'CRC32') {
                self::unsupported($name);
            }
        }
        if (isset($this->headers['x-amz-storage-class']) && $this->headers['x-amz-storage-class'] !== 'STANDARD') {
            self::unsupported('x-amz-storage-class');
        }
        if (isset($this->headers['x-amz-checksum-type']) && !in_array($this->headers['x-amz-checksum-type'], ['FULL_OBJECT', 'COMPOSITE'], true)) {
            self::unsupported('x-amz-checksum-type');
        }
    }

    private static function unsupported(string $name): never
    {
        throw new S3Error('NotImplemented', 'Unsupported multipart option: ' . $name, 501);
    }

    private static function number(string $value, int $minimum, int $maximum, string $name): int
    {
        if (!ctype_digit($value) || (int) $value < $minimum || (int) $value > $maximum) {
            throw new S3Error('InvalidArgument', 'Invalid ' . $name . '.', 400);
        }
        return (int) $value;
    }

    private function checkLength(int $size): void
    {
        if (isset($this->headers['content-length']) && self::number($this->headers['content-length'], 0, self::MAX_PART, 'Content-Length') !== $size) {
            throw new S3Error('IncompleteBody', 'The body length differs from Content-Length.', 400);
        }
    }

    private function checkDigests(string $md5, ?string $sha256): void
    {
        if (isset($this->headers['content-md5']) && !hash_equals(base64_encode($md5), $this->headers['content-md5'])) {
            throw new S3Error('BadDigest', 'The request body MD5 does not match.', 400);
        }
        if ($this->payloadHash !== null && !hash_equals($this->payloadHash, $sha256)) {
            throw new S3Error('XAmzContentSHA256Mismatch', 'The request body SHA-256 does not match.', 400);
        }
    }

    private function smallBody(int $limit): string
    {
        $body = file_get_contents('php://input', false, null, 0, $limit + 1);
        if ($body === false || strlen($body) > $limit) {
            throw new S3Error('InvalidRequest', 'Invalid request body length.', 400);
        }
        $this->checkLength(strlen($body));
        $this->checkDigests(md5($body, true), $this->payloadHash === null ? null : hash('sha256', $body));
        return $body;
    }

    private static function element(string $tag, string|int $value): string
    {
        $value = (string) $value;
        if (preg_match('/[^\x{9}\x{a}\x{d}\x{20}-\x{d7ff}\x{e000}-\x{fffd}\x{10000}-\x{10ffff}]/u', $value) !== 0) {
            throw new S3Error('InvalidArgument', 'The value cannot be represented in XML.', 400);
        }
        $escaped = str_replace("\r", '&#13;', htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        return '<' . $tag . '>' . $escaped . '</' . $tag . '>';
    }

    private static function xml(string $name, string $contents): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><' . $name . ' xmlns="' . self::XMLNS . '">' . $contents . '</' . $name . '>';
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Length: ' . strlen($body));
        echo $body;
    }

    private static function timestamp(string $date): string
    {
        return str_replace(' ', 'T', substr($date, 0, 23)) . 'Z';
    }

    private static function owners(): string
    {
        $owner = self::element('ID', hash('sha256', 'tinys3')) . '<DisplayName>tinys3</DisplayName>';
        return '<Initiator>' . $owner . '</Initiator><Owner>' . $owner . '</Owner>';
    }

    private function initiate(): void
    {
        $keyXml = self::element('Key', $this->key);
        $type = $this->headers['content-type'] ?? 'application/octet-stream';
        if (!preg_match('/\A[\x20-\x7e]{1,255}\z/', $type)) {
            throw new S3Error('InvalidArgument', 'Invalid content type.', 400);
        }
        $metadata = [];
        $bytes = 0;
        foreach ($this->headers as $name => $value) {
            if (str_starts_with($name, 'x-amz-meta-')) {
                $name = substr($name, 11);
                if (!preg_match("/\A[!#$%&'*+.^_`|~0-9a-z-]+\z/", $name) || !preg_match('//u', $value) || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value)) {
                    throw new S3Error('InvalidArgument', 'Invalid object metadata.', 400);
                }
                $bytes += strlen($name) + strlen($value);
                $metadata[$name] = $value;
            }
        }
        if ($bytes > 2048) {
            throw new S3Error('MetadataTooLarge', 'User metadata exceeds 2048 bytes.', 400);
        }
        $checksumType = null;
        if (isset($this->headers['x-amz-checksum-algorithm'])) {
            $checksumType = $this->headers['x-amz-checksum-type'] ?? 'COMPOSITE';
        } elseif (isset($this->headers['x-amz-checksum-type'])) {
            throw new S3Error('InvalidRequest', 'Checksum type requires a checksum algorithm.', 400);
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->id = $now->format('Uu') . bin2hex(random_bytes(16));
        try {
            $this->sql('INSERT INTO multipart_uploads (upload_id, bucket, object_key, content_type, metadata, crc32_type, initiated)
                VALUES (?, ?, ?, ?, ?, ?, ?)', [$this->id, $this->bucket, $this->key, $type, json_encode((object) $metadata, JSON_THROW_ON_ERROR), $checksumType, $now->format('Y-m-d H:i:s.u')]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1452) {
                throw new S3Error('NoSuchBucket', 'The bucket does not exist.', 404);
            }
            throw $e;
        }
        if ($checksumType !== null) {
            header('x-amz-checksum-algorithm: CRC32');
            header('x-amz-checksum-type: ' . $checksumType);
        }
        self::xml('InitiateMultipartUploadResult', self::element('Bucket', $this->bucket) . $keyXml . self::element('UploadId', $this->id));
    }

    private function directory(): string
    {
        return $this->root . '/multipart/' . substr($this->id, -2) . '/' . $this->id;
    }

    private function uploadPart(): void
    {
        $number = self::number($this->query['partNumber'] ?? '', 1, self::MAX_PARTS, 'partNumber');
        $expected = $this->headers['x-amz-checksum-crc32'] ?? null;
        if (isset($this->headers['content-length'])) {
            self::number($this->headers['content-length'], 0, self::MAX_PART, 'Content-Length');
        }
        $dir = $this->directory();
        $fileId = bin2hex(random_bytes(16));
        $path = $dir . '/' . $fileId;
        $file = $this->withUpload(function (array $upload) use ($expected, $dir, $path) {
            if (($upload['crc32_type'] !== null || isset($this->headers['x-amz-sdk-checksum-algorithm'])) && $expected === null) {
                throw new S3Error('InvalidRequest', 'The CRC32 part checksum is required.', 400);
            }
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create the multipart directory.');
            }
            $file = @fopen($path, 'xb');
            if (!$file) {
                throw new RuntimeException('Cannot create the part file.');
            }
            return $file;
        }, true);
        $discard = true;
        $input = null;
        try {
            $input = fopen('php://input', 'rb');
            $size = 0;
            $md5 = hash_init('md5');
            $crc = hash_init('crc32b');
            $sha = $this->payloadHash === null ? null : hash_init('sha256');
            while (!feof($input)) {
                $chunk = fread($input, 1048576);
                if ($chunk === false || ($chunk === '' && !feof($input))) {
                    throw new S3Error('IncompleteBody', 'Cannot read the part body.', 400);
                }
                $size += strlen($chunk);
                if ($size > self::MAX_PART) {
                    throw new S3Error('EntityTooLarge', 'The part exceeds 5 GiB.', 400);
                }
                if (fwrite($file, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Cannot write the part file.');
                }
                hash_update($md5, $chunk);
                hash_update($crc, $chunk);
                if ($sha !== null) {
                    hash_update($sha, $chunk);
                }
            }
            $md5 = hash_final($md5, true);
            $crc = hash_final($crc, true);
            $this->checkLength($size);
            $this->checkDigests($md5, $sha === null ? null : hash_final($sha));
            if ($expected !== null && !hash_equals(base64_encode($crc), $expected)) {
                throw new S3Error('BadDigest', 'The part CRC32 does not match.', 400);
            }
            if (!fsync($file) || !fclose($file)) {
                throw new RuntimeException('Cannot finish writing the part file.');
            }
            $this->withUpload(function () use ($number, $size, $fileId, $md5, $crc, $dir, &$discard) {
                $old = $this->sql('SELECT LOWER(HEX(file_id)) FROM multipart_parts WHERE upload_id = ? AND part_number = ?', [$this->id, $number])->fetchColumn();
                // A lost autocommit acknowledgement can leave the new file referenced.
                $discard = false;
                if ($old === false) {
                    $this->sql('INSERT INTO multipart_parts (file_id, size, md5, crc32, upload_id, part_number, modified)
                        VALUES (UNHEX(?), ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', [$fileId, $size, $md5, $crc, $this->id, $number]);
                } else {
                    $this->sql('UPDATE multipart_parts SET file_id = UNHEX(?), size = ?, md5 = ?, crc32 = ?, modified = UTC_TIMESTAMP(6)
                        WHERE upload_id = ? AND part_number = ?', [$fileId, $size, $md5, $crc, $this->id, $number]);
                    if (!@unlink($dir . '/' . $old)) {
                        error_log('Cannot remove replaced multipart file ' . $dir . '/' . $old);
                    }
                }
            }, true);
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($file)) {
                fclose($file);
            }
            if ($discard && !@unlink($path) && file_exists($path)) {
                error_log('Cannot remove unpublished part file ' . $path);
            }
        }
        header('ETag: "' . bin2hex($md5) . '"');
        if ($expected !== null) {
            header('x-amz-checksum-crc32: ' . base64_encode($crc));
        }
        header('Content-Length: 0');
    }

    private function listParts(?string $crcType): void
    {
        $marker = self::number($this->query['part-number-marker'] ?? '0', 0, self::MAX_PARTS, 'part-number-marker');
        $max = self::number($this->query['max-parts'] ?? '1000', 0, 1000, 'max-parts');
        $limit = $max + 1;
        $rows = $this->sql("SELECT part_number, size, md5, crc32, modified FROM multipart_parts WHERE upload_id = ? AND part_number > ? ORDER BY part_number LIMIT $limit",
            [$this->id, $marker])->fetchAll(PDO::FETCH_ASSOC);
        $more = $max > 0 && count($rows) > $max;
        $xml = self::element('Bucket', $this->bucket) . self::element('Key', $this->key) . self::element('UploadId', $this->id)
            . self::owners() . '<StorageClass>STANDARD</StorageClass>' . self::element('PartNumberMarker', $marker);
        if ($more) {
            $xml .= self::element('NextPartNumberMarker', $rows[$max - 1]['part_number']);
        }
        $xml .= self::element('MaxParts', $max) . self::element('IsTruncated', $more ? 'true' : 'false');
        if ($crcType !== null) {
            $xml .= '<ChecksumAlgorithm>CRC32</ChecksumAlgorithm>' . self::element('ChecksumType', $crcType);
        }
        foreach (array_slice($rows, 0, $max) as $row) {
            $xml .= '<Part>' . self::element('PartNumber', $row['part_number']) . self::element('LastModified', self::timestamp($row['modified']))
                . self::element('ETag', '"' . bin2hex($row['md5']) . '"') . self::element('Size', $row['size'])
                . ($crcType === null ? '' : self::element('ChecksumCRC32', base64_encode($row['crc32']))) . '</Part>';
        }
        self::xml('ListPartsResult', $xml);
    }

    private function listUploads(): void
    {
        $q = $this->query;
        $prefix = $q['prefix'] ?? '';
        $marker = $q['key-marker'] ?? '';
        $idMarker = $q['upload-id-marker'] ?? '';
        if ((isset($q['encoding-type']) && $q['encoding-type'] !== 'url') || (isset($q['upload-id-marker'])
            && ($marker === '' || !preg_match('/\A[0-9]{16}[0-9a-f]{32}\z/', $idMarker)))) {
            throw new S3Error('InvalidArgument', 'Invalid multipart listing option.', 400);
        }
        $max = self::number($q['max-uploads'] ?? '1000', 0, 1000, 'max-uploads');
        $limit = $max + 1;
        $sql = 'SELECT object_key, upload_id, initiated, crc32_type FROM multipart_uploads WHERE bucket = ? AND object_key >= ?';
        $parameters = [$this->bucket, $prefix];
        if ($prefix !== '') {
            // A valid UTF-8 key cannot contain 0xff.
            $sql .= ' AND object_key < UNHEX(?)';
            $parameters[] = bin2hex($prefix) . 'ff';
        }
        if ($marker !== '') {
            $sql .= $idMarker === '' ? ' AND object_key > ?' : ' AND (object_key > ? OR (object_key = ? AND upload_id > ?))';
            $parameters[] = $marker;
            if ($idMarker !== '') {
                array_push($parameters, $marker, $idMarker);
            }
        }
        $rows = $this->sql($sql . " ORDER BY object_key, upload_id LIMIT $limit", $parameters)->fetchAll(PDO::FETCH_ASSOC);
        $more = $max > 0 && count($rows) > $max;
        $encode = static fn(string $s): string => isset($q['encoding-type']) ? rawurlencode($s) : $s;
        $xml = self::element('Bucket', $this->bucket) . self::element('KeyMarker', $encode($marker))
            . self::element('UploadIdMarker', $idMarker) . self::element('Prefix', $encode($prefix));
        if ($more) {
            $xml .= self::element('NextKeyMarker', $encode($rows[$max - 1]['object_key'])) . self::element('NextUploadIdMarker', $rows[$max - 1]['upload_id']);
        }
        if (isset($q['encoding-type'])) {
            $xml .= '<EncodingType>url</EncodingType>';
        }
        $xml .= self::element('MaxUploads', $max) . self::element('IsTruncated', $more ? 'true' : 'false');
        foreach (array_slice($rows, 0, $max) as $row) {
            $xml .= '<Upload>' . self::element('Key', $encode($row['object_key'])) . self::element('UploadId', $row['upload_id'])
                . self::owners() . '<StorageClass>STANDARD</StorageClass>' . self::element('Initiated', self::timestamp($row['initiated']))
                . ($row['crc32_type'] === null ? '' : '<ChecksumAlgorithm>CRC32</ChecksumAlgorithm>' . self::element('ChecksumType', $row['crc32_type'])) . '</Upload>';
        }
        self::xml('ListMultipartUploadsResult', $xml);
    }

    private static function malformed(): never
    {
        throw new S3Error('MalformedXML', 'Invalid CompleteMultipartUpload XML.', 400);
    }

    private static function manifest(string $body): Generator
    {
        if ($body === '') {
            self::malformed();
        }
        $flags = LIBXML_NONET | (defined('LIBXML_NO_XXE') ? LIBXML_NO_XXE : 0);
        $reader = XMLReader::fromString($body, null, $flags);
        $reader->setParserProperty(XMLReader::LOADDTD, false);
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $namespace = '';
        $rootClosed = false;
        $count = 0;
        $fields = [];
        $field = null;
        try {
            while ($reader->read()) {
                $name = $reader->localName;
                $depth = $reader->depth;
                switch ($reader->nodeType) {
                    case XMLReader::ELEMENT:
                        if ($reader->isEmptyElement || $depth > 2 || ($depth > 0 && $reader->namespaceURI !== $namespace)) {
                            self::malformed();
                        }
                        if ($depth === 0) {
                            $namespace = $reader->namespaceURI;
                            if ($name !== 'CompleteMultipartUpload' || !in_array($namespace, ['', self::XMLNS], true)) {
                                self::malformed();
                            }
                        } elseif ($depth === 1) {
                            if ($name !== 'Part' || ++$count > self::MAX_PARTS) {
                                self::malformed();
                            }
                            $fields = [];
                        } else {
                            if (!in_array($name, ['PartNumber', 'ETag', 'ChecksumCRC32'], true) || isset($fields[$name])) {
                                self::malformed();
                            }
                            $field = $name;
                            $fields[$field] = '';
                        }
                        while ($reader->moveToNextAttribute()) {
                            if ($reader->namespaceURI !== 'http://www.w3.org/2000/xmlns/') {
                                self::malformed();
                            }
                        }
                        $reader->moveToElement();
                        break;
                    case XMLReader::END_ELEMENT:
                        if ($depth === 2) {
                            $field = null;
                        } elseif ($depth === 1) {
                            if (!isset($fields['PartNumber'], $fields['ETag'])) {
                                self::malformed();
                            }
                            yield $fields;
                        } else {
                            $rootClosed = true;
                        }
                        break;
                    case XMLReader::TEXT:
                    case XMLReader::CDATA:
                    case XMLReader::WHITESPACE:
                    case XMLReader::SIGNIFICANT_WHITESPACE:
                        if ($field !== null) {
                            $fields[$field] .= $reader->value;
                            if (strlen($fields[$field]) > 64) {
                                self::malformed();
                            }
                        } elseif (trim($reader->value) !== '') {
                            self::malformed();
                        }
                        break;
                    case XMLReader::COMMENT:
                    case XMLReader::XML_DECLARATION:
                        break;
                    default:
                        self::malformed();
                }
            }
            if (!$rootClosed || $count === 0 || libxml_get_last_error() !== false) {
                self::malformed();
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function streams(array $parts): Generator
    {
        foreach ($parts as $part) {
            $stream = @fopen($this->directory() . '/' . $part['file_id'], 'rb');
            if (!$stream) {
                throw new RuntimeException('Cannot open the multipart file.');
            }
            try {
                if (fstat($stream)['size'] !== (int) $part['size']) {
                    throw new RuntimeException('The stored multipart file has an incorrect length.');
                }
                yield $stream;
            } finally {
                fclose($stream);
            }
        }
    }

    private function complete(array $upload, string $body): void
    {
        $crcType = $upload['crc32_type'];
        if (($this->headers['x-amz-checksum-type'] ?? $crcType) !== $crcType || ($crcType === null && isset($this->headers['x-amz-checksum-crc32']))) {
            throw new S3Error('InvalidRequest', 'Checksum settings differ from upload initiation.', 400);
        }
        $rows = $this->sql('SELECT part_number, LOWER(HEX(file_id)) AS file_id, size, md5, crc32 FROM multipart_parts WHERE upload_id = ? ORDER BY part_number', [$this->id]);
        $row = $rows->fetch(PDO::FETCH_ASSOC);
        $parts = [];
        $length = 0;
        $lastNumber = 0;
        $lastSize = null;
        $etagHash = hash_init('md5');
        $crcHash = hash_init('crc32b');
        foreach (self::manifest($body) as $entry) {
            $number = self::number(trim($entry['PartNumber']), 1, self::MAX_PARTS, 'PartNumber');
            if ($number <= $lastNumber || ($crcType !== null && $number !== $lastNumber + 1)) {
                throw new S3Error('InvalidPartOrder', 'Parts must be ascending and checksummed uploads require consecutive part numbers starting at 1.', 400);
            }
            if ($lastSize !== null && $lastSize < self::MIN_PART) {
                throw new S3Error('EntityTooSmall', 'Every selected part except the last must contain at least 5 MiB.', 400);
            }
            while ($row && (int) $row['part_number'] < $number) {
                $row = $rows->fetch(PDO::FETCH_ASSOC);
            }
            if (!$row || (int) $row['part_number'] !== $number) {
                throw new S3Error('InvalidPart', 'A requested part is missing.', 400);
            }
            $etag = bin2hex($row['md5']);
            if (!in_array(trim($entry['ETag']), [$etag, '"' . $etag . '"'], true)
                || (isset($entry['ChecksumCRC32']) && !hash_equals(base64_encode($row['crc32']), trim($entry['ChecksumCRC32'])))) {
                throw new S3Error('InvalidPart', 'A requested part ETag or CRC32 does not match.', 400);
            }
            hash_update($etagHash, $row['md5']);
            hash_update($crcHash, $row['crc32']);
            $lastNumber = $number;
            $lastSize = (int) $row['size'];
            $length += $lastSize;
            $parts[] = ['file_id' => $row['file_id'], 'size' => $lastSize];
        }
        $rows->closeCursor();
        if (isset($this->headers['x-amz-mp-object-size']) && self::number($this->headers['x-amz-mp-object-size'], 0, self::MAX_PART * self::MAX_PARTS, 'object size') !== $length) {
            throw new S3Error('InvalidRequest', 'The multipart object size does not match.', 400);
        }
        $etag = hash_final($etagHash) . '-' . count($parts);
        $metadata = json_decode($upload['metadata'], true, flags: JSON_THROW_ON_ERROR);
        $checksumXml = '';
        if ($crcType !== null) {
            if ($crcType === 'FULL_OBJECT') {
                $crcHash = hash_init('crc32b');
                foreach ($this->streams($parts) as $i => $stream) {
                    if (hash_update_stream($crcHash, $stream) !== $parts[$i]['size']) {
                        throw new RuntimeException('Cannot checksum the complete part file.');
                    }
                }
            }
            $digest = base64_encode(hash_final($crcHash, true));
            $value = $crcType === 'COMPOSITE' ? $digest . '-' . count($parts) : $digest;
            if (isset($this->headers['x-amz-checksum-crc32']) && !in_array($this->headers['x-amz-checksum-crc32'], [$value, $digest], true)) {
                throw new S3Error('BadDigest', 'The multipart object CRC32 does not match.', 400);
            }
            $metadata['@s3-checksum'] = 'crc32:' . $value;
            $checksumXml = self::element('ChecksumCRC32', $value) . self::element('ChecksumType', $crcType);
        }
        $streams = function () use ($parts): Generator {
            yield from $this->streams($parts);
            // Store begins its publication transaction after consuming all streams.
            $this->sql('UPDATE multipart_uploads SET publishing = TRUE WHERE upload_id = ?', [$this->id]);
        };
        $this->store->put($this->bucket, $this->key, $streams(), $upload['content_type'], $metadata, null, null, $etag);
        $this->removeUpload();
        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $location = $scheme . '://' . $this->headers['host'] . '/' . $this->bucket . '/' . str_replace('%2F', '/', rawurlencode($this->key));
        self::xml('CompleteMultipartUploadResult', self::element('Location', $location) . self::element('Bucket', $this->bucket)
            . self::element('Key', $this->key) . self::element('ETag', '"' . $etag . '"') . $checksumXml);
    }

    private function removeUpload(): void
    {
        $this->sql('DELETE FROM multipart_uploads WHERE upload_id = ?', [$this->id]);
        $dir = $this->directory();
        if (!is_dir($dir)) {
            return;
        }
        $handle = @opendir($dir);
        if ($handle === false) {
            error_log('Cannot open multipart directory for cleanup ' . $dir);
            return;
        }
        try {
            while (($name = readdir($handle)) !== false) {
                if ($name !== '.' && $name !== '..' && !@unlink($dir . '/' . $name)) {
                    error_log('Cannot remove multipart file ' . $dir . '/' . $name);
                }
            }
        } finally {
            closedir($handle);
        }
        if (!@rmdir($dir)) {
            error_log('Cannot remove multipart directory ' . $dir);
        }
    }
}
