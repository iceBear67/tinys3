<?php

declare(strict_types=1);

function serveDashboard(Store $store, array $config, Credentials $credentials): void
{
    (new DashboardPage($store, $config, $credentials))->serve();
}

final class DashboardPage
{
    private const PAGE_SIZE = 50;
    private string $bucket = '';
    private string $prefix = '';
    private string $key = '';
    private string $view = 'overview';
    private ?array $notice = null;
    private bool $readError = false;

    public function __construct(private Store $store, private array $config, private Credentials $credentials) {}

    public function serve(): void
    {
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        session_name('tinys3_admin');
        $secure = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        session_start([
            'use_only_cookies' => 1, 'use_strict_mode' => 1, 'use_trans_sid' => 0,
            'cookie_path' => '/admin', 'cookie_httponly' => 1, 'cookie_samesite' => 'Strict',
            'cookie_secure' => $secure, 'cookie_lifetime' => 0, 'cache_limiter' => '',
        ]);
        $identity = hash('sha256', (string) ($this->config['admin_password_hash'] ?? '') . "\0" . (string) ($this->config['access_key'] ?? ''));
        if (isset($_SESSION['identity']) && !hash_equals($identity, $_SESSION['identity'])) {
            $this->renewSession();
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        $this->notice = $_SESSION['notice'] ?? null;
        unset($_SESSION['notice']);
        $authenticated = isset($_SESSION['identity']);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'POST'], true)) {
            header('Allow: GET, POST');
            http_response_code(405);
            session_write_close();
            return;
        }
        $this->view = $authenticated ? self::field($_GET, 'view', 'overview') : 'login';
        $this->bucket = self::field($_GET, 'bucket');
        $this->prefix = self::field($_GET, 'prefix');
        $this->key = self::field($_GET, 'key');
        if ($method === 'POST') {
            $this->submit($authenticated, $identity);
            return;
        }
        $data = [];
        try {
            switch ($this->view) {
                case 'download':
                    $this->download();
                    return;
                case 'overview':
                    $data['stats'] = $this->store->stats();
                    $data['recent'] = $this->store->recent(8);
                    break;
                case 'buckets':
                    $data = $this->store->buckets();
                    break;
                case 'files':
                    $cursor = self::field($_GET, 'after');
                    $data = $this->store->listObjects($this->bucket, $this->prefix, '/', self::PAGE_SIZE, $cursor === '' ? null : $cursor);
                    break;
                case 'credentials':
                    $data = $this->credentials->all();
                    break;
                case 'credential-create':
                    $_SESSION['credential_create_token'] ??= bin2hex(random_bytes(32));
                    $data['buckets'] = $this->store->buckets();
                    break;
                case 'credential-edit':
                case 'credential-revoke':
                    $data = $this->credentials->get(self::field($_GET, 'id'));
                    if ($this->view === 'credential-edit') {
                        $data['buckets'] = $this->store->buckets();
                    }
                    break;
                case 'login': case 'create': case 'upload': case 'delete-bucket': case 'delete-object':
                    break;
                default:
                    $this->redirect([]);
                    return;
            }
        } catch (DomainException $error) {
            $this->flash('error', $error->getMessage(), ['view' => 'credentials']);
            return;
        } catch (S3Error $error) {
            if ($this->view === 'download') {
                $this->flash('error', 'This object could not be downloaded. It may have been moved or deleted.', $this->folder());
                return;
            }
            $this->notice = ['error', 'TinyS3 could not load this page. Check that the bucket exists and try again.'];
            $this->readError = true;
        }
        session_write_close();
        header('Content-Type: text/html; charset=utf-8');
        $this->render($data);
    }

    private static function field(array $source, string $key, string $default = ''): string
    {
        return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : $default;
    }

    private function renewSession(): void
    {
        session_regenerate_id(true);
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
    }

    private function submit(bool $authenticated, string $identity): void
    {
        $target = $authenticated && $this->bucket !== '' ? $this->folder() : [];
        if (str_starts_with($this->view, 'credential')) {
            $target = ['view' => 'credentials'];
        }
        if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
            $this->flash('error', 'The form could not be read. Check the PHP request size limit and try again.', $target);
            return;
        }
        if (!hash_equals($_SESSION['csrf'], self::field($_POST, 'csrf'))) {
            $this->flash('error', 'This form has expired. Please try again.', $target);
            return;
        }
        $action = self::field($_POST, 'action');
        if (!$authenticated) {
            if ($action === 'login' && password_verify(self::field($_POST, 'password'), (string) ($this->config['admin_password_hash'] ?? ''))) {
                $this->renewSession();
                $_SESSION['identity'] = $identity;
                $this->redirect([]);
            } else {
                $this->flash('error', $action === 'login' ? 'The password is incorrect.' : 'Sign in to continue.', []);
            }
            return;
        }
        if ($action === 'logout') {
            $this->renewSession();
            $this->redirect([]);
            return;
        }
        $failure = 'The requested action could not be completed.';
        try {
            switch ($action) {
                case 'credential-create':
                    $target = ['view' => 'credentials'];
                    if (!isset($_SESSION['credential_create_token']) || !hash_equals($_SESSION['credential_create_token'], self::field($_POST, 'create_token'))) {
                        throw new DomainException('This creation form has expired. Open a new form to create another credential.');
                    }
                    $target = ['view' => 'credential-create'];
                    $pair = $this->credentials->create(self::field($_POST, 'label'), $this->submittedGrants());
                    unset($_SESSION['credential_create_token']);
                    session_write_close();
                    header('Content-Type: text/html; charset=utf-8');
                    $this->view = 'credential-created';
                    $this->render($pair);
                    return;
                case 'credential-edit':
                    $id = self::field($_GET, 'id');
                    $target = ['view' => 'credential-edit', 'id' => $id];
                    $this->credentials->update($id, self::field($_POST, 'label'), $this->submittedGrants());
                    $this->flash('success', 'Credential updated.', ['view' => 'credentials']);
                    return;
                case 'credential-revoke':
                    $target = ['view' => 'credentials'];
                    $this->credentials->revoke(self::field($_GET, 'id'));
                    $this->flash('success', 'Credential revoked.', $target);
                    return;
                case 'create':
                    $target = ['view' => 'create'];
                    $name = trim(self::field($_POST, 'name'));
                    if ($name === '') {
                        throw new DomainException('Enter a bucket name.');
                    }
                    $failure = 'The bucket could not be created. Check the name and whether it already exists.';
                    $this->store->createBucket($name);
                    $this->flash('success', 'Bucket created.', ['view' => 'files', 'bucket' => $name]);
                    return;
                case 'delete-bucket':
                    $target = ['view' => 'buckets'];
                    $failure = 'The bucket could not be deleted. Check that it exists and is empty.';
                    $this->store->deleteBucket($this->bucket);
                    $this->flash('success', 'Bucket deleted.', $target);
                    return;
                case 'delete-object':
                    $failure = 'The object could not be deleted.';
                    $this->store->delete($this->bucket, $this->key);
                    $this->flash('success', 'Object deleted.', $this->folder());
                    return;
                case 'upload':
                    $target = $this->folder('upload');
                    $file = $_FILES['file'] ?? [];
                    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
                    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                        throw new DomainException('The file exceeds the PHP upload size limit.');
                    }
                    if ($error !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
                        throw new DomainException('Choose a file and try the upload again.');
                    }
                    $name = self::field($_POST, 'name');
                    if ($name === '') {
                        $name = basename(str_replace('\\', '/', self::field($file, 'name')));
                    }
                    if ($name === '' || str_contains($name, "\0")) {
                        throw new DomainException('Enter a valid object name.');
                    }
                    $type = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
                    $input = fopen($file['tmp_name'], 'rb');
                    if ($input === false) {
                        throw new DomainException('The uploaded file could not be read.');
                    }
                    $failure = 'The object could not be uploaded. Check the bucket and object name.';
                    try {
                        $this->store->put($this->bucket, $this->prefix . $name, $input, $type);
                    } finally {
                        if (is_resource($input)) {
                            fclose($input);
                        }
                    }
                    $this->flash('success', 'Object uploaded.', $this->folder());
                    return;
                default:
                    throw new DomainException('The form action was not recognized.');
            }
        } catch (DomainException $error) {
            $this->flash('error', $error->getMessage(), $target);
        } catch (S3Error $error) {
            $this->flash('error', $failure, $target);
        }
    }

    private function submittedGrants(): array
    {
        // PHP can truncate form fields at max_input_vars. This marker follows every bucket control.
        if (self::field($_POST, 'grants_complete') !== '1') {
            throw new DomainException('The bucket access form was incomplete. Check the PHP form limits and try again.');
        }
        $access = $_POST['access'] ?? [];
        $buckets = $this->store->buckets();
        if (!is_array($access) || count($access) !== count($buckets)) {
            throw new DomainException('The bucket list has changed. Review bucket access and try again.');
        }
        $grants = [];
        foreach ($buckets as $bucket) {
            $name = $bucket['name'];
            $level = $access[$name] ?? null;
            if (!in_array($level, ['none', 'read', 'write'], true)) {
                throw new DomainException('Select an access level for every current bucket and try again.');
            }
            if ($level !== 'none') {
                $grants[$name] = $level;
            }
        }
        return $grants;
    }

    private function download(): void
    {
        [$row, $stream] = $this->store->open($this->bucket, $this->key);
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', basename(str_replace('\\', '/', $this->key)));
        $name = $name === '' || $name === '.' || $name === '..' ? 'download' : $name;
        $fallback = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
        session_write_close();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . (int) $row['size']);
        try {
            sendObjectBytes($stream, (int) $row['size']);
        } finally {
            fclose($stream);
        }
    }

    private function folder(string $view = 'files'): array
    {
        return ['view' => $view, 'bucket' => $this->bucket, 'prefix' => $this->prefix];
    }

    private static function url(array $query): string
    {
        $params = http_build_query(array_filter($query, static fn($value) => $value !== '' && $value !== null), '', '&', PHP_QUERY_RFC3986);
        return '/admin' . ($params === '' ? '' : '?' . $params);
    }

    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function href(array $query): string
    {
        return self::e(self::url($query));
    }

    private function redirect(array $query): void
    {
        header('Location: ' . self::url($query), true, 303);
        session_write_close();
    }

    private function flash(string $kind, string $message, array $query): void
    {
        $_SESSION['notice'] = [$kind, $message];
        $this->redirect($query);
    }

    private static function icon(string $name): string
    {
        return '<svg class="icon" aria-hidden="true" focusable="false"><use href="/assets/dashboard-icons.svg#' . self::e($name) . '"></use></svg>';
    }

    private static function size(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB'];
        $number = $bytes;
        $unit = 0;
        while ($number >= 1024 && $unit < count($units) - 1) {
            $number /= 1024;
            ++$unit;
        }
        return number_format($number, $unit > 0 && $number < 10 && floor($number) != $number ? 1 : 0) . ' ' . $units[$unit];
    }

    private static function date(mixed $value): string
    {
        $date = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
        $date = $date->setTimezone(new DateTimeZone('UTC'));
        return '<time datetime="' . self::e($date->format(DATE_ATOM)) . '" title="' . self::e($date->format('Y-m-d H:i:s T')) . '">' . self::e($date->format('M j, Y')) . '<small>' . self::e($date->format('H:i')) . ' UTC</small></time>';
    }

    private static function fileIcon(array $row): string
    {
        $type = $row['type'];
        $icon = str_starts_with($type, 'image/') ? 'image' : (preg_match('/json|javascript|xml|yaml|css|html/', $type) ? 'code' : 'file');
        return '<span class="tile tile-' . $icon . '">' . self::icon($icon) . '</span>';
    }

    private function fields(string $action): string
    {
        return '<input type="hidden" name="csrf" value="' . self::e($_SESSION['csrf']) . '"><input type="hidden" name="action" value="' . self::e($action) . '">';
    }

    private function message(): void
    {
        if ($this->notice !== null) {
            [$kind, $text] = $this->notice;
            echo '<div role="alert" class="notice ' . self::e($kind) . '">' . self::icon($kind === 'success' ? 'check' : 'alert') . self::e($text) . '</div>';
        }
    }

    private static function brand(): string
    {
        return '<span class="brand"><span class="brand-mark">' . self::icon('cube') . '</span>TinyS3</span>';
    }

    private function render(array $data): void
    {
        $titles = ['login' => 'Sign in', 'overview' => 'Overview', 'buckets' => 'Buckets', 'files' => $this->bucket, 'create' => 'Create bucket', 'upload' => 'Upload object', 'delete-bucket' => 'Delete bucket', 'delete-object' => 'Delete object'];
        $credentialTitles = [
            'credentials' => 'Credentials', 'credential-create' => 'Create credential',
            'credential-edit' => 'Edit credential', 'credential-revoke' => 'Revoke credential',
            'credential-created' => 'Credential created',
        ];
        $credentialPage = isset($credentialTitles[$this->view]);
        $title = $credentialPage ? $credentialTitles[$this->view] : $titles[$this->view];
        ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title><?= self::e($title) ?> · TinyS3</title><link rel="stylesheet" href="/assets/dashboard.css"></head>
<body>
<?php if ($this->view === 'login'): ?>
<main class="login-shell">
    <div class="login-panel">
        <div class="login-identity"><?= self::brand() ?></div>
        <section class="login-form"><span class="tile tile-bucket"><?= self::icon('lock') ?></span><h1>Welcome back.</h1><p class="intro">Sign in to your storage console.</p>
            <?php $this->message(); ?>
            <form method="post" action="/admin"><?= $this->fields('login') ?>
                <label for="password">Admin password</label><input id="password" type="password" name="password" autocomplete="current-password" autofocus required>
                <button class="button primary login-button" type="submit">Sign in <?= self::icon('arrow') ?></button>
            </form>
        </section>
        <div class="login-foot"><?= self::icon('server') ?> Self-hosted instance</div>
    </div>
    <aside class="login-art" aria-hidden="true"><img src="/assets/dashboard-storage.svg" alt=""></aside>
</main>
<?php else: ?>
<a class="skip" href="#main">Skip to content</a>
<div class="console"><aside class="sidebar"><a class="brand-link" href="/admin" aria-label="TinyS3 overview"><?= self::brand() ?></a>
    <nav aria-label="Main navigation"><span class="nav-label">Workspace</span>
        <a class="nav-item<?= $this->view === 'overview' ? ' active' : '' ?>" <?= $this->view === 'overview' ? 'aria-current="page"' : '' ?> href="/admin"><?= self::icon('overview') ?>Overview</a>
        <a class="nav-item<?= !$credentialPage && $this->view !== 'overview' ? ' active' : '' ?>" <?= $this->view === 'buckets' ? 'aria-current="page"' : '' ?> href="<?= self::href(['view' => 'buckets']) ?>"><?= self::icon('bucket') ?>Buckets</a>
        <a class="nav-item<?= $credentialPage ? ' active' : '' ?>" <?= $this->view === 'credentials' ? 'aria-current="page"' : '' ?> href="<?= self::href(['view' => 'credentials']) ?>"><?= self::icon('lock') ?>Credentials</a>
    </nav>
    <div class="account"><span class="avatar"><?= self::icon('user') ?></span><span class="account-label">Administrator<small>Local account</small></span><form method="post" action="/admin"><?= $this->fields('logout') ?><button class="icon-button" type="submit" title="Sign out" aria-label="Sign out"><?= self::icon('logout') ?></button></form></div>
</aside>
<div class="workarea"><header class="topbar"><span class="context"><?= self::icon('server') ?> Storage console</span><span class="instance"><i></i> Self-hosted</span></header>
<main id="main" class="content"><?php $this->message(); ?>
<?php if ($this->readError): ?><h1><?= self::e($title) ?></h1><a class="button" href="<?= self::href(['view' => $credentialPage ? 'credentials' : 'buckets']) ?>"><?= $credentialPage ? 'Back to credentials' : 'Browse buckets' ?></a>
<?php elseif ($credentialPage): require __DIR__ . '/CredentialView.php';
elseif ($this->view === 'overview'): $this->overview($data);
elseif ($this->view === 'buckets'): $this->buckets($data);
elseif ($this->view === 'files'): $this->files($data);
else: $this->formPage($title); endif; ?>
</main></div></div>
<?php endif; ?></body></html>
<?php
    }

    private function overview(array $data): void
    {
        ?>
<div class="heading"><div><h1>Overview</h1><p class="intro">A snapshot of your object storage.</p></div><a class="button primary" href="<?= self::href(['view' => 'create']) ?>"><?= self::icon('plus') ?> Create bucket</a></div>
<div class="stats">
<?php foreach ([['buckets', 'Buckets', 'bucket'], ['objects', 'Objects', 'layers'], ['bytes', 'Storage used', 'disk']] as [$key, $label, $icon]): ?>
<section class="stat stat-<?= $key ?>"><div class="stat-label"><span class="tile tile-<?= $icon ?>"><?= self::icon($icon) ?></span><?= $label ?></div><div class="stat-value"><?= $key === 'bytes' ? self::size((int) $data['stats'][$key]) : number_format($data['stats'][$key]) ?></div></section>
<?php endforeach; ?></div>
<section class="panel"><div class="panel-head"><div><h2>Recent objects</h2><p>Latest updates across your buckets.</p></div><span class="section-symbol"><?= self::icon('activity') ?></span></div>
<?php if (!$data['recent']): $this->emptyState('layers', 'No objects yet', 'Objects will appear here when you upload a file.', ['view' => 'buckets'], 'Browse buckets'); else: ?>
<div class="table-scroll"><table><thead><tr><th>Object name</th><th>Bucket</th><th>Size</th><th>Last modified</th></tr></thead><tbody>
<?php foreach ($data['recent'] as $row): $key = (string) $row['key']; $slash = strrpos($key, '/'); $folder = ['view' => 'files', 'bucket' => $row['bucket'], 'prefix' => $slash === false ? '' : substr($key, 0, $slash + 1)]; ?>
<tr><td><a class="entity" href="<?= self::href(array_merge($folder, ['view' => 'download', 'key' => $key])) ?>" title="Download <?= self::e($key) ?>"><?= self::fileIcon($row) ?><span class="object-name"><?= self::e($key) ?></span></a></td><td><a class="bucket-tag" href="<?= self::href($folder) ?>"><?= self::icon('bucket') ?><?= self::e($row['bucket']) ?></a></td><td class="number"><?= self::size((int) $row['size']) ?></td><td><?= self::date($row['updated_at']) ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php
    }

    private function emptyState(string $icon, string $title, string $description, array $query, string $action): void
    {
        ?><div class="empty"><span class="empty-icon"><?= self::icon($icon) ?></span><h3><?= self::e($title) ?></h3><p><?= self::e($description) ?></p><a class="text-link" href="<?= self::href($query) ?>"><?= self::e($action) ?> <?= self::icon('arrow') ?></a></div><?php
    }

    private function buckets(array $rows): void
    {
        ?>
<div class="heading"><div><h1>Buckets</h1><p class="intro">Manage the buckets in your storage instance.</p></div><a class="button primary" href="<?= self::href(['view' => 'create']) ?>"><?= self::icon('plus') ?> Create bucket</a></div>
<section class="panel"><div class="panel-head"><h2>All buckets <span class="count"><?= number_format(count($rows)) ?></span></h2></div>
<?php if (!$rows): $this->emptyState('bucket', 'No buckets yet', 'Create a bucket to start storing objects.', ['view' => 'create'], 'Create bucket'); else: ?>
<div class="table-scroll"><table><thead><tr><th>Bucket name</th><th>Created</th><th class="actions">Actions</th></tr></thead><tbody>
<?php foreach ($rows as $row): $name = $row['name']; ?>
<tr><td><a class="entity" href="<?= self::href(['view' => 'files', 'bucket' => $name]) ?>"><span class="tile tile-bucket"><?= self::icon('bucket') ?></span><?= self::e($name) ?></a></td><td><?= self::date($row['created_at']) ?></td><td class="actions"><a class="icon-button danger-icon" href="<?= self::href(['view' => 'delete-bucket', 'bucket' => $name]) ?>" title="Delete bucket" aria-label="Delete bucket <?= self::e($name) ?>"><?= self::icon('trash') ?></a></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php
    }

    private function breadcrumbs(): void
    {
        $root = ['view' => 'files', 'bucket' => $this->bucket];
        ?><nav class="crumbs" aria-label="Object location"><a href="<?= self::href(['view' => 'buckets']) ?>">Buckets</a><?php if ($this->bucket !== ''): ?><?= self::icon('right') ?><a href="<?= self::href($root) ?>"><?= self::e($this->bucket) ?></a><?php endif;
        $path = '';
        $segments = explode('/', $this->prefix);
        array_pop($segments);
        foreach ($segments as $segment) {
            $path .= $segment . '/';
            echo self::icon('right') . '<a href="' . self::href($root + ['prefix' => $path]) . '">' . self::e($segment === '' ? '/' : $segment) . '</a>';
        }
        ?></nav><?php
    }

    private function files(array $page): void
    {
        $this->breadcrumbs();
        ?>
<div class="heading"><div><h1><?= self::e($this->bucket) ?></h1><p class="intro">Browse and manage objects in this bucket.</p></div><a class="button primary" href="<?= self::href($this->folder('upload')) ?>"><?= self::icon('upload') ?> Upload object</a></div>
<section class="panel"><div class="panel-head"><h2><?= $this->prefix === '' ? 'All objects' : self::e($this->prefix) ?></h2></div>
<?php if (!$page['prefixes'] && !$page['objects']): $this->emptyState('folder', 'No objects here', 'Upload a file to this location.', $this->folder('upload'), 'Upload object'); else: ?>
<div class="table-scroll"><table><thead><tr><th>Name</th><th>Size</th><th>Last modified</th><th class="actions">Actions</th></tr></thead><tbody>
<?php foreach ($page['prefixes'] as $prefix): ?>
<tr><td><a class="entity" href="<?= self::href(['view' => 'files', 'bucket' => $this->bucket, 'prefix' => $prefix]) ?>"><span class="tile tile-folder"><?= self::icon('folder') ?></span><?= self::e(substr($prefix, strlen($this->prefix))) ?></a></td><td colspan="3" class="muted">Folder</td></tr>
<?php endforeach; foreach ($page['objects'] as $row): $key = (string) $row['key']; $name = substr($key, strlen($this->prefix)); $download = self::href($this->folder('download') + ['key' => $key]); ?>
<tr><td><a class="entity" href="<?= $download ?>"><?= self::fileIcon($row) ?><span class="object-name"><?= self::e($name === '' ? $key : $name) ?></span></a></td><td class="number"><?= self::size((int) $row['size']) ?></td><td><?= self::date($row['updated_at']) ?></td><td class="actions"><a class="icon-button" href="<?= $download ?>" title="Download" aria-label="Download <?= self::e($key) ?>"><?= self::icon('download') ?></a> <a class="icon-button danger-icon" href="<?= self::href($this->folder('delete-object') + ['key' => $key]) ?>" title="Delete object" aria-label="Delete <?= self::e($key) ?>"><?= self::icon('trash') ?></a></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if (self::field($_GET, 'after') !== '' || $page['next'] !== null): ?><div class="pagination"><?php if (self::field($_GET, 'after') !== ''): ?><a class="text-link" href="<?= self::href($this->folder()) ?>">First page</a><?php endif; ?><?php if ($page['next'] !== null): ?><a class="button next" href="<?= self::href($this->folder() + ['after' => $page['next']]) ?>">Next page <?= self::icon('arrow') ?></a><?php endif; ?></div><?php endif; ?></section>
<?php
    }

    private function formPage(string $title): void
    {
        $this->breadcrumbs();
        $upload = $this->view === 'upload';
        $create = $this->view === 'create';
        $bucketDelete = $this->view === 'delete-bucket';
        $delete = !$upload && !$create;
        $cancel = $create || $bucketDelete ? ['view' => 'buckets'] : $this->folder();
        $target = $this->folder($this->view) + ['key' => $this->key];
        ?>
<div class="heading"><div><h1><?= self::e($title) ?></h1></div></div>
<form class="panel form-panel" method="post" action="<?= self::href($target) ?>" <?= $upload ? 'enctype="multipart/form-data"' : '' ?>><?= $this->fields($this->view) ?>
<?php if ($create): ?><label for="name">Bucket name</label><input id="name" name="name" type="text" placeholder="e.g. project-assets" required maxlength="63" autocapitalize="none" spellcheck="false" autofocus><p class="hint">Use a unique name with 3–63 lowercase letters, numbers, dots, or hyphens.</p>
<?php elseif ($upload): ?><label for="file">File</label><input type="file" name="file" id="file" required><label for="name">Object name <span class="optional">Optional</span></label><input type="text" id="name" name="name" placeholder="Use the original filename" spellcheck="false"><p class="hint">Include a prefix such as <code>images/</code> to create a folder. Uploading the same object name replaces its contents.</p>
<?php else: ?><span class="tile tile-danger"><?= self::icon('trash') ?></span><p class="delete-copy">Permanently delete <?= $bucketDelete ? 'bucket' : 'object' ?> <strong><?= self::e($bucketDelete ? $this->bucket : $this->key) ?></strong>?</p><p class="hint"><?= $bucketDelete ? 'The bucket must be empty before you can delete it.' : 'This removes the object from the bucket and cannot be undone.' ?></p>
<?php endif; ?><div class="form-actions"><a class="button" href="<?= self::href($cancel) ?>">Cancel</a><button type="submit" class="button <?= $delete ? 'destructive' : 'primary' ?>"><?= self::icon($delete ? 'trash' : ($upload ? 'upload' : 'plus')) ?><?= self::e($title) ?></button></div></form>
<?php
    }
}
