<?php

namespace App\Services\PaymentGateways\Fiat\PerfectMoney\Services;

use Illuminate\Support\Facades\Http;

class PerfectMoney {

    public $login;
    public $pass;
    public $account;
    public $url = 'https://perfectmoney.com/acct/';

    public function __construct() {
        $this->login = config('perfectmoney.login');
        $this->pass = config('perfectmoney.pass');
        $this->account = config('perfectmoney.account_id');
    }

    public function requestBuilder($arguments = []) {

        $args = ['AccountID' => $this->login, 'PassPhrase' => $this->pass, 'api_version' => 1];

        return http_build_query(array_merge($args, $arguments));
    }

    public function getTransactions() {
        return Http::get($this->url . 'historycsv.asp?' . $this->requestBuilder());
    }

    public function withdraw($params) {

        $args = [
            'Payer_Account' => $this->account,
            'Payee_Account' => $params['account'],
            'Amount' => $params['amount'],
            'PAY_IN' => 1,
            'PAYMENT_ID' => $params['id'],
        ];

        $response =  fopen($this->url . 'confirm.asp?' . $this->requestBuilder($args), 'rb');

        if($response===false){
            return ['success' => false, 'error' => __('Provider Error')];
        }

        $out=array(); $out="";
        while(!feof($response)) $out.=fgets($response);


        fclose($response);

        if(!preg_match_all("/<input name='(.*)' type='hidden' value='(.*)'\/>/", $out, $result, PREG_SET_ORDER)){
            return ['success' => false, 'error' => __('Provider Error')];
        }

        if($result[0][1] == "ERROR") {
            return ['success' => false, 'error' => $result[0][2]];
        }

        return ['success' => true];

    }

    public function getBalance() {

        $response =  fopen($this->url . 'balance.asp?' . $this->requestBuilder(), 'rb');

        if($response===false){
            echo 'error openning url';
        }

        $out=array(); $out="";
        while(!feof($response)) $out.=fgets($response);


        fclose($response);

        if(!preg_match_all("/<input name='(.*)' type='hidden' value='(.*)'\/>/", $out, $result, PREG_SET_ORDER)){
            echo 'Ivalid output';
            exit;
        }

        return $result[0][2];

    }
}
