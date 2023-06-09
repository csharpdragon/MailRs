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

        $service = new Google_Service_Gmail($this->client);

        $profile = $service->users->getProfile('me');
        $email = $profile->getEmailAddress();
        // Print the labels in the user's account.
        $userId = 'me';
        $pageToken = NULL;
        $messages = array();
        $opt_param = array();

        $endTime = new DateTime();
        $startTime = clone $endTime;
   //     $startTime->sub(new DateInterval('P1D'));
        $query = 'after:' . $startTime->format('Y/m/d');

        do {

            
            try {

                $opt_param['q'] = $query;
                if ($pageToken) {
                    $opt_param['pageToken'] = $pageToken;
                }

                $messagesResponse = $service->users_messages->listUsersMessages($userId, $opt_param);
                echo json_encode($messagesResponse);
                if ($messagesResponse->getMessages()) {
                    $messages = array_merge($messages, $messagesResponse->getMessages());
                    $pageToken = $messagesResponse->getNextPageToken();
                    if(!$pageToken){
                        break;
                    }
                }
            } catch (Exception $e) {
                print 'An error occurred: ' . $e->getMessage();
            }
        } while ($pageToken);

        
        $decoded_msg = array();
        $message_info = array();

        echo json_encode($messages);
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

                
                try {
                    //code...
                    $result = $this->getChatGPTAnswer($info->content);
                    echo $info->sender . "\n";
                    echo json_encode($result);
                    Thread.sleep(100);
                    break;
                } catch (Throwable $th) {
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
            
            return json_decode($response->getBody()->getContents(), true)['choices'][0]["message"]['content'];
            return "";
        } catch (Exception $e) {
            echo 'Error: ' . $e->getMessage();
            return null;
        }
    }
}
?>