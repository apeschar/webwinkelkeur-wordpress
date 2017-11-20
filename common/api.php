<?php
require_once dirname(__FILE__) . '/vendor/Peschar/URLRetriever.php';
class WebwinkelKeurAPI {
    private $api_domain;
    private $shop_id;
    private $api_key;

    public function __construct($api_domain, $shop_id, $api_key) {
        $this->api_domain = (string)$api_domain;
        $this->shop_id = (string) $shop_id;
        $this->api_key = (string) $api_key;
    }

    public function invite($order_id, $email, $delay, $lang, $customer_name, $noremail = false) {
        $credentials = array(
            'id'   => $this->shop_id,
            'code' => $this->api_key
        );
        $post = array(
            'order'     => $order_id,
            'email'     => $email,
            'delay'     => $delay,
            'language'  => $lang,
            'client'    => 'wordpress',
            'customer_name' => $customer_name,
        );

        if($noremail)
            $post['max_invitations_per_email'] = 1;

        $url = $this->buildURL('https://dashboard.webwinkelkeur.nl/api/1.0/invitations.json', $credentials);

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
            CURLOPT_SSL_VERIFYPEER => false
        ));
        $response = curl_exec($ch);
        curl_close($ch);

        if(!$response) {
            throw new WebwinkelKeurAPIError($url, 'API not reachable.');
        }

        $result = json_decode($response);
        if (isset ($result->status) && $result->status == 'success') {
            return true;
        }
        if(preg_match('|already sent|', $result->message)) {
            throw new WebwinkelKeurAPIAlreadySentError($url, $response);
        }
        if(preg_match('|limit hit|', $result->message)) {
            throw new WebwinkelKeurAPIAlreadySentError($url, $response);
        }
        throw new WebwinkelKeurAPIError($url, $response);
    }

    private function buildURL($address, $parameters) {
        $query_string = http_build_query($parameters);
        if(strpos($address, '?') === false) {
            return $address . '?' . $query_string;
        } else {
            return $address . '&' . $query_string;
        }
    }
}

class WebwinkelKeurAPIError extends Exception {
    private $url;

    public function __construct($url, $message) {
        $this->url = $url;
        parent::__construct($message);
    }

    public function getURL() {
        return $this->url;
    }
}

class WebwinkelKeurAPIAlreadySentError extends WebwinkelKeurAPIError {}
