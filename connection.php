<?php 
class connection{

    private  $tokenPath = 'token.json';
    public $client;
    private $credentials;
    public $is_connected = false;
    public function __construct(){
        $this->credentials = "client_secret/client_secret.json";
        $this->client = $this->create_client();
    }

    public function get_client(){
        return $this->client;
    }

    public function get_credentials(){
        return $this->credentials;
    }


    // when don't have authorized token, request for token
    public function get_unauthenticated_data(){
        $authUrl = $this->client->createAuthUrl();
        // open browser and go to auth url
        $data = `start chrome "$authUrl" --incognito`;

        $data = `start "" "$authUrl"`;

        $output=null;
        $retval=null;
        exec($data,$output,$retval);
    }

    public function credintials_in_browser()
    {
        if (isset($_GET['code'])) {
            return true;
        }
        return false;
    }

    public function is_connected()
    {
        return $this->is_connected;
    }

    // check the token about expiring
    public function isAccessTokenExpired(){
        if(!$this->getAccessToekn()){
            return true;
        }

        return false;
    }
    public function create_client()
    {
        $client = new Google_Client();
        $client->setApplicationName('MailRS');
        $client->setScopes([
            Google_Service_Gmail::GMAIL_READONLY,
            Google_Service_Gmail::GMAIL_SEND,
        ]);
        $client->setAuthConfig($this->credentials);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        // Load previously authorized token from a file, if it exists.
        // The file token.json stores the user's access and refresh tokens, and is
        // created automatically when the authorization flow completes for the first
        // time.
        if (file_exists($this->tokenPath)) {
            
            $accessToken = json_decode(file_get_contents($this->tokenPath), true);
            $client->setAccessToken($accessToken);
        }

        // If there is no previous token or it's expired.
        if ($client->isAccessTokenExpired()) {
            // Refresh the token if possible, else fetch a new one.

            if ($client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            } elseif ($this->credintials_in_browser()) {
                $authCode = $_GET['code'];

                // Exchange authorization code for an access token.
                $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
                $client->setAccessToken($accessToken);

                // Check to see if there was an error.
                if (array_key_exists('error', $accessToken)) {
                    throw new Exception(join(', ', $accessToken));
                }

            } else {
                $this->is_connected = false;
                return $client;
            }
            // Save the token to a file.
            if (!file_exists(dirname($this->tokenPath))) {
                mkdir(dirname($this->tokenPath), 0700, true);
            }
            if($client->getAccessToken())
                file_put_contents($this->tokenPath, json_encode($client->getAccessToken()));
        } 
        
        $this->is_connected = true;
        return $client;
    }

}

?>