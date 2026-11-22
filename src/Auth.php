<?php
declare(strict_types=1);

final readonly class S3Request
{
    public function __construct(
        public array $headers,
        public array $query,
        public string $bucket,
        public string $key,
        public ?string $payloadHash,
        public BucketAccess $access,
    ) {}
}

final readonly class BucketAccess
{
    public function __construct(private Credentials $credentials, private ?string $accessKey) {}

    public function buckets(Store $store): array
    {
        return $this->accessKey === null ? $store->buckets() : $this->credentials->buckets($this->accessKey);
    }

    public function authorize(string $operation, string $bucket): void
    {
        if ($this->accessKey === null || $operation === 'ListBuckets') {
            return;
        }
        $write = match ($operation) {
            'ListObjects', 'ListObjectsV2', 'HeadBucket', 'GetBucketLocation', 'GetObject', 'HeadObject',
            'ListMultipartUploads', 'ListParts' => false,
            'PutObject', 'DeleteObject', 'DeleteObjects', 'CopyObject', 'CreateMultipartUpload',
            'UploadPart', 'CompleteMultipartUpload', 'AbortMultipartUpload' => true,
            default => throw new S3Error('AccessDenied', 'This operation requires administrator access.', 403),
        };
        if (!$this->credentials->allowed($this->accessKey, $bucket, $write)) {
            throw new S3Error('AccessDenied', 'This credential does not have the required bucket permission.', 403);
        }
    }
}

function verifyS3(array $config, Credentials $credentials): S3Request
{
    $headers = [];
    foreach (getallheaders() as $name => $value) {
        $headers[strtolower($name)] = trim($value);
    }
    $authorization = $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    [$path, $rawQuery] = array_pad(explode('?', $_SERVER['REQUEST_URI'], 2), 2, '');
    if (!str_starts_with($path, '/') || preg_match('/%(?![0-9a-f]{2})/i', $_SERVER['REQUEST_URI'])) {
        throw new S3Error('InvalidURI', 'Invalid request URI.', 400);
    }
    $query = $pairs = [];
    foreach ($rawQuery === '' ? [] : explode('&', $rawQuery) as $part) {
        [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
        $name = rawurldecode($name);
        $value = rawurldecode($value);
        if (isset($query[$name])) {
            throw new S3Error('InvalidRequest', 'Repeated query parameter.', 400);
        }
        $query[$name] = $value;
        if ($name !== 'X-Amz-Signature') {
            $pairs[rawurlencode($name)] = rawurlencode($name) . '=' . rawurlencode($value);
        }
    }
    ksort($pairs, SORT_STRING);
    $presigned = isset($query['X-Amz-Algorithm']);
    if ($presigned) {
        if ($authorization !== '' || $query['X-Amz-Algorithm'] !== 'AWS4-HMAC-SHA256') {
            throw new S3Error('InvalidRequest', 'Use a single AWS4-HMAC-SHA256 signature.', 400);
        }
        $credential = $query['X-Amz-Credential'] ?? '';
        $signed = $query['X-Amz-SignedHeaders'] ?? '';
        $signature = $query['X-Amz-Signature'] ?? '';
        $date = $query['X-Amz-Date'] ?? '';
        $expires = $query['X-Amz-Expires'] ?? '';
        if (!preg_match('/^[0-9]{1,6}$/D', $expires) || (int)$expires < 1 || (int)$expires > 604800) {
            throw new S3Error('InvalidArgument', 'Expiry must be between 1 and 604800 seconds.', 400);
        }
    } else {
        if (isset($query['X-Amz-Signature']) || !preg_match('/^AWS4-HMAC-SHA256 Credential=([^, ]+),\s*SignedHeaders=([^, ]+),\s*Signature=([0-9a-f]{64})$/D', $authorization, $matches)) {
            throw new S3Error('AccessDenied', 'A Signature Version 4 credential is required.', 403);
        }
        [, $credential, $signed, $signature] = $matches;
        $date = $headers['x-amz-date'] ?? '';
    }
    $scope = explode('/', $credential);
    if (count($scope) !== 5 || $scope[1] !== substr($date, 0, 8) || $scope[2] !== $config['region'] || $scope[3] !== 's3' || $scope[4] !== 'aws4_request') {
        throw new S3Error('AuthorizationHeaderMalformed', 'Invalid credential scope for this region.', 400);
    }
    $isAdmin = hash_equals($config['access_key'], $scope[0]);
    $key = $isAdmin ? 'AWS4' . $config['secret_key'] : $credentials->signingKey($scope[0]);
    if ($key === null) {
        throw new S3Error('InvalidAccessKeyId', 'Unknown access key.', 403);
    }
    $instant = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $date, new DateTimeZone('UTC'));
    if (!$instant || $instant->format('Ymd\THis\Z') !== $date) {
        throw new S3Error('AccessDenied', 'Invalid request date.', 403);
    }
    $age = time() - $instant->getTimestamp();
    if ($age < -900 || $age > ($presigned ? (int)$expires : 900)) {
        throw new S3Error($presigned ? 'AccessDenied' : 'RequestTimeTooSkewed', 'Request outside its validity period.', 403);
    }

    $names = explode(';', $signed);
    $sorted = array_unique($names);
    sort($sorted, SORT_STRING);
    if ($names !== $sorted || !in_array('host', $names, true)) {
        throw new S3Error('AccessDenied', 'Invalid signed header list.', 403);
    }
    foreach ($headers as $name => $_) {
        if (str_starts_with($name, 'x-amz-') && !in_array($name, ['x-amz-content-sha256', 'x-amz-user-agent'], true) && !in_array($name, $names, true)) {
            throw new S3Error('AccessDenied', 'Missing signed Amazon header.', 403);
        }
    }
    $headerLines = '';
    foreach ($names as $name) {
        if (!preg_match('/^[a-z0-9-]+$/D', $name) || !isset($headers[$name])) {
            throw new S3Error('SignatureDoesNotMatch', 'Missing signed header.', 403);
        }
        $headerLines .= $name . ':' . preg_replace('/[\t ]+/', ' ', $headers[$name]) . "\n";
    }
    $payload = $headers['x-amz-content-sha256'] ?? ($presigned ? 'UNSIGNED-PAYLOAD' : '');
    if ($payload !== 'UNSIGNED-PAYLOAD' && !preg_match('/^[0-9a-f]{64}$/D', $payload)) {
        throw new S3Error('InvalidRequest', 'Use a SHA-256 digest or UNSIGNED-PAYLOAD.', 400);
    }
    $request = implode("\n", [$_SERVER['REQUEST_METHOD'], $path, implode('&', $pairs), $headerLines, $signed, $payload]);
    $scopeText = implode('/', array_slice($scope, 1));
    $toSign = "AWS4-HMAC-SHA256\n$date\n$scopeText\n" . hash('sha256', $request);
    foreach (array_slice($scope, 1) as $component) {
        $key = hash_hmac('sha256', $component, $key, true);
    }
    if (!hash_equals(hash_hmac('sha256', $toSign, $key), $signature)) {
        throw new S3Error('SignatureDoesNotMatch', 'Request signature does not match.', 403);
    }
    [$bucket, $objectKey] = array_map('rawurldecode', array_pad(explode('/', substr($path, 1), 2), 2, ''));
    if ($path !== '/' && $bucket === '') {
        throw new S3Error('InvalidBucketName', 'The request path needs a bucket name.', 400);
    }
    if (strlen($objectKey) > 1024 || !preg_match('//u', $objectKey) || str_contains($objectKey, "\0")) {
        throw new S3Error('InvalidArgument', 'Invalid object key.', 400);
    }
    $access = new BucketAccess($credentials, $isAdmin ? null : $scope[0]);
    return new S3Request($headers, $query, $bucket, $objectKey, $payload === 'UNSIGNED-PAYLOAD' ? null : $payload, $access);
}
