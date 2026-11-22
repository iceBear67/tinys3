CREATE TABLE IF NOT EXISTS buckets (
    name VARBINARY(63) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    object_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    byte_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (name)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS objects (
    bucket VARBINARY(63) NOT NULL,
    object_key VARBINARY(1024) NOT NULL,
    blob_id BINARY(16) NOT NULL,
    size BIGINT UNSIGNED NOT NULL,
    etag VARCHAR(38) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    content_type VARCHAR(255) NOT NULL,
    metadata JSON NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (bucket, object_key),
    KEY objects_recent (updated_at, bucket, object_key),
    CONSTRAINT objects_bucket_fk FOREIGN KEY (bucket) REFERENCES buckets (name)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS multipart_uploads (
    upload_id CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    bucket VARBINARY(63) NOT NULL,
    object_key VARBINARY(1024) NOT NULL,
    content_type VARCHAR(255) NOT NULL,
    metadata JSON NOT NULL,
    crc32_type ENUM('COMPOSITE', 'FULL_OBJECT') NULL,
    publishing BOOLEAN NOT NULL DEFAULT FALSE,
    initiated DATETIME(6) NOT NULL,
    PRIMARY KEY (upload_id),
    KEY multipart_listing (bucket, object_key, upload_id),
    CONSTRAINT multipart_bucket_fk FOREIGN KEY (bucket) REFERENCES buckets (name)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS multipart_parts (
    upload_id CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    part_number SMALLINT UNSIGNED NOT NULL,
    file_id BINARY(16) NOT NULL,
    size BIGINT UNSIGNED NOT NULL,
    md5 BINARY(16) NOT NULL,
    crc32 BINARY(4) NOT NULL,
    modified DATETIME(6) NOT NULL,
    PRIMARY KEY (upload_id, part_number),
    CONSTRAINT multipart_parts_fk FOREIGN KEY (upload_id) REFERENCES multipart_uploads (upload_id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS credentials (
    access_key VARBINARY(24) NOT NULL,
    signing_key BINARY(32) NOT NULL,
    label VARCHAR(255) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (access_key)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS credential_grants (
    access_key VARBINARY(24) NOT NULL,
    bucket VARBINARY(63) NOT NULL,
    permission ENUM('read', 'write') NOT NULL,
    PRIMARY KEY (access_key, bucket),
    KEY credential_grants_bucket (bucket),
    CONSTRAINT credential_grants_key_fk FOREIGN KEY (access_key) REFERENCES credentials (access_key) ON DELETE CASCADE,
    CONSTRAINT credential_grants_bucket_fk FOREIGN KEY (bucket) REFERENCES buckets (name) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
