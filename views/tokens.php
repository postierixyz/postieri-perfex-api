<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">

            <?php if (!empty($new_token) && !empty($new_token['token'])): ?>
                <!-- ============ Newly issued token (shown once) ============ -->
                <div class="col-md-12">
                    <div class="alert alert-success" role="alert">
                        <h4 class="alert-heading tw-mt-0">
                            <i class="fa fa-check-circle"></i>
                            <?= _l('postieri_api_token_issued'); ?>
                        </h4>
                        <p><?= _l('postieri_api_token_created_once'); ?></p>
                        <div class="input-group tw-mt-3">
                            <input type="text" id="postieri_new_token" class="form-control"
                                   value="<?= htmlspecialchars($new_token['token'], ENT_QUOTES, 'UTF-8'); ?>"
                                   readonly onclick="this.select();">
                            <span class="input-group-btn">
                                <button class="btn btn-primary" type="button"
                                        onclick="postieri_copy_token(this);">
                                    <i class="fa fa-copy"></i> <?= _l('postieri_api_token_copy'); ?>
                                </button>
                            </span>
                        </div>
                        <small class="text-muted tw-mt-2 tw-block">
                            <strong>Name:</strong> <?= htmlspecialchars($new_token['name'], ENT_QUOTES, 'UTF-8'); ?>
                            &nbsp;·&nbsp;
                            <strong>Scopes:</strong>
                            <code><?= htmlspecialchars(implode(', ', (array) ($new_token['scopes'] ?? ['*'])), ENT_QUOTES, 'UTF-8'); ?></code>
                        </small>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ============ Issue form ============ -->
            <div class="col-md-6">
                <h4 class="tw-mt-0 tw-mb-4"><?= _l('postieri_api_tokens'); ?></h4>

                <div class="panel_s">
                    <div class="panel-body">
                        <h5><?= _l('postieri_api_token_create'); ?></h5>
                        <?= form_open(admin_url('postieri_api/tokens')); ?>
                            <div class="form-group">
                                <label for="token_name"><?= _l('postieri_api_token_name'); ?></label>
                                <input type="text" name="name" id="token_name" class="form-control"
                                       maxlength="100" required
                                       placeholder="e.g. Zoho integration, GitHub Actions">
                            </div>
                            <div class="form-group">
                                <label for="token_scopes"><?= _l('postieri_api_token_scopes'); ?></label>
                                <input type="text" name="scopes" id="token_scopes" class="form-control"
                                       value="*" placeholder="* or customers:read,invoices:write">
                                <small class="text-muted"><?= _l('postieri_api_scopes_help'); ?></small>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-plus"></i> <?= _l('postieri_api_token_create'); ?>
                            </button>
                        <?= form_close(); ?>
                    </div>
                </div>

                <p class="text-muted tw-mt-4">
                    <i class="fa fa-info-circle"></i>
                    <?= _l('postieri_api_token_created_once'); ?>
                </p>
            </div>

            <!-- ============ Active tokens ============ -->
            <div class="col-md-6">
                <h4 class="tw-mt-0 tw-mb-4">Active tokens</h4>

                <div class="panel_s">
                    <div class="panel-body">
                        <?php if (empty($tokens)): ?>
                            <p class="text-muted tw-mb-0">
                                <i class="fa fa-info-circle"></i> <?= _l('postieri_api_token_no_tokens'); ?>
                            </p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped tw-mb-0">
                                    <thead>
                                        <tr>
                                            <th><?= _l('postieri_api_token_name'); ?></th>
                                            <th>Scopes</th>
                                            <th><?= _l('postieri_api_token_created_at'); ?></th>
                                            <th><?= _l('postieri_api_token_last_used'); ?></th>
                                            <th><?= _l('postieri_api_token_expires_at'); ?></th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($tokens as $t): ?>
                                        <tr>
                                            <td>
                                                <?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if (!empty($t['revoked_at'])): ?>
                                                    <span class="label label-danger">revoked</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <code class="tw-text-xs">
                                                    <?= htmlspecialchars(implode(', ', (array) ($t['scopes'] ?? [])), ENT_QUOTES, 'UTF-8'); ?>
                                                </code>
                                            </td>
                                            <td><?= htmlspecialchars($t['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?= !empty($t['last_used_at']) ? htmlspecialchars($t['last_used_at'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                            <td><?= !empty($t['expires_at']) ? htmlspecialchars($t['expires_at'], ENT_QUOTES, 'UTF-8') : _l('postieri_api_token_never'); ?></td>
                                            <td>
                                                <?php if (empty($t['revoked_at'])): ?>
                                                    <?= form_open(admin_url('postieri_api/tokens')); ?>
                                                        <input type="hidden" name="revoke_id" value="<?= (int) ($t['id'] ?? 0); ?>">
                                                        <button type="submit" class="btn btn-xs btn-danger"
                                                                onclick="return confirm('<?= _l('postieri_api_token_confirm_revoke'); ?>');">
                                                            <i class="fa fa-trash"></i> <?= _l('postieri_api_token_revoke'); ?>
                                                        </button>
                                                    <?= form_close(); ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function postieri_copy_token(btn) {
    var el = document.getElementById('postieri_new_token');
    if (!el) return;
    el.select();
    el.setSelectionRange(0, 99999);
    try {
        document.execCommand('copy');
        var original = btn.innerHTML;
        btn.innerHTML = '<i class="fa fa-check"></i> <?= _l('postieri_api_token_copied'); ?>';
        setTimeout(function () { btn.innerHTML = original; }, 1500);
    } catch (e) {
        // Clipboard API failed; user can still copy manually.
    }
}
</script>

<?php init_tail(); ?>
