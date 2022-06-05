<?php

class Controller {

    private $MODES = array(
        'initial', 
        'accept_all', 
        'deny_basic', 
        'deny_advanced'
    );
    private $HTTP_STATUSES = array(
        400 => 'Bad Request',
        401 => 'Authentication Failed',
    );
    private $API_ENTRIES = [];


    function __construct() {
        // Initialize $API_ENTRIES here to circumvent error
        // 'Constant expression contains invalid operations'.
        $api_entries = explode(',', $_ENV['API_ENTRIES']);
        foreach ($api_entries as $api_entry) {
            $splitted = explode(':', $api_entry);
            $api_key  = $splitted[0];
            $user     = $splitted[1];
            $this->API_ENTRIES[$api_key] = $user;
        }
    }


    private function dieWith($code, $message = null) {
        http_response_code($code);
        echo json_encode($message ?? $this->HTTP_STATUSES[$code]);
        die;
    }


    function beforeRoute($f3, $args) {
        // Always set content-type json
        header('Content-Type: application/json; charset=utf-8');

        // Validate API Key
        if (!isset($_GET['api_key']) || !in_array($_GET['api_key'], array_keys($this->API_ENTRIES))) {
            $this->dieWith(401);
        }
    }


    // 'GET /'
    function root() {
        echo json_encode('Welcome to the Dark Patterns Cookie Helper API.');
    }


    // 'GET /validate-key'
    function validateKey() {
        header('X-User: ' . $this->API_ENTRIES[$_GET['api_key']]);
        echo json_encode('Your API key is valid.');
    }


    // 'GET /stats'
    function stats($f3) {
        $result = $f3->db->exec("SELECT * FROM `websites`");
        if (!$result || !is_array($result)) {
            echo json_encode("");
            die;
        }
        $stats = array('count_total' => count($result));
        foreach ($this->MODES as $mode) {
            $stats['completed_' . $mode] = count(array_filter($result, fn ($item) => $item[$mode . '_completed'] !== null));
        }
        
        echo json_encode($stats);
    }


    // 'GET /next-website/@mode'
    function nextWebsite($f3,$args) {
        $mode = $args['mode'];
        if (!in_array($mode, $this->MODES)) {
            $this->dieWith(400);
        }
        $column_completed = $mode . '_completed';
        $column_fetches   = $mode . '_fetches';
        
        $numkeys   = count($this->API_ENTRIES);
        $key_index = array_search($_GET['api_key'], array_keys($this->API_ENTRIES));

        $f3->db->begin();
        $result = $f3->db->exec("SELECT * FROM `websites` WHERE `{$column_completed}` IS NULL AND `id` % {$numkeys} = {$key_index} ORDER BY `{$column_fetches}` LIMIT 1");
        
        if (!$result) {
            $f3->db->commit();
            echo json_encode("");
            die;
        }
        $website = $result[0]['url'];
        $user    = $this->API_ENTRIES[$_GET['api_key']];
        $f3->db->exec("UPDATE `websites` SET `{$column_fetches}` = `{$column_fetches}` + 1, `user` = '{$user}' WHERE `url` = '{$website}'");
        $f3->db->commit();

        echo json_encode($website);
    }


    // 'POST /report-cookies/@url/@mode'
    function reportCookiesAndClicks($f3,$args) {

        // Extract mode
        $mode = $args['mode'];
        if (!in_array($mode, $this->MODES)) {
            $this->dieWith(400);
        }

        // Extract and verify url with a prepared statement (safe from SQL injection)
        $url = $args['url'];
        $result = $f3->db->exec("SELECT * FROM `websites` WHERE `url` = (?)", array($url));
        if (!$result || count($result) !== 1) {
            $this->dieWith(400);
        }

        // Extract data from POST
        $data = json_decode($f3->BODY, false);
        if (!$data || !isset($data->clicks) || !isset($data->cookies) || !is_array($data->cookies)) {
            $this->dieWith(400);
        }
        $clicks  = $data->clicks;
        $cookies = $data->cookies;

        // Ensure that query params and cookie fields match
        $cookiesFiltered = array_filter($cookies, fn ($cookie) => $cookie->url === $url && $cookie->mode === $mode);
        if (count($cookies) != count($cookiesFiltered)) {
            $this->dieWith(400);
        }

        // Insert cookies into database (the copyFrom function is safe from SQL injection)
        $f3->db->begin();
        $f3->db->exec("DELETE FROM `cookies` WHERE `url` = (?) AND `mode` = '{$mode}'", array($url));
        $cookie_mapper = new DB\SQL\Mapper($f3->db, 'cookies');
        foreach ($cookies as $cookie) {
            $cookie_mapper->copyFrom($cookie);
            $cookie_mapper->save();
            $cookie_mapper->reset();
        }
        $column_completed = $mode . '_completed';
        $column_clicks    = $mode . '_clicks';
        $f3->db->exec("UPDATE `websites` SET `{$column_completed}` = NOW(), `{$column_clicks}` = (?) WHERE `url` = (?)", array($clicks, $url));
        $f3->db->commit();

        echo json_encode("Cookies successfully recorded.");
    }
}