<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <h4 class="tw-mt-0 tw-mb-4"><?= _l('postieri_api_settings'); ?></h4>

                <?= form_open(admin_url('postieri_api/save_settings')); ?>

                <div class="form-group">
                    <div class="checkbox checkbox-primary">
                        <input type="checkbox" name="postieri_api_enabled" id="postieri_api_enabled"
                               value="1" <?= get_option('postieri_api_enabled') === '1' ? 'checked' : ''; ?>>
                        <label for="postieri_api_enabled"><?= _l('postieri_api_enabled'); ?></label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="postieri_api_rate_limit_per_min"><?= _l('postieri_api_rate_limit_min'); ?></label>
                    <input type="number" min="1" max="10000" class="form-control"
                           name="postieri_api_rate_limit_per_min" id="postieri_api_rate_limit_per_min"
                           value="<?= get_option('postieri_api_rate_limit_per_min'); ?>">
                </div>

                <div class="form-group">
                    <label for="postieri_api_rate_limit_per_hour"><?= _l('postieri_api_rate_limit_hour'); ?></label>
                    <input type="number" min="1" max="100000" class="form-control"
                           name="postieri_api_rate_limit_per_hour" id="postieri_api_rate_limit_per_hour"
                           value="<?= get_option('postieri_api_rate_limit_per_hour'); ?>">
                </div>

                <div class="form-group">
                    <label for="postieri_api_default_token_scopes"><?= _l('postieri_api_default_scopes'); ?></label>
                    <input type="text" class="form-control" name="postieri_api_default_token_scopes"
                           id="postieri_api_default_token_scopes"
                           value="<?= get_option('postieri_api_default_token_scopes'); ?>">
                    <small class="text-muted">JSON array, e.g. <code>["*"]</code> or <code>["customers:read","invoices:read"]</code></small>
                </div>

                <button type="submit" class="btn btn-primary"><?= _l('postieri_api_save_settings'); ?></button>

                <?= form_close(); ?>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
