<?php

declare(strict_types=1);

final class Credentials
{
    public function __construct(private readonly PDO $db, private readonly string $adminAccessKey)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function all(): array
    {
        return $this->query('SELECT access_key, label, created_at FROM credentials ORDER BY created_at DESC, access_key')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(string $id): array
    {
        $statement = $this->query('SELECT c.access_key, c.label, g.bucket, g.permission FROM credentials c
            LEFT JOIN credential_grants g ON g.access_key = c.access_key WHERE c.access_key = ? ORDER BY g.bucket', [$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new DomainException('Credential not found.');
        }
        $credential = ['access_key' => $row['access_key'], 'label' => $row['label'], 'grants' => []];
        do {
            if ($row['bucket'] !== null) {
                $credential['grants'][$row['bucket']] = $row['permission'];
            }
        } while ($row = $statement->fetch(PDO::FETCH_ASSOC));
        return $credential;
    }

    public function create(string $label, array $grants): array
    {
        self::validate($label, $grants);
        do {
            $id = bin2hex(random_bytes(12));
        } while ($id === $this->adminAccessKey);
        $secret = bin2hex(random_bytes(32));
        $this->transaction(function () use ($id, $secret, $label, $grants) {
            // HMAC-SHA256 hashes keys longer than 64 bytes before the first SigV4 step.
            $this->query('INSERT INTO credentials (access_key, signing_key, label, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6))',
                [$id, hash('sha256', 'AWS4' . $secret, true), $label]);
            $this->insertGrants($id, $grants);
        });
        return ['access_key' => $id, 'secret_key' => $secret];
    }

    public function update(string $id, string $label, array $grants): void
    {
        self::validate($label, $grants);
        $this->transaction(function () use ($id, $label, $grants) {
            if ($this->query('SELECT access_key FROM credentials WHERE access_key = ? FOR UPDATE', [$id])->fetchColumn() === false) {
                throw new DomainException('Credential not found.');
            }
            $this->query('UPDATE credentials SET label = ? WHERE access_key = ?', [$label, $id]);
            $this->query('DELETE FROM credential_grants WHERE access_key = ?', [$id]);
            $this->insertGrants($id, $grants);
        });
    }

    public function revoke(string $id): void
    {
        if ($this->query('DELETE FROM credentials WHERE access_key = ?', [$id])->rowCount() === 0) {
            throw new DomainException('Credential not found.');
        }
    }

    public function signingKey(string $id): ?string
    {
        $key = $this->query('SELECT signing_key FROM credentials WHERE access_key = ?', [$id])->fetchColumn();
        return $key === false ? null : $key;
    }

    public function allowed(string $id, string $bucket, bool $write): bool
    {
        $permission = $this->query('SELECT permission FROM credential_grants WHERE access_key = ? AND bucket = ?',
            [$id, $bucket])->fetchColumn();
        return $permission === 'write' || (!$write && $permission === 'read');
    }

    public function buckets(string $id): array
    {
        return $this->query('SELECT b.name, b.created_at FROM credential_grants g JOIN buckets b ON b.name = g.bucket
            WHERE g.access_key = ? ORDER BY g.bucket', [$id])->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function validate(string $label, array $grants): void
    {
        if (trim($label) === '' || !preg_match('/\A.{1,255}\z/us', $label)) {
            throw new DomainException('The label must contain 1 to 255 UTF-8 characters.');
        }
        foreach ($grants as $permission) {
            if ($permission !== 'read' && $permission !== 'write') {
                throw new DomainException('Each bucket grant must specify read or write access.');
            }
        }
    }

    private function insertGrants(string $id, array $grants): void
    {
        $insert = $this->db->prepare('INSERT INTO credential_grants (access_key, bucket, permission)
            SELECT ?, name, ? FROM buckets WHERE name = ?');
        foreach ($grants as $bucket => $permission) {
            $insert->execute([$id, $permission, (string) $bucket]);
            if ($insert->rowCount() !== 1) {
                throw new DomainException('Bucket not found.');
            }
        }
    }

    private function query(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    private function transaction(Closure $operation): void
    {
        $this->db->beginTransaction();
        try {
            $operation();
            $this->db->commit();
        } finally {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
        }
    }
}
