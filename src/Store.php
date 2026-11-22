<?php

declare(strict_types=1);

final class Store
{
    private const string COLUMNS = 'bucket, object_key AS `key`, size, etag, content_type AS type, metadata, updated_at';
    private readonly string $root;

    public function __construct(private readonly PDO $db, string $root)
    {
        if ($root === '' || str_contains($root, "\0")) {
            throw new S3Error('InternalError', 'Invalid storage directory.', 500);
        }
        self::directory($root);
        $path = realpath($root);
        if ($path === false) {
            throw new S3Error('InternalError', 'Cannot resolve the storage directory.', 500);
        }
        $this->root = $path;
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function buckets(): array
    {
        return $this->query('SELECT name, created_at FROM buckets ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createBucket(string $bucket): void
    {
        self::validateBucket($bucket);
        try {
            $this->db->prepare('INSERT INTO buckets (name, created_at) VALUES (?, UTC_TIMESTAMP(6))')->execute([$bucket]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new S3Error('BucketAlreadyOwnedByYou', 'The bucket already exists.', 409);
            }
            throw self::databaseError($e);
        }
    }

    public function deleteBucket(string $bucket): void
    {
        self::validateBucket($bucket);
        $this->transaction(function () use ($bucket) {
            $row = $this->bucketRow($bucket, ' FOR UPDATE');
            if ((int) $row['object_count'] !== 0) {
                throw new S3Error('BucketNotEmpty', 'The bucket contains objects.', 409);
            }
            try {
                $this->db->prepare('DELETE FROM buckets WHERE name = ?')->execute([$bucket]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1451) {
                    throw new S3Error('BucketNotEmpty', 'The bucket contains objects or multipart uploads.', 409);
                }
                throw $e;
            }
        });
    }

    public function listObjects(string $bucket, string $prefix, string $delimiter, int $limit, ?string $after): array
    {
        self::validateBucket($bucket);
        if ($limit < 0) {
            throw new S3Error('InvalidArgument', 'The listing limit must be nonnegative.', 400);
        }
        $this->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return $this->transaction(function () use ($bucket, $prefix, $delimiter, $limit, $after) {
            $this->bucketRow($bucket);
            $result = ['objects' => [], 'prefixes' => [], 'next' => null];
            if ($limit === 0) {
                return $result;
            }
            $upper = self::pastPrefix($prefix);
            $lower = $prefix;
            $operator = '>=';
            if ($after !== null && strcmp($after, $prefix) >= 0) {
                $lower = $after;
                $operator = '>';
                $group = self::commonPrefix($after, $prefix, $delimiter);
                if ($group !== null) {
                    $lower = self::pastPrefix($group);
                    $operator = '>=';
                }
            }
            $last = null;
            $count = 0;
            while ($lower !== null && ($upper === null || strcmp($lower, $upper) < 0)) {
                $sql = 'SELECT ' . self::COLUMNS . " FROM objects WHERE bucket = ? AND object_key $operator UNHEX(?)";
                $params = [$bucket, bin2hex($lower)];
                if ($upper !== null) {
                    $sql .= ' AND object_key < UNHEX(?)';
                    $params[] = bin2hex($upper);
                }
                $batch = min(128, $limit - $count) + 1;
                $rows = $this->query($sql . " ORDER BY object_key LIMIT $batch", $params)->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $group = self::commonPrefix($row['key'], $prefix, $delimiter);
                    $entry = $group ?? $row['key'];
                    if ($entry === $last) {
                        continue;
                    }
                    if ($count === $limit) {
                        $result['next'] = $last;
                        return $result;
                    }
                    if ($group === null) {
                        $result['objects'][] = self::objectRow($row);
                    } else {
                        $result['prefixes'][] = $group;
                    }
                    $last = $entry;
                    ++$count;
                }
                if (count($rows) < $batch) {
                    break;
                }
                // A whole directory can occupy millions of adjacent index entries.
                $lower = $group === null ? $last : self::pastPrefix($group);
                $operator = $group === null ? '>' : '>=';
            }
            return $result;
        });
    }

    public function put(
        string $bucket, string $key, $input, string $type, array $metadata = [],
        ?string $sha256 = null, ?string $md5 = null, ?string $multipartETag = null,
        ?int $expectedSize = null,
    ): array
    {
        self::validateBucket($bucket);
        self::validateKey($key);
        if (!($input instanceof Generator) && (!is_resource($input) || get_resource_type($input) !== 'stream')) {
            throw new S3Error('InvalidArgument', 'A readable input stream or generator of streams is required.', 400);
        }
        if ($multipartETag !== null && !preg_match('/\A[a-f0-9]{32}-(?:[1-9][0-9]{0,3}|10000)\z/', $multipartETag)) {
            throw new S3Error('InvalidArgument', 'Invalid multipart ETag.', 400);
        }
        if (!preg_match('/\A[\x20-\x7e]{1,255}\z/', $type)) {
            throw new S3Error('InvalidArgument', 'Invalid content type.', 400);
        }
        $json = self::encodeMetadata($metadata);
        if (($sha256 !== null && !preg_match('/\A[0-9a-fA-F]{64}\z/', $sha256))
            || ($md5 !== null && !preg_match('/\A[0-9a-fA-F]{32}\z/', $md5))) {
            throw new S3Error('InvalidDigest', 'Invalid hexadecimal checksum.', 400);
        }
        $this->bucketRow($bucket);
        $id = bin2hex(random_bytes(16));
        $path = $this->blobPath($id);
        self::directory(dirname($path));
        $file = @fopen($path, 'xb');
        if ($file === false) {
            throw new S3Error('InternalError', 'Cannot create the object file.', 500);
        }
        $discard = true;
        try {
            $size = 0;
            $md5Hash = $multipartETag === null || $md5 !== null ? hash_init('md5') : null;
            $shaHash = $sha256 === null ? null : hash_init('sha256');
            $checksum = null;
            if ($multipartETag === null && isset($metadata['@s3-checksum'])) {
                [$algorithm, $expectedDigest] = explode(':', $metadata['@s3-checksum'], 2);
                if ($algorithm !== 'sha256' || $shaHash === null) {
                    $checksum = hash_init($algorithm === 'crc32' ? 'crc32b' : $algorithm);
                }
            }
            foreach ($input instanceof Generator ? $input : [$input] as $stream) {
                if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
                    throw new S3Error('InvalidArgument', 'Each part must provide a readable stream.', 400);
                }
                while (!feof($stream)) {
                    $chunk = @fread($stream, 1048576);
                    if ($chunk === false || ($chunk === '' && !feof($stream))) {
                        throw new S3Error('IncompleteBody', 'Cannot read the request body.', 400);
                    }
                    $length = strlen($chunk);
                    if (@fwrite($file, $chunk) !== $length) {
                        throw new S3Error('InternalError', 'Cannot write the object file.', 500);
                    }
                    $size += $length;
                    if ($md5Hash !== null) {
                        hash_update($md5Hash, $chunk);
                    }
                    if ($shaHash !== null) {
                        hash_update($shaHash, $chunk);
                    }
                    if ($checksum !== null) {
                        hash_update($checksum, $chunk);
                    }
                }
            }
            if ($expectedSize !== null && $expectedSize !== $size) {
                throw new S3Error('IncompleteBody', 'The object length differs from Content-Length.', 400);
            }
            if ($checksum !== null && !hash_equals($expectedDigest, base64_encode(hash_final($checksum, true)))) {
                throw new S3Error('BadDigest', 'The object checksum does not match.', 400);
            }
            $fullMD5 = $md5Hash === null ? null : hash_final($md5Hash);
            $etag = $multipartETag ?? $fullMD5;
            if ($md5 !== null && !hash_equals(strtolower($md5), $fullMD5)) {
                throw new S3Error('BadDigest', 'The MD5 checksum does not match the request body.', 400);
            }
            if ($shaHash !== null && !hash_equals(strtolower($sha256), hash_final($shaHash))) {
                throw new S3Error('XAmzContentSHA256Mismatch', 'The SHA-256 checksum does not match the request body.', 400);
            }
            if (!@fsync($file) || !@fclose($file)) {
                throw new S3Error('InternalError', 'Cannot finish writing the object file.', 500);
            }
            [$row, $old] = $this->transaction(function () use ($bucket, $key, $id, $size, $etag, $type, $json, &$discard) {
                $this->bucketRow($bucket, ' FOR UPDATE');
                $old = $this->storedFile($bucket, $key);
                $params = [$id, $size, $etag, $type, $json, $bucket, $key];
                if ($old === false) {
                    $this->query('INSERT INTO objects (blob_id, size, etag, content_type, metadata, bucket, object_key, updated_at)
                        VALUES (UNHEX(?), ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))', $params);
                } else {
                    $this->query('UPDATE objects SET blob_id = UNHEX(?), size = ?, etag = ?, content_type = ?, metadata = ?, updated_at = UTC_TIMESTAMP(6)
                        WHERE bucket = ? AND object_key = ?', $params);
                }
                $this->query('UPDATE buckets SET object_count = object_count + ?, byte_count = byte_count - ? + ? WHERE name = ?',
                    [$old === false ? 1 : 0, $old['size'] ?? 0, $size, $bucket]);
                $row = $this->query('SELECT ' . self::COLUMNS . ' FROM objects WHERE bucket = ? AND object_key = ?', [$bucket, $key])->fetch(PDO::FETCH_ASSOC);
                $row = self::objectRow($row);
                // A lost COMMIT response leaves the publication outcome unknown.
                $discard = false;
                return [$row, $old];
            });
            if ($old !== false) {
                self::removeFile($this->blobPath($old['id']));
            }
            return $row;
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            if ($discard) {
                self::removeFile($path);
            }
        }
    }

    public function open(string $bucket, string $key): array
    {
        self::validateBucket($bucket);
        self::validateKey($key);
        return $this->transaction(function () use ($bucket, $key) {
            $this->bucketRow($bucket, ' LOCK IN SHARE MODE');
            $row = $this->query('SELECT ' . self::COLUMNS . ', LOWER(HEX(blob_id)) AS id FROM objects WHERE bucket = ? AND object_key = ?',
                [$bucket, $key])->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new S3Error('NoSuchKey', 'The object does not exist.', 404);
            }
            $file = @fopen($this->blobPath($row['id']), 'rb');
            if ($file === false) {
                throw new S3Error('InternalError', 'Cannot open the stored object.', 500);
            }
            unset($row['id']);
            return [self::objectRow($row), $file];
        });
    }

    public function delete(string $bucket, string $key): void
    {
        self::validateBucket($bucket);
        self::validateKey($key);
        $old = $this->transaction(function () use ($bucket, $key) {
            $this->bucketRow($bucket, ' FOR UPDATE');
            $old = $this->storedFile($bucket, $key);
            if ($old !== false) {
                $this->query('DELETE FROM objects WHERE bucket = ? AND object_key = ?', [$bucket, $key]);
                $this->query('UPDATE buckets SET object_count = object_count - 1, byte_count = byte_count - ? WHERE name = ?', [$old['size'], $bucket]);
            }
            return $old;
        });
        if ($old !== false) {
            self::removeFile($this->blobPath($old['id']));
        }
    }

    public function stats(): array
    {
        $row = $this->query('SELECT COUNT(*) AS buckets, COALESCE(SUM(object_count), 0) AS objects, COALESCE(SUM(byte_count), 0) AS bytes FROM buckets')->fetch(PDO::FETCH_ASSOC);
        return array_map('intval', $row);
    }

    public function recent(int $limit): array
    {
        if ($limit < 0) {
            throw new S3Error('InvalidArgument', 'The recent object limit must be nonnegative.', 400);
        }
        $rows = $this->query('SELECT ' . self::COLUMNS . " FROM objects ORDER BY updated_at DESC, bucket DESC, object_key DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(self::objectRow(...), $rows);
    }

    private static function validateBucket(string $bucket): void
    {
        if (!preg_match('/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/', $bucket)
            || str_contains($bucket, '..') || preg_match('/\A(?:[0-9]{1,3}\.){3}[0-9]{1,3}\z/', $bucket)
            || preg_match('/\A(?:xn--|sthree-|amzn-s3-demo-)|(?:-s3alias|--ol-s3|\.mrap|--x-s3|--table-s3)\z/', $bucket)) {
            throw new S3Error('InvalidBucketName', 'Invalid bucket name.', 400);
        }
    }

    private static function validateKey(string $key): void
    {
        if ($key === '' || strlen($key) > 1024 || str_contains($key, "\0") || !preg_match('//u', $key)) {
            throw new S3Error('InvalidArgument', 'The object key must contain 1 to 1024 UTF-8 bytes and no NUL.', 400);
        }
    }

    private static function encodeMetadata(array $metadata): string
    {
        $bytes = 0;
        $names = [];
        foreach ($metadata as $name => $value) {
            $name = (string) $name;
            // The API adds this private field after validating the request checksum.
            if ($name === '@s3-checksum' && is_string($value)
                && preg_match('/\A(?:(?:crc32|crc32c):[A-Za-z0-9+\/]{6}==|crc32:[A-Za-z0-9+\/]{5}[AQgw]==-(?:[1-9][0-9]{0,3}|10000)|sha1:[A-Za-z0-9+\/]{27}=|sha256:[A-Za-z0-9+\/]{43}=)\z/', $value)) {
                continue;
            }
            if (!preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $name) || !is_string($value)
                || !preg_match('//u', $value) || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) || isset($names[strtolower($name)])) {
                throw new S3Error('InvalidArgument', 'Invalid metadata name or value.', 400);
            }
            $names[strtolower($name)] = true;
            $bytes += strlen($name) + strlen($value);
        }
        if ($bytes > 2048) {
            throw new S3Error('MetadataTooLarge', 'User metadata exceeds 2048 bytes.', 400);
        }
        return json_encode((object) $metadata, JSON_THROW_ON_ERROR);
    }

    private static function commonPrefix(string $key, string $prefix, string $delimiter): ?string
    {
        if ($delimiter === '' || !str_starts_with($key, $prefix)) {
            return null;
        }
        $at = strpos($key, $delimiter, strlen($prefix));
        return $at === false ? null : substr($key, 0, $at + strlen($delimiter));
    }

    private static function pastPrefix(string $value): ?string
    {
        for ($i = strlen($value) - 1; $i >= 0; --$i) {
            if (ord($value[$i]) < 255) {
                return substr($value, 0, $i) . chr(ord($value[$i]) + 1);
            }
        }
        return null;
    }

    private static function objectRow(array $row): array
    {
        $row['size'] = (int) $row['size'];
        $row['metadata'] = json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR);
        return $row;
    }

    private function bucketRow(string $bucket, string $lock = ''): array
    {
        $row = $this->query('SELECT object_count FROM buckets WHERE name = ?' . $lock, [$bucket])->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new S3Error('NoSuchBucket', 'The bucket does not exist.', 404);
        }
        return $row;
    }

    private function storedFile(string $bucket, string $key): array|false
    {
        return $this->query('SELECT LOWER(HEX(blob_id)) AS id, size FROM objects WHERE bucket = ? AND object_key = ?', [$bucket, $key])->fetch(PDO::FETCH_ASSOC);
    }

    private function blobPath(string $id): string
    {
        return $this->root . '/' . substr($id, 0, 2) . '/' . $id;
    }

    private static function directory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new S3Error('InternalError', 'Cannot create the storage directory.', 500);
        }
    }

    private static function removeFile(string $path): void
    {
        if (!@unlink($path)) {
            error_log('Cannot remove stored object file ' . $path);
        }
    }

    private function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $statement = $this->db->prepare($sql);
            $statement->execute($params);
            return $statement;
        } catch (PDOException $e) {
            throw self::databaseError($e);
        }
    }

    private function transaction(Closure $operation): mixed
    {
        try {
            $this->db->beginTransaction();
            try {
                $result = $operation();
                $this->db->commit();
                return $result;
            } finally {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            }
        } catch (PDOException $e) {
            throw self::databaseError($e);
        }
    }

    private static function databaseError(PDOException $e): S3Error
    {
        error_log($e->getMessage());
        return new S3Error('InternalError', 'The storage database operation failed.', 500);
    }
}
