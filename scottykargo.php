<?php
trait scottycargo {
    public function loginScotty() {
        // Tokenin saklanandığı dosya konumu.
        $token_info_location = DIR_SYSTEM . '/storage/scotty_token.json';
        $json_file = file_get_contents($token_info_location);
        $token_data = json_decode($json_file);
        $token = null;

        // Token geçerliliği kontrolü
        if(!empty($token_data->token)) {
            $exp = $token_data->exp;
            if(strtotime(date("y-m-d H:i:s"))<$exp) {
                $token = $token_data->token;
            }
        }

        if($token==null) {
            $url = "";
            if($this->config->get('scottycargo_account_mode')=='test') {
                $url = 'https://private-anon-e7bf4813a4-scottycargo.apiary-proxy.com/api/login';
            } else if ($this->config->get('scottycargo_account_mode')=='live') {
                $url = 'https://cargo.usescotty.com/api/login';
            } 
            $send_data = array( 
                'username' => 'scottycargo kullanici adi',
                'password' => 'scottycargo sifresi',
            );
    
            $result = $this->callScottyAPI($url, $send_data, null, 'POST');    
            $token = $result['data']['token'];
            $b64_decoded_token = base64_decode($token);
            $exploded_token = explode("}",$b64_decoded_token);
            $token_data = '['.$exploded_token[0].'},'.$exploded_token[1].'}]';
            $json_decoded_token = json_decode($token_data);
            $exp = $json_decoded_token[1]->exp;
            $token_info = [
                'token' => $token,
                'exp' => $exp
            ];
            file_put_contents($token_info_location, json_encode($token_info));
        }


        return $token;
    }

    public function sendScottyCargo($order_info) {

        if($order_info['payment_code']=='cod') {
            $response = array();
            $response['errors'][''][0]['description'] = 'Kapıda ödemeli siparişlerde Scotty kullanılamaz!';

            return $response;
        }

        $telephone = !empty($order_info['telephone']) ? $order_info['telephone'] : '1111111111';

        $token = $this->loginScotty();
        $url = "";
        if($this->config->get('scottycargo_account_mode')=='test') {
            $url = 'https://private-anon-e7bf4813a4-scottycargo.apiary-proxy.com/api/packages';
        } else if ($this->config->get('scottycargo_account_mode')=='live') {
            $url = 'https://cargo.usescotty.com/api/packages';
        }
        $send_data = array(
            'barcode' => 'BARKOD ICIN ON EK YAZILABİLİR' . $order_info['order_id'],
            'volumetric_weight' => 0,
            'weight' => 0,
            'contact_person' => array(
                'name' => $order_info['shipping_firstname'] . ' ' . $order_info['shipping_lastname'],
                'phone' => $telephone
            ),
            'pickup_address' => array(
                'name' => 'İsim',
                'city' =>  'Şehir',
                'town' => 'İlçe',
                'district' => 'Mahalle',
                'address' => 'Tam adres',
            ),
            'delivery_address' => array(
                'name' =>  $order_info['shipping_firstname'] . ' ' . $order_info['shipping_lastname'],
                'city' => $order_info['shipping_zone'],
                'town' => $order_info['shipping_city'],
                'district' => ' ',
                'address' => $order_info['shipping_address_1']
            ),   
        );
 
        $result = $this->callScottyAPI($url, $send_data, $token, 'POST');    
        
        if($result['errors'] != []) {
            return $result;
        }

        if(empty($result['data']['barcode'])) {
            $response = array();
            $response['errors'][''][0]['description'] = 'Scotty Kargo\'dan barkod alınamadı. Sayfayı yenileyip tekrar deneyiniz.';
            
            return $response;
        }
            
        // Burada kargo hareketini veritabanına işleyebilirsiniz.

        return $result;     
    }

    public function getScottyPackageDetails($barcode) {
        $token = $this->loginScotty();
        $url = "";
        if($this->config->get('scottycargo_account_mode')=='test') {
            $url = 'https://private-anon-e7bf4813a4-scottycargo.apiary-proxy.com/api/packages/';
        } else if ($this->config->get('scottycargo_account_mode')=='live') {
            $url = 'https://cargo.usescotty.com/api/packages/';
        }
        $url.= $barcode;
        $result = $this->callScottyAPI($url, null, $token, 'GET');    
        return $result; // response 204
    }
    
    public function getScottyPackageMovements($barcode) {
        $token = $this->loginScotty();
        $url = "";
        if($this->config->get('scottycargo_account_mode')=='test') {
            $url = 'https://private-anon-e7bf4813a4-scottycargo.apiary-proxy.com/api/package-activities/';
        } else if ($this->config->get('scottycargo_account_mode')=='live') {
            $url = 'https://cargo.usescotty.com/api/package-activities/';
        }
        $url.= $barcode;
        $result = $this->callScottyAPI($url, null, $token, 'GET');    

        return $result; // response 204
    }

    public function cancelScottyCargo($barcode) {
        $token = $this->loginScotty();
        $url = "";
        if($this->config->get('scottycargo_account_mode')=='test') {
            $url = 'https://private-anon-e7bf4813a4-scottycargo.apiary-proxy.com/api/packages/';
        } else if ($this->config->get('scottycargo_account_mode')=='live') {
            $url = 'https://cargo.usescotty.com/api/packages/';
        }
        $url.= $barcode;

        $result = $this->callScottyAPI($url, null, $token, 'DELETE');   
        
        if($result) {
            // Burada iptal edilen paketin veritabanından silinmesi işlemi yapılabilir.
        }
        
        return $result; // response 204
    }

    public function callScottyAPI($url, $data, $token = null, $method) {
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);

        if($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, TRUE);
        } else if($method == 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        }

        if(isset($data)) {
            $json = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);            
        }

        $http_header = array();

        $http_header[] = 'Content-Type: application/json';

        // header bilgisine token eklenmesi
        if($token!=null) {
            $http_header[] = 'Authorization:Bearer ' . $token;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $http_header);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }

        //$info = curl_getinfo($ch);
        //print_r($info);
        curl_close($ch);
        $return = json_decode($result, true);
        return $return;
    }

}
