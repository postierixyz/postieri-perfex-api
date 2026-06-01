<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-8">
                <h4 class="tw-mt-0 tw-mb-4"><?= _l('postieri_api_webhooks'); ?></h4>

                <div class="panel_s">
                    <div class="panel-body">
                        <h5>Create new webhook</h5>
                        <?= form_open(admin_url('postieri_api/webhooks')); ?>
                            <div class="form-group">
                                <label for="webhook_name"><?= _l('postieri_api_webhook_name'); ?></label>
                                <input type="text" name="name" id="webhook_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="webhook_url"><?= _l('postieri_api_webhook_url'); ?></label>
                                <input type="url" name="url" id="webhook_url" class="form-control" required
                                       placeholder="https://your-app.example.com/webhook">
                            </div>
                            <div class="form-group">
                                <label for="webhook_events"><?= _l('postieri_api_webhook_events'); ?></label>
                                <textarea name="events" id="webhook_events" class="form-control" rows="6" required><?= "invoice.created\ninvoice.paid\nsubscription.expiring\nsubscription.expired\nlead.created\nlead.converted"; ?></textarea>
                                <small class="text-muted">One event per line. Available: <code>invoice.created</code>, <code>invoice.paid</code>, <code>subscription.expiring</code>, <code>subscription.expired</code>, <code>lead.created</code>, <code>lead.converted</code>.</small>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-plus"></i> <?= _l('postieri_api_webhook_create'); ?>
                            </button>
                        <?= form_close(); ?>
                    </div>
                </div>

                <p class="text-muted tw-mt-4">
                    <i class="fa fa-shield"></i>
                    Webhooks are signed with HMAC-SHA256. Your endpoint must verify the
                    <code>X-Postieri-Signature</code> header.
                </p>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
