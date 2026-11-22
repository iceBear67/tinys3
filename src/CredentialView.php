<?php

$credentialsUrl = self::href(['view' => 'credentials']);
if ($this->view === 'credentials'): ?>
<div class="heading">
    <div><h1>Credentials</h1><p class="intro">Manage S3 access for applications.</p></div>
    <a class="button primary" href="<?= self::href(['view' => 'credential-create']) ?>"><?= self::icon('plus') ?> Create credential</a>
</div>
<section class="panel">
    <div class="panel-head"><div><h2>Application credentials <span class="count"><?= number_format(count($data)) ?></span></h2>
        <p>The administrator key has full access to all buckets and is managed in the server configuration.</p>
    </div></div>
    <?php if (!$data): ?>
        <div class="empty"><span class="empty-icon"><?= self::icon('lock') ?></span><h3>No application credentials yet</h3></div>
    <?php else: ?>
        <div class="table-scroll"><table class="credential-table">
            <thead><tr><th scope="col">Label</th><th scope="col">Access key</th><th scope="col">Created</th><th class="actions" scope="col">Actions</th></tr></thead>
            <tbody><?php foreach ($data as $row): ?>
                <tr>
                    <td class="credential-label"><?= self::e($row['label']) ?></td>
                    <td><code><?= self::e($row['access_key']) ?></code></td>
                    <td><?= self::date($row['created_at']) ?></td>
                    <td class="actions">
                        <a class="button" aria-label="Edit credential <?= self::e($row['label']) ?>" href="<?= self::href(['view' => 'credential-edit', 'id' => $row['access_key']]) ?>">Edit</a>
                        <a class="button" aria-label="Revoke credential <?= self::e($row['label']) ?>" href="<?= self::href(['view' => 'credential-revoke', 'id' => $row['access_key']]) ?>">Revoke</a>
                    </td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php else: ?>
<nav class="crumbs" aria-label="Credential location"><a href="<?= $credentialsUrl ?>">Credentials</a></nav>
<div class="heading"><h1><?= self::e($title) ?></h1></div>
<?php if ($this->view === 'credential-created'): ?>
<section class="panel form-panel">
    <p>Copy the secret key now and store it securely. It is shown only once.</p>
    <dl class="credential-keys">
        <dt>Access key</dt><dd><code><?= self::e($data['access_key']) ?></code></dd>
        <dt>Secret key</dt><dd><code><?= self::e($data['secret_key']) ?></code></dd>
    </dl>
    <div class="form-actions"><a class="button primary" href="<?= $credentialsUrl ?>">Back to credentials</a></div>
</section>
<?php else:
    $creating = $this->view === 'credential-create';
    $revoking = $this->view === 'credential-revoke';
    $target = ['view' => $this->view];
    if (!$creating) {
        $target['id'] = $data['access_key'];
    }
?>
<form class="panel form-panel" method="post" action="<?= self::href($target) ?>">
    <?= $this->fields($this->view) ?>
    <?php if ($revoking): ?>
        <span class="tile tile-danger"><?= self::icon('trash') ?></span>
        <p class="delete-copy">Revoke <strong><?= self::e($data['label']) ?></strong> (<code><?= self::e($data['access_key']) ?></code>)?</p>
        <p class="hint">Applications using this credential will lose access immediately.</p>
    <?php else: ?>
        <?php if ($creating): ?>
            <input type="hidden" name="create_token" value="<?= self::e($_SESSION['credential_create_token']) ?>">
        <?php else: ?>
            <p class="credential-id">Access key <code><?= self::e($data['access_key']) ?></code></p>
        <?php endif; ?>
        <label for="credential-label">Label</label>
        <input type="text" id="credential-label" name="label" value="<?= self::e($data['label'] ?? '') ?>" required>
        <fieldset class="bucket-access"><legend>Bucket access</legend>
            <?php if (!$data['buckets']): ?>
                <p class="hint">No buckets are available. You can assign access after creating a bucket.</p>
            <?php else: ?>
                <p class="access-help">Read allows listing and downloading objects. Write also allows uploading and deleting objects.</p>
            <?php endif; ?>
            <?php foreach ($data['buckets'] as $index => $bucket):
                $name = $bucket['name'];
                $current = $data['grants'][$name] ?? 'none';
            ?>
                <div class="access-row">
                    <label for="bucket-access-<?= (int) $index ?>"><?= self::e($name) ?></label>
                    <select id="bucket-access-<?= (int) $index ?>" name="access[<?= self::e($name) ?>]">
                        <?php foreach (['none' => 'None', 'read' => 'Read', 'write' => 'Write'] as $value => $label): ?>
                            <option value="<?= $value ?>"<?= $current === $value ? ' selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
        </fieldset>
        <input type="hidden" name="grants_complete" value="1">
    <?php endif; ?>
    <div class="form-actions">
        <a class="button" href="<?= $credentialsUrl ?>">Cancel</a>
        <button class="button <?= $revoking ? 'destructive' : 'primary' ?>" type="submit"><?= $revoking ? 'Revoke credential' : ($creating ? 'Create credential' : 'Save changes') ?></button>
    </div>
</form>
<?php endif; endif; ?>
