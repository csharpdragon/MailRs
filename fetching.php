<?php
use GuzzleHttp\Client;

class mailManager{
    public function __construct($client){
        $this->client = $client;
    }
    
    public function readLabels()
    {
        $service = new Google_Service_Gmail($this->client);

        // Print the labels in the user's account.
        $user = 'me';
        $results = $service->users_labels->listUsersLabels($user);

        $the_html = "";

        if (count($results->getLabels()) == 0) {
            // print "No labels found.\n";
            $the_html .= "<p>No Labels Found</p>";
        } else {
            // print "Labels:\n";
            $the_html .= "<p>Labels : </p>";

            foreach ($results->getLabels() as $label) {
                printf("- %s\n", $label->getName());
                // $the_html.="<p>.$label->getName().</p>";

            }
        }
        return $the_html;
    }

    /**
     * Get list of Messages in user's mailbox.
     *
     * @param  Google_Service_Gmail $service Authorized Gmail API instance.
     * @param  string $userId User's email address. The special value 'me'
     * can be used to indicate the authenticated user.
     * @return array Array of Messages.
     */
    public function listMessages()
    {
        // log all state to this file
        $output_log_file = "output_log.txt";
        file_put_contents($output_log_file, "start the listing messages....\n");

        $service = new Google_Service_Gmail($this->client);

        $profile = $service->users->getProfile('me'); 
        $email = $profile->getEmailAddress(); // get user's email address
        // Print the labels in the user's account.
        $userId = 'me';    // indicate the authenticated user
        $pageToken = NULL;   // for the next page
        $messages = array();   //store all messages
        $opt_param = array(); // query optional parameter for gmail api

        // get today's date
        $startTime = new DateTime();

        file_put_contents($output_log_file, $startTime->format('Y/m/d') . "\n", FILE_APPEND);
        // mail query to get today mails
        $query = 'after:' . $startTime->format('Y/m/d');

        do {

            
            try {

                $opt_param['q'] = $query;
                if ($pageToken) {
                    $opt_param['pageToken'] = $pageToken;
                }

                $messagesResponse = $service->users_messages->listUsersMessages($userId, $opt_param);
                if ($messagesResponse->getMessages()) {
                    $messages = array_merge($messages, $messagesResponse->getMessages());
                    $pageToken = $messagesResponse->getNextPageToken();
                    if(!$pageToken){
                        break;
                    }
                }
            } catch (Exception $e) {
                print 'An error occurred: ' . $e->getMessage();
                file_put_contents($output_log_file, "Error:" . $e->getMessage() . "\n", FILE_APPEND);
            }
        } while ($pageToken);

        
        $decoded_msg = array();
        $message_info = array();

        foreach ($messages as $message) {
            $receivedObject = new stdClass();

            $msg = $service->users_messages->get($userId, $message->getId());
            $receivedObject->threadId = $msg->getThreadId();
            $receivedObject->id = $msg->getId();
            //get sender email address from $msg
            $headers = $msg->getPayload()->getHeaders();


            
            foreach ($headers as $header) {
                if ($header->getName() == "From") {
                    $receivedObject->sender = $header->getValue();
                }

                if ($header->getName() == "Subject") {
                    $receivedObject->subject = $header->getValue();
                }
                if ($header->getName() == "Date") {
                    $receivedObject->date = $header->getValue();
                }
                // get sede email address from $msg
                if ($header->getName() == "To") {
                    $receivedObject->to = $header->getValue();
                }
                // get smtp mailfrom address
                if ($header->getName() == "Authentication-Results") {
                    $pattern = "/smtp.mailfrom=([^;]+)/";
                    preg_match($pattern, $header->getValue(), $matches);
                    $receivedObject->from = trim($matches[1]);
                }
            }

            $parts = $msg->getPayload()->getParts();
            foreach ($parts as $part) {
                if ($part->getMimeType() == 'text/plain') {
                    $receivedObject->content = base64_decode($part->getBody()->getData());
                }
            }

           

            array_push($message_info, $receivedObject);

            $parts = $msg->getPayload()->getParts();

            if (count($parts) > 0) {
                $data= $parts[1]->getBody()->getData();
            } else {
                $data= $msg->getPayload()->getBody()->getData();
            }
            $out= str_replace("-","+",$data);
            $out= str_replace("_","/",$out);
            $decoded_msg[] = base64_decode($out);
        }

        foreach ($message_info as $info) {
            $result = null;
            if($email != $info->sender)
             {

                file_put_contents($output_log_file, "Message: From " . $info->from . "\n", FILE_APPEND);
                file_put_contents($output_log_file, "Message: Date " . $info->date . "\n", FILE_APPEND);
                file_put_contents($output_log_file, "Message: Subject " . $info->subject . "\n", FILE_APPEND);

               try {
                    //code...
                    $result = $this->getChatGPTAnswer($info->content);
                    sleep(1);
                   break;
               } catch (Throwable $th) {
                    file_put_contents($output_log_file, "error: Error at getting chatGPT answer for " . $info->content . "\n", FILE_APPEND);
               }
                
             }  
            if($result){
                $this->replayMessage($info->threadId, $info->id, $info->subject, $info->to, $info->sender, $result);
            }
        }
        return $message_info;
    }


    function replayMessage($theadId, $messageId, $subject, $from, $to,$message,){

        $service = new Google_Service_Gmail($this->client);

        $replyMessage = new Google_Service_Gmail_Message();

        $replyMessage->setThreadId($theadId);

       
        $replyMessage->setRaw(base64_encode("From: ".$from."\r\n" .
            "To: ".$to."\r\n" .
            "Subject: Re: " . $subject . "\r\n" .
            "In-Reply-To: <".$messageId.">\r\n" .
            "References: <".$messageId.">\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: quoted-printable\r\n\r\n" .
            quoted_printable_encode($message)));

        // Send the reply message
        $service->users_messages->send('me', $replyMessage);
    }


    // get chatgpt anwer using api
    function getChatGPTAnswer($question){
        echo $question;
        $output_log_file = "output_log.txt";
        

        $client = new Client([
            'base_uri' => MailRsEnv::$baseUriForOpenAI,
            'headers' => [
                'X-RapidAPI-Host' => MailRsEnv::$apiHost,
                'X-RapidAPI-Key' => MailRsEnv::$apiKey,
                'content-type' => 'application/json',
            ]
        ]);

        $data = [
            'model' => MailRsEnv::$openAiModel,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $question
                ]
            ]
        ];

        $options = [
            'json' => $data
        ];
        try {
            $response = $client->post('/chat/completions', $options);
            file_put_contents($output_log_file, "got response....\n", FILE_APPEND);
            $response_data = json_decode($response->getBody()->getContents(), true);

            if($response_data){
                if($response_data['choices']){
                    if(count($response_data['choices']) > 0){
                        if($response_data['choices'][0]["message"]['content']){
                            return $response_data['choices'][0]["message"]['content'];
                        }
                    }
                }
            }
            return null;
        } catch (Exception $e) {
            file_put_contents($output_log_file, 'Error: ' . $e->getMessage() . "\n", File_APPEND);
            return null;
        }
    }
}
?>