<?php
defined( 'ABSPATH' ) || exit;

GFForms::include_addon_framework();

class GFGCS_Addon extends GFAddOn {
    protected $_version           = GFGCS_VERSION;
    protected $_min_gravityforms_version = '2.7';
    protected $_slug              = 'gf-gcs-uploads';
    protected $_path              = 'gf-gcs-uploads/gf-gcs-uploads.php';
    protected $_full_path         = __FILE__;
    protected $_title             = 'GCS Uploads';
    protected $_short_title       = 'GCS Uploads';

    private static $_instance = null;

    public static function get_instance() {
        if ( null === self::$_instance ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function init() {
        parent::init();
    }
}
