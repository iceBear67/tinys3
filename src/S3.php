<?php

declare(strict_types=1);

final class S3Api
{
    private const XMLNS = 'http://s3.amazonaws.com/doc/2006-03-01/';
    private const CHECKSUMS = ['crc32' => 'crc32b', 'crc32c' => 'crc32c', 'sha1' => 'sha1', 'sha256' => 'sha256'];
    // The leading @ cannot occur in an HTTP metadata field name.
    private const STORED_CHECKSUM = '@s3-checksum';
    private array $headers = [];
    private array $query = [];
    private string $bucket;
    private string $key;

    private BucketAccess $access;
    private ?string $payloadHash;

    public function __construct(private Store $store, S3Request $request)
    {
        $this->headers = $request->headers;
        $this->query = $request->query;
        $this->bucket = $request->bucket;
        $this->key = $request->key;
        $this->access = $request->access;
        $this->payloadHash = $request->payloadHash;
    }

    private static function unsupported(string $item): never
    {
        throw new S3Error('NotImplemented', 'Unsupported S3 request option: ' . $item, 501);
    }

    public function serve(array $config): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $operation = match (true) {
            $this->bucket === '' => $method === 'GET' ? 'ListBuckets' : null,
            $this->key === '' => match ($method) {
                'GET' => match (true) {
                    isset($this->query['location']) => 'GetBucketLocation',
                    isset($this->query['list-type']) => 'ListObjectsV2',
                    default => 'ListObjects',
                },
                'POST' => isset($this->query['delete']) ? 'DeleteObjects' : null,
                'PUT' => 'CreateBucket', 'HEAD' => 'HeadBucket', 'DELETE' => 'DeleteBucket', default => null,
            },
            default => match ($method) {
                'GET' => 'GetObject', 'HEAD' => 'HeadObject', 'DELETE' => 'DeleteObject',
                'PUT' => isset($this->headers['x-amz-copy-source']) ? 'CopyObject' : 'PutObject', default => null,
            },
        };
        if ($operation === null) {
            self::unsupported($method);
        }
        $this->access->authorize($operation, $this->bucket);
        $this->validateOptions($operation);
        if ($operation === 'PutObject') {
            $this->putObject();
            return;
        }
        if ($operation === 'CreateBucket') {
            $this->createBucket($config);
            return;
        }
        if ($operation === 'DeleteObjects') {
            $this->deleteObjects();
            return;
        }
        $this->checkSmallBody(0);
        switch ($operation) {
            case 'ListBuckets':
                $items = '';
                foreach ($this->access->buckets($this->store) as $row) {
                    $items .= '<Bucket>' . self::element('Name', $row['name'])
                        . self::element('CreationDate', self::isoTime($row['created_at'])) . '</Bucket>';
                }
                self::xml('ListAllMyBucketsResult', self::owner() . '<Buckets>' . $items . '</Buckets>');
                break;
            case 'ListObjects':
            case 'ListObjectsV2':
                $this->listObjects($operation === 'ListObjectsV2');
                break;
            case 'GetBucketLocation':
                $this->store->listObjects($this->bucket, '', '', 0, null);
                $region = $config['region'] === 'us-east-1' ? '' : $config['region'];
                self::xml('LocationConstraint', htmlspecialchars($region, ENT_XML1, 'UTF-8'));
                break;
            case 'HeadBucket':
                $this->store->listObjects($this->bucket, '', '', 0, null);
                header('x-amz-bucket-region: ' . $config['region']);
                http_response_code(200);
                break;
            case 'DeleteBucket':
                $this->store->deleteBucket($this->bucket);
                http_response_code(204);
                break;
            case 'DeleteObject':
                $this->store->delete($this->bucket, $this->key);
                http_response_code(204);
                break;
            case 'GetObject':
            case 'HeadObject':
                $this->download($operation === 'HeadObject');
                break;
            case 'CopyObject':
                $this->copyObject();
                break;
        }
    }

    private function validateOptions(string $operation): void
    {
        $allowed = ['X-Amz-Algorithm', 'X-Amz-Credential', 'X-Amz-Date', 'X-Amz-Expires', 'X-Amz-SignedHeaders',
            'X-Amz-Signature', 'x-id'];
        if ($operation === 'ListObjects' || $operation === 'ListObjectsV2') {
            array_push($allowed, 'prefix', 'delimiter', 'max-keys', 'encoding-type');
            $allowed = array_merge($allowed, $operation === 'ListObjects' ? ['marker']
                : ['list-type', 'continuation-token', 'start-after', 'fetch-owner']);
        }
        if ($operation === 'GetBucketLocation' || $operation === 'DeleteObjects') {
            $subresource = $operation === 'GetBucketLocation' ? 'location' : 'delete';
            if ($this->query[$subresource] !== '') {
                throw new S3Error('InvalidArgument', 'Expected an empty subresource value.', 400);
            }
            $allowed[] = $subresource;
        }
        foreach (array_keys($this->query) as $name) {
            if (!in_array($name, $allowed, true)) {
                self::unsupported((string) $name);
            }
        }
        if (isset($this->query['x-id']) && $this->query['x-id'] !== $operation) {
            throw new S3Error('InvalidRequest', 'The operation identifier is inconsistent with the request.', 400);
        }
        $allowed = ['x-amz-content-sha256', 'x-amz-date', 'x-amz-user-agent'];
        if ($operation === 'CreateBucket' && strtolower($this->headers['x-amz-bucket-object-lock-enabled'] ?? '') === 'false') {
            $allowed[] = 'x-amz-bucket-object-lock-enabled';
        }
        if ($operation === 'PutObject' || $operation === 'DeleteObjects') {
            $allowed[] = 'x-amz-sdk-checksum-algorithm';
            foreach (self::CHECKSUMS as $name => $algorithm) {
                $allowed[] = 'x-amz-checksum-' . $name;
            }
        } elseif ($operation === 'CopyObject') {
            array_push($allowed, 'x-amz-copy-source', 'x-amz-metadata-directive');
        } elseif ($operation === 'GetObject' || $operation === 'HeadObject') {
            $allowed[] = 'x-amz-checksum-mode';
        }
        foreach ($this->headers as $name => $value) {
            if (str_starts_with($name, 'x-amz-meta-') && in_array($operation, ['PutObject', 'CopyObject'], true)) {
                continue;
            }
            if (str_starts_with($name, 'x-amz-') && !in_array($name, $allowed, true)) {
                self::unsupported($name);
            }
        }
        if (isset($this->headers['trailer']) || (isset($this->headers['transfer-encoding'])
            && strtolower(trim($this->headers['transfer-encoding'])) !== 'chunked') || (isset($this->headers['content-encoding'])
            && strtolower(trim($this->headers['content-encoding'])) !== 'identity')) {
            self::unsupported('encoded request body or trailers');
        }
        if (isset($this->headers['x-amz-checksum-mode']) && strtoupper($this->headers['x-amz-checksum-mode']) !== 'ENABLED') {
            self::unsupported('x-amz-checksum-mode');
        }
        $read = in_array($operation, ['GetObject', 'HeadObject'], true);
        foreach (['range', 'if-range', 'if-match', 'if-none-match', 'if-modified-since', 'if-unmodified-since'] as $name) {
            if (!$read && isset($this->headers[$name])) {
                self::unsupported($name);
            }
        }
        if ($operation === 'PutObject' || $operation === 'CopyObject') {
            foreach (['cache-control', 'content-disposition', 'content-language', 'expires'] as $name) {
                if (isset($this->headers[$name])) {
                    self::unsupported($name);
                }
            }
        }
    }

    private function checkSmallBody(int $limit): string
    {
        $body = file_get_contents('php://input', false, null, 0, $limit + 1);
        if ($body === false || strlen($body) > $limit) {
            throw new S3Error('InvalidRequest', 'The request body is too large for this operation.', 400);
        }
        if ($this->payloadHash !== null && !hash_equals($this->payloadHash, hash('sha256', $body))) {
            throw new S3Error('XAmzContentSHA256Mismatch', 'The request body SHA-256 does not match.', 400);
        }
        $md5 = $this->digest('content-md5', 16);
        if ($md5 !== null && !hash_equals($md5, md5($body))) {
            throw new S3Error('BadDigest', 'The request body MD5 does not match.', 400);
        }
        return $body;
    }

    private function deleteObjects(): void
    {
        [$checksum, $expected] = $this->requestChecksum();
        if ($checksum === null && !isset($this->headers['content-md5'])) {
            throw new S3Error('MissingContentMD5', 'Supply Content-MD5 or an S3 checksum for DeleteObjects.', 400);
        }
        $body = $this->checkSmallBody(8 * 1024 * 1024);
        if ($checksum !== null && !hash_equals($expected, hash(self::CHECKSUMS[$checksum], $body))) {
            throw new S3Error('BadDigest', 'The delete request checksum does not match.', 400);
        }
        $invalid = new S3Error('MalformedXML', 'Expected a Delete document with 1 to 1000 Object entries and an optional Quiet flag.', 400);
        if ($body === '' || str_contains($body, "\0") || stripos($body, '<!DOCTYPE') !== false) {
            throw $invalid;
        }
        $reader = XMLReader::XML($body, 'UTF-8', LIBXML_NONET);
        unset($body);
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $keys = [];
        $quiet = null;
        $namespace = '';
        $parent = '';
        $key = null;
        $text = '';
        try {
            while ($reader->read()) {
                $name = $reader->localName;
                $depth = $reader->depth;
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    if ($depth === 0) {
                        $namespace = $reader->namespaceURI;
                        if ($name !== 'Delete' || !in_array($namespace, ['', self::XMLNS], true)) {
                            throw $invalid;
                        }
                    } elseif ($reader->namespaceURI !== $namespace) {
                        throw $invalid;
                    } elseif ($depth === 1 && $name === 'Object' && count($keys) < 1000) {
                        $parent = $name;
                        $key = null;
                    } elseif ($depth === 1 && $name === 'Quiet' && $quiet === null) {
                        $parent = $name;
                        $text = '';
                    } elseif ($depth === 2 && $parent === 'Object' && $name === 'Key' && $key === null) {
                        $text = '';
                    } else {
                        throw $invalid;
                    }
                    if ($reader->moveToFirstAttribute()) {
                        do {
                            if ($reader->namespaceURI !== 'http://www.w3.org/2000/xmlns/') {
                                throw $invalid;
                            }
                        } while ($reader->moveToNextAttribute());
                        $reader->moveToElement();
                    }
                    if (!$reader->isEmptyElement) {
                        continue;
                    }
                } elseif (in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::WHITESPACE, XMLReader::SIGNIFICANT_WHITESPACE], true)) {
                    if (($depth === 3 && $parent === 'Object') || ($depth === 2 && $parent === 'Quiet')) {
                        $text .= $reader->value;
                    } elseif (trim($reader->value) !== '') {
                        throw $invalid;
                    }
                    continue;
                } elseif ($reader->nodeType !== XMLReader::END_ELEMENT) {
                    if ($reader->nodeType === XMLReader::COMMENT || $reader->nodeType === XMLReader::PI) {
                        continue;
                    }
                    throw $invalid;
                }
                if ($name === 'Key') {
                    $key = $text;
                } elseif ($name === 'Object') {
                    if ($key === null) {
                        throw $invalid;
                    }
                    $keys[] = $key;
                    $parent = '';
                } elseif ($name === 'Quiet') {
                    $quiet = match (trim($text)) {
                        'true', '1' => true, 'false', '0' => false, default => throw $invalid,
                    };
                    $parent = '';
                }
            }
            if (!$keys || libxml_get_last_error() !== false) {
                throw $invalid;
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $this->store->listObjects($this->bucket, '', '', 0, null);
        $result = '';
        foreach ($keys as $key) {
            try {
                $this->store->delete($this->bucket, $key);
                if (!$quiet) {
                    $result .= '<Deleted>' . self::element('Key', $key) . '</Deleted>';
                }
            } catch (S3Error $error) {
                $result .= '<Error>' . self::element('Key', $key) . self::element('Code', $error->s3code)
                    . self::element('Message', $error->getMessage()) . '</Error>';
            }
        }
        self::xml('DeleteResult', $result);
    }

    private function createBucket(array $config): void
    {
        $body = $this->checkSmallBody(16384);
        if ($body !== '') {
            if (str_contains($body, "\0") || stripos($body, '<!DOCTYPE') !== false) {
                throw new S3Error('MalformedXML', 'Expected UTF-8 XML without a document type declaration.', 400);
            }
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if ($xml === false || $xml->getName() !== 'CreateBucketConfiguration'
                || count($xml->xpath('./*')) !== 1 || !isset($xml->LocationConstraint) || $xml->xpath('./*/*')) {
                throw new S3Error('MalformedXML', 'Invalid CreateBucketConfiguration.', 400);
            }
            if ((string) $xml->LocationConstraint !== ($config['region'] ?? 'us-east-1')) {
                throw new S3Error('InvalidLocationConstraint', 'The location must match the configured region.', 400);
            }
        }
        $this->store->createBucket($this->bucket);
        header('Location: /' . $this->bucket, true, 200);
    }

    private static function element(string $tag, string $value): string
    {
        if (preg_match('/[^\x{9}\x{a}\x{d}\x{20}-\x{d7ff}\x{e000}-\x{fffd}\x{10000}-\x{10ffff}]/u', $value) !== 0) {
            throw new S3Error('InvalidArgument', 'The list contains a value that requires encoding-type=url.', 400);
        }
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return '<' . $tag . '>' . str_replace("\r", '&#13;', $escaped) . '</' . $tag . '>';
    }

    private static function xml(string $root, string $contents): void
    {
        header('Content-Type: application/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?><', $root, ' xmlns="', self::XMLNS, '">', $contents, '</', $root, '>';
    }

    private static function isoTime(string $date): string
    {
        $utc = new DateTimeZone('UTC');
        return (new DateTimeImmutable($date, $utc))->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function owner(): string
    {
        return '<Owner><ID>' . hash('sha256', 'tinys3') . '</ID><DisplayName>tinys3</DisplayName></Owner>';
    }

    private function listObjects(bool $v2): void
    {
        $q = $this->query;
        if (($v2 && $q['list-type'] !== '2') || (isset($q['encoding-type']) && $q['encoding-type'] !== 'url')
            || (isset($q['fetch-owner']) && !in_array($q['fetch-owner'], ['true', 'false'], true))) {
            throw new S3Error('InvalidArgument', 'Invalid list option.', 400);
        }
        $max = $q['max-keys'] ?? '1000';
        if (!ctype_digit($max)) {
            throw new S3Error('InvalidArgument', 'max-keys must be a nonnegative integer.', 400);
        }
        $limit = min(1000, (int) $max);
        $prefix = $q['prefix'] ?? '';
        $delimiter = $q['delimiter'] ?? '';
        $after = $v2 ? ($q['start-after'] ?? null) : ($q['marker'] ?? null);
        if (isset($q['continuation-token'])) {
            $token = $q['continuation-token'];
            $after = base64_decode(strtr($token, '-_', '+/'), true);
            if ($after === false || $after === '' || self::token($after) !== $token) {
                throw new S3Error('InvalidArgument', 'Invalid continuation token.', 400);
            }
        }
        $page = $this->store->listObjects($this->bucket, $prefix, $delimiter, $limit, $after);
        $encode = static fn(string $s): string => isset($q['encoding-type']) ? rawurlencode($s) : $s;
        $xml = self::element('Name', $this->bucket) . self::element('Prefix', $encode($prefix));
        if ($v2) {
            foreach (['continuation-token' => 'ContinuationToken', 'start-after' => 'StartAfter'] as $name => $tag) {
                if (isset($q[$name])) {
                    $xml .= self::element($tag, $name === 'start-after' ? $encode($q[$name]) : $q[$name]);
                }
            }
            $xml .= self::element('KeyCount', (string) (count($page['objects']) + count($page['prefixes'])));
        } else {
            $xml .= self::element('Marker', $encode($after ?? ''));
        }
        if ($delimiter !== '') {
            $xml .= self::element('Delimiter', $encode($delimiter));
        }
        if (isset($q['encoding-type'])) {
            $xml .= '<EncodingType>url</EncodingType>';
        }
        $next = $page['next'];
        $xml .= self::element('MaxKeys', (string) $limit) . self::element('IsTruncated', $next === null ? 'false' : 'true');
        if ($next !== null && ($v2 || $delimiter !== '')) {
            $xml .= $v2 ? self::element('NextContinuationToken', self::token($next)) : self::element('NextMarker', $encode($next));
        }
        foreach ($page['objects'] as $row) {
            $xml .= '<Contents>' . self::element('Key', $encode($row['key']))
                . self::element('LastModified', self::isoTime($row['updated_at']))
                . self::element('ETag', '"' . $row['etag'] . '"') . self::element('Size', (string) $row['size'])
                . '<StorageClass>STANDARD</StorageClass>' . (!$v2 || ($q['fetch-owner'] ?? '') === 'true' ? self::owner() : '') . '</Contents>';
        }
        foreach ($page['prefixes'] as $common) {
            $xml .= '<CommonPrefixes>' . self::element('Prefix', $encode($common)) . '</CommonPrefixes>';
        }
        self::xml('ListBucketResult', $xml);
    }

    private static function token(string $key): string
    {
        return rtrim(strtr(base64_encode($key), '+/', '-_'), '=');
    }

    private function digest(string $name, int $bytes): ?string
    {
        if (!isset($this->headers[$name])) {
            return null;
        }
        $value = $this->headers[$name];
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) !== $bytes || base64_encode($raw) !== $value) {
            throw new S3Error('InvalidDigest', 'Invalid digest in ' . $name . '.', 400);
        }
        return bin2hex($raw);
    }

    private function metadata(): array
    {
        $type = $this->headers['content-type'] ?? 'application/octet-stream';
        $metadata = [];
        foreach ($this->headers as $name => $value) {
            if (str_starts_with($name, 'x-amz-meta-')) {
                $key = substr($name, 11);
                if ($key === self::STORED_CHECKSUM) {
                    throw new S3Error('InvalidArgument', 'Invalid metadata field name.', 400);
                }
                $metadata[$key] = $value;
            }
        }
        return [$type, $metadata];
    }

    private function requestChecksum(): array
    {
        $checksum = null;
        $expected = null;
        foreach (self::CHECKSUMS as $name => $algorithm) {
            $header = 'x-amz-checksum-' . $name;
            if (!isset($this->headers[$header])) {
                continue;
            }
            if ($checksum !== null) {
                throw new S3Error('InvalidRequest', 'Supply one S3 checksum algorithm.', 400);
            }
            $checksum = $name;
            $expected = $this->digest($header, strlen(hash($algorithm, '', true)));
        }
        if (isset($this->headers['x-amz-sdk-checksum-algorithm'])
            && ($checksum === null || strtoupper($checksum) !== $this->headers['x-amz-sdk-checksum-algorithm'])) {
            throw new S3Error('InvalidRequest', 'Supply the checksum declared by x-amz-sdk-checksum-algorithm.', 400);
        }
        return [$checksum, $expected];
    }

    private function putObject(): void
    {
        [$type, $metadata] = $this->metadata();
        $sha256 = $this->payloadHash;
        $md5 = $this->digest('content-md5', 16);
        [$checksum, $expected] = $this->requestChecksum();
        if ($checksum !== null) {
            $metadata[self::STORED_CHECKSUM] = $checksum . ':' . $this->headers['x-amz-checksum-' . $checksum];
        }
        if ($checksum === 'sha256') {
            if ($sha256 !== null && !hash_equals($sha256, $expected)) {
                throw new S3Error('BadDigest', 'The supplied SHA-256 digests disagree.', 400);
            }
            $sha256 = $expected;
        }
        $length = $this->headers['content-length'] ?? null;
        if ($length !== null && !ctype_digit($length)) {
            throw new S3Error('InvalidArgument', 'Invalid Content-Length.', 400);
        }
        $input = fopen('php://input', 'rb');
        try {
            $row = $this->store->put($this->bucket, $this->key, $input, $type, $metadata, $sha256, $md5,
                expectedSize: $length === null ? null : (int) $length);
        } finally {
            fclose($input);
        }
        header('ETag: "' . $row['etag'] . '"');
        if ($checksum !== null) {
            header('x-amz-checksum-' . $checksum . ': ' . $this->headers['x-amz-checksum-' . $checksum]);
        }
    }

    private function copyObject(): void
    {
        $source = $this->headers['x-amz-copy-source'];
        if (str_contains($source, '?') || preg_match('/%(?![0-9a-f]{2})/i', $source)) {
            throw new S3Error('InvalidArgument', 'Invalid copy source or unsupported source version.', 400);
        }
        $path = rawurldecode($source);
        [$bucket, $key] = array_pad(explode('/', str_starts_with($path, '/') ? substr($path, 1) : $path, 2), 2, '');
        if ($key === '') {
            throw new S3Error('InvalidArgument', 'The copy source needs an object key.', 400);
        }
        $directive = $this->headers['x-amz-metadata-directive'] ?? 'COPY';
        if (!in_array($directive, ['COPY', 'REPLACE'], true) || ($directive === 'COPY' && $bucket === $this->bucket && $key === $this->key)) {
            throw new S3Error('InvalidRequest', 'Invalid metadata directive or unchanged self-copy.', 400);
        }
        $this->access->authorize('GetObject', $bucket);
        [$sourceRow, $stream] = $this->store->open($bucket, $key);
        try {
            [$type, $metadata] = $directive === 'REPLACE' ? $this->metadata() : [$sourceRow['type'], $sourceRow['metadata']];
            if (isset($sourceRow['metadata'][self::STORED_CHECKSUM])) {
                $stored = $sourceRow['metadata'][self::STORED_CHECKSUM];
                if (str_contains($stored, '-')) {
                    // A checksum over the individual parts changes when copied as a single object.
                    $crc = hash_init('crc32b');
                    if (hash_update_stream($crc, $stream) !== $sourceRow['size'] || !rewind($stream)) {
                        throw new RuntimeException('Cannot checksum the copy source.');
                    }
                    $stored = 'crc32:' . base64_encode(hash_final($crc, true));
                }
                $metadata[self::STORED_CHECKSUM] = $stored;
            }
            $md5 = str_contains($sourceRow['etag'], '-') ? null : $sourceRow['etag'];
            $row = $this->store->put($this->bucket, $this->key, $stream, $type, $metadata, null, $md5);
        } finally {
            fclose($stream);
        }
        $xml = self::element('LastModified', self::isoTime($row['updated_at'])) . self::element('ETag', '"' . $row['etag'] . '"');
        if (isset($metadata[self::STORED_CHECKSUM])) {
            [$name, $value] = explode(':', $metadata[self::STORED_CHECKSUM], 2);
            $xml .= self::element('Checksum' . strtoupper($name), $value);
        }
        self::xml('CopyObjectResult', $xml);
    }

    private function download(bool $head): void
    {
        [$row, $stream] = $this->store->open($this->bucket, $this->key);
        try {
            $etag = '"' . $row['etag'] . '"';
            $modified = (new DateTimeImmutable($row['updated_at'], new DateTimeZone('UTC')))->getTimestamp();
            $status = $this->precondition($etag, $modified);
            if ($status === 412) {
                throw new S3Error('PreconditionFailed', 'An object precondition failed.', 412);
            }
            header('ETag: ' . $etag);
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', $modified));
            if ($status === 304) {
                http_response_code(304);
                return;
            }
            $size = (int) $row['size'];
            $length = $size;
            $range = $this->headers['range'] ?? null;
            $ifRange = $this->headers['if-range'] ?? null;
            if ($ifRange !== null && $ifRange !== $etag && (self::httpDate($ifRange) ?? -1) < $modified) {
                $range = null;
            }
            if ($range !== null) {
                [$start, $end] = self::range($range, $size);
                $length = $end - $start + 1;
                if (!$head && $start > 0 && fseek($stream, $start) !== 0) {
                    throw new RuntimeException('Cannot seek in the object stream.');
                }
                header("Content-Range: bytes $start-$end/$size", true, 206);
            }
            header('Accept-Ranges: bytes');
            header('Content-Type: ' . $row['type']);
            header('Content-Length: ' . $length);
            foreach ($row['metadata'] as $key => $value) {
                if ($key === self::STORED_CHECKSUM) {
                    if ($range === null && isset($this->headers['x-amz-checksum-mode'])) {
                        [$name, $digest] = explode(':', $value, 2);
                        header('x-amz-checksum-' . $name . ': ' . $digest);
                        header('x-amz-checksum-type: ' . (str_contains($digest, '-') ? 'COMPOSITE' : 'FULL_OBJECT'));
                    }
                } else {
                    header('x-amz-meta-' . $key . ': ' . $value);
                }
            }
            if (!$head && $length > 0) {
                sendObjectBytes($stream, $length);
            }
        } finally {
            fclose($stream);
        }
    }

    private static function httpDate(string $value): ?int
    {
        $time = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new DateTimeZone('UTC'));
        return $time === false ? null : $time->getTimestamp();
    }

    private function precondition(string $etag, int $modified): int
    {
        $h = $this->headers;
        if (isset($h['if-match'])) {
            $tags = array_map('trim', explode(',', $h['if-match']));
            if (!in_array('*', $tags, true) && !in_array($etag, $tags, true)) {
                return 412;
            }
        } elseif (isset($h['if-unmodified-since']) && (self::httpDate($h['if-unmodified-since']) ?? PHP_INT_MAX) < $modified) {
            return 412;
        }
        if (isset($h['if-none-match'])) {
            $tags = array_map('trim', explode(',', $h['if-none-match']));
            if (in_array('*', $tags, true) || in_array($etag, $tags, true) || in_array('W/' . $etag, $tags, true)) {
                return 304;
            }
        } elseif (isset($h['if-modified-since']) && (self::httpDate($h['if-modified-since']) ?? -1) >= $modified) {
            return 304;
        }
        return 200;
    }

    private static function range(string $range, int $size): array
    {
        if ($size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/Di', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
            $start = $m[1] === '' ? max(0, $size - (int) $m[2]) : (int) $m[1];
            $end = $m[1] === '' || $m[2] === '' ? $size - 1 : min($size - 1, (int) $m[2]);
            if ($start <= $end) {
                return [$start, $end];
            }
        }
        header('Content-Range: bytes */' . $size);
        throw new S3Error('InvalidRange', 'The requested range is invalid for this object.', 416);
    }
}
