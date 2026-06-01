<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <h4 class="tw-mt-0 tw-mb-4"><?= _l('postieri_api'); ?></h4>
                <p class="text-muted">REST API + webhooks for Perfex CRM. Build <?= POSTIERI_API_VERSION; ?>.</p>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="panel_s">
                    <div class="panel-body text-center">
                        <i class="fa fa-key fa-2x tw-mb-3 text-muted"></i>
                        <h4><?= _l('postieri_api_tokens'); ?></h4>
                        <p class="text-muted">Issue, manage and revoke API tokens.</p>
                        <a href="<?= admin_url('postieri_api/tokens'); ?>" class="btn btn-primary">
                            <?= _l('postieri_api_tokens'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="panel_s">
                    <div class="panel-body text-center">
                        <i class="fa fa-bell fa-2x tw-mb-3 text-muted"></i>
                        <h4><?= _l('postieri_api_webhooks'); ?></h4>
                        <p class="text-muted">Subscribe to Perfex events with HMAC-signed delivery.</p>
                        <a href="<?= admin_url('postieri_api/webhooks'); ?>" class="btn btn-primary">
                            <?= _l('postieri_api_webhooks'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="panel_s">
                    <div class="panel-body text-center">
                        <i class="fa fa-book fa-2x tw-mb-3 text-muted"></i>
                        <h4>API Documentation</h4>
                        <p class="text-muted">Read the full OpenAPI 3.1 spec on GitHub.</p>
                        <a href="https://github.com/postierixyz/postieri-perfex-api" target="_blank" rel="noopener" class="btn btn-default">
                            <i class="fa fa-external-link"></i> GitHub
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
