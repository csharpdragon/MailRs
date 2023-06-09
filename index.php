<?php

    class GmailResponser{
        public function __construct(){
            $this->include();
        }

        public function include(){
            require __DIR__ . '/vendor/autoload.php';
            include "environment.php";
            include "connection.php";
        }

        public function run(){
            $conn = new connection();
            
            if ($conn->is_connected()) {
                require_once("fetching.php");
                $mailManager = new mailManager($conn->get_client());
                return $mailManager->listMessages();
            } else {
                $conn->get_unauthenticated_data();
                return null;
            }
        }
    }

    
    $testObj = new GmailResponser();
    $out =  $testObj->run();
?>