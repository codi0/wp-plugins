<?php

/*
Plugin Name: Codi Disable XML-RPC
Description: Prevent spam attacks to xmlrpc.php by disabling requests
Version: 1.0.0
Author: codi0
*/

defined('ABSPATH') or die;


//kill xmlrpc dead?
if(defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
    http_response_code(404);
    exit();
}