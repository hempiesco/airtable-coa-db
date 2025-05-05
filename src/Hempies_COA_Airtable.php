<?php

namespace Hempies\COA;

class Hempies_COA_Airtable {
    private $square_access_token;
    private $airtable_api_key;
    private $airtable_base_id;
    private $airtable_table_name;
    private $sync_running = false;
    private $sync_log = [];

    public function __construct() {
        $this->square_access_token = $_ENV['SQUARE_ACCESS_TOKEN'] ?? '';
        $this->airtable_api_key = $_ENV['AIRTABLE_API_KEY'] ?? '';
        $this->airtable_base_id = $_ENV['AIRTABLE_BASE_ID'] ?? '';
        $this->airtable_table_name = $_ENV['AIRTABLE_TABLE_NAME'] ?? 'Products';
    }

    public function start_sync($test_mode = false) {
        $this->sync_running = true;
        $this->log('Starting sync' . ($test_mode ? ' in test mode' : ''));
        // TODO: Implement actual sync logic
        return true;
    }

    public function get_sync_status() {
        return [
            'running' => $this->sync_running,
            'log' => $this->sync_log
        ];
    }

    private function log($message) {
        $this->sync_log[] = [
            'time' => date('Y-m-d H:i:s'),
            'message' => $message
        ];
    }
} 