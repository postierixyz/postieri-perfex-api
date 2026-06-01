<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-6">
                <h4 class="tw-mt-0 tw-mb-4"><?= _l('postieri_api_tokens'); ?></h4>

                <div class="panel_s">
                    <div class="panel-body">
                        <h5>Create new token</h5>
                        <?= form_open(admin_url('postieri_api/tokens')); ?>
                            <div class="form-group">
                                <label for="token_name"><?= _l('postieri_api_token_name'); ?></label>
                                <input type="text" name="name" id="token_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="token_scopes"><?= _l('postieri_api_token_scopes'); ?></label>
                                <input type="text" name="scopes" id="token_scopes" class="form-control"
                                       placeholder='* or customers:read,invoices:read' value="*">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-plus"></i> <?= _l('postieri_api_token_create'); ?>
                            </button>
                        <?= form_close(); ?>
                    </div>
                </div>

                <p class="text-muted tw-mt-4">
                    <i class="fa fa-info-circle"></i>
                    Programmatically: <code>POST /api/v1/auth/token</code> with admin Bearer token.
                    See the README for the full reference.
                </p>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
