<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Admin controller for the Postieri API module.
 *
 * Scope: token issuance only. Webhook management and settings are exposed
 * exclusively through the REST API. (Decision: keep admin UI minimal —
 * the API is the product, the UI is a thin issuer.)
 */
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
     * Module landing page — issues a brief API info card and points admins
     * at the REST API docs (docs/openapi.yaml in the repo).
     */
    public function index(): void
    {
        $data['title'] = _l('postieri_api');
        $data['version'] = POSTIERI_API_VERSION ?? '0.0.0';
        $this->load->view('postieri_api/index', $data);
    }

    /**
     * Token issuance page. Admins can create new API tokens here.
     * Listing, revocation, and editing of existing tokens happens via the
     * REST API (/api/v1/auth/tokens).
     */
    public function tokens(): void
    {
        if (!is_admin()) {
            access_denied(_l('postieri_api_tokens'));
        }
        $data['title'] = _l('postieri_api_tokens');
        $this->load->view('postieri_api/tokens', $data);
    }
}
