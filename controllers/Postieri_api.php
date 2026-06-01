<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Postieri_api extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        if (staff_cant('view', 'settings')) {
            access_denied(_l('postieri_api'));
        }
    }

    /**
     * Module dashboard / index.
     */
    public function index(): void
    {
        $data['title'] = _l('postieri_api');
        $this->load->view('postieri_api/index', $data);
    }

    /**
     * Token management page.
     */
    public function tokens(): void
    {
        if (!is_admin()) {
            access_denied(_l('postieri_api_tokens'));
        }
        $data['title'] = _l('postieri_api_tokens');
        $this->load->view('postieri_api/tokens', $data);
    }

    /**
     * Webhook subscriptions page.
     */
    public function webhooks(): void
    {
        if (!is_admin()) {
            access_denied(_l('postieri_api_webhooks'));
        }
        $data['title'] = _l('postieri_api_webhooks');
        $this->load->view('postieri_api/webhooks', $data);
    }
}
