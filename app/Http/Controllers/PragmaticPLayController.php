<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\ProviderHelper;
use DB;
use App\Helpers\Helper;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client;


class PragmaticPLayController extends Controller
{
    public $key;

    public function __construct(){
    	$this->key = config('providerlinks.tpp.secret_key');
    }

    public function authenticate(Request $request)
    {
        
        $enc_body = file_get_contents("php://input");
        parse_str($enc_body, $data);
        $json_encode = json_encode($data, true);
        $data = json_decode($json_encode);

        // $hash = md5('providerId='.$data->providerId.'&token='.$data->token.$this->key);
        $dataSort = json_decode($json_encode, true);
        $hash = $this->hashParam($dataSort);

        if($hash != $data->hash){
            $response = [
                "error" => 5,
                "decription" => "Success"
            ];
            return $response;
        }
               
        $providerId = $data->providerId;
        $hash = $data->hash;
        $token = $data->token;
        $client_details = ProviderHelper::getClientDetails('token',$token);
        
        if($client_details != null)
        {
            $player_details = Providerhelper::playerDetailsCall($client_details->player_token);
            $currency = $client_details->default_currency;
            $country = $player_details->playerdetailsresponse->country_code;
            $balance = $player_details->playerdetailsresponse->balance;
            $userid = "TGaming_".$client_details->player_id;

            $response = array(
                "userId" => $userid,
                "currency" => $currency,
                "cash" => number_format($balance, 2, '.', ''),
                "bonus" => 0.00,
                "country" => $country,
                "jurisdiction" => "99",
                "error" => 0,
                "decription" => "Success"
            );

            Helper::saveLog('PP authenticate', 49, json_encode($data) , $response);

            
        }else{
            $response = [
                "error" => 4,
                "decription" => "Success"
            ];
        }

        return $response;
    }

    public function balance(Request $request)
    {
        
        $enc_body = file_get_contents("php://input");
        parse_str($enc_body, $data);
        $json_encode = json_encode($data, true);
        $data = json_decode($json_encode);


        Helper::saveLog('PP balance', 49, json_encode($data) , "balance");
        // $hash = md5('providerId='.$data->providerId.'&userId='.$data->userId.$this->key);

        $dataSort = json_decode($json_encode, true);
        $hash = $this->hashParam($dataSort);


        if($hash != $data->hash){
            $response = [
                "error" => 5,
                "decription" => "Success"
            ];
            return $response;
        }

        $playerId = ProviderHelper::explodeUsername('_',$data->userId);
        $client_details = ProviderHelper::getClientDetails('player_id',$playerId);
        $player_details = Providerhelper::playerDetailsCall($client_details->player_token);
   
        $response = array(
            "currency" => $client_details->default_currency,
            "cash" => number_format($player_details->playerdetailsresponse->balance, 2, '.', ''),
            "bonus" => 0.00,
            "error" => 0,
            "description" => "Success"
        );

        Helper::saveLog('PP balance', 49, json_encode($data) , $response);
        
        return $response;
    }

    public function bet(Request $request)
    {
        $enc_body = file_get_contents("php://input");
        parse_str($enc_body, $data);
        $json_encode = json_encode($data, true);
        $data = json_decode($json_encode);

        $dataSort = json_decode($json_encode, true);

        $hash = $this->hashParam($dataSort);

        // $hash = md5('amount='.$data->amount.'&gameId='.$data->gameId.'&providerId='.$data->providerId.'&reference='.$data->reference.'&roundDetails='.$data->roundDetails.'&roundId='.$data->roundId.'&timestamp='.$data->timestamp.'&userId='.$data->userId.$this->key);
        
        if($hash != $data->hash){
            $response = [
                "error" => 5,
                "decription" => "Success"
            ];
            return $response;
        }

        Helper::saveLog('PP bet request', 49,json_encode($data), "");

        $playerId = ProviderHelper::explodeUsername('_',$data->userId);
        $client_details = ProviderHelper::getClientDetails('player_id',$playerId);
        $player_details = Providerhelper::playerDetailsCall($client_details->player_token);
        $game_details = Helper::findGameDetails('game_code', 26, $data->gameId);


        $client = new Client([
            'headers' => [ 
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$client_details->client_access_token
            ]
        ]);

        $tokenId = $client_details->token_id;
        $game_code = $data->gameId;
        $bet_amount = $data->amount;
        $roundId = $data->roundId;

        if($bet_amount > $player_details->playerdetailsresponse->balance){

            $response = array(
                "cash" => number_format($player_details->playerdetailsresponse->balance, 2, '.', ''),
                "error" => 1,
                "description" => "Not Enough Balance"
            );
            Helper::saveLog('PP bet not enough balance', 49,json_encode($data) , $response);
        }else{

            $checkGameTrans = DB::table('game_transactions')->where("round_id","=",$data->roundId)->get();
            if(count($checkGameTrans) > 0){

                $response = array(
                    "transactionId" => $checkGameTrans[0]->game_trans_id,
                    "currency" => $client_details->default_currency,
                    "cash" => number_format($player_details->playerdetailsresponse->balance, 2, '.', ''),
                    "bonus" => 0.00,
                    "usedPromo" => 0,
                    "error" => 0,
                    "description" => "Successs"
                );

                Helper::saveLog('PP bet duplicate', 49,json_encode($data) , $response);

            }else{

                $responseDetails = $this->responsetosend($client_details->client_access_token, $client_details->client_api_key, $game_details->game_code, $game_details->game_name, $client_details->client_player_id, $client_details->player_token, $bet_amount, $client, $client_details->fund_transfer_url, "debit",$client_details->default_currency );
                
                $gametrans = ProviderHelper::createGameTransaction($tokenId, $game_details->game_id, $bet_amount, 0.00, 1, 0, null, null, $bet_amount, $data->reference, $roundId);
        
                $response = array(
                    "transactionId" => $gametrans,
                    "currency" => $client_details->default_currency,
                    "cash" => number_format($responseDetails['client_response']->fundtransferresponse->balance, 2, '.', ''),
                    "bonus" => 0.00,
                    "usedPromo" => 0,
                    "error" => 0,
                    "description" => "Success"
                );
        
                $game_trans = DB::table('game_transactions')->where("round_id","=",$data->roundId)->get();
        
                // $response = array(
                //     "transactionId" => $game_trans[0]->game_trans_id,
                //     "currency" => $client_details->default_currency,
                //     "cash" => $clientDetalsResponse['client_response']->fundtransferresponse->balance,
                //     "bonus" => 0,
                //     "error" => 0,
                //     "description" => "Success"
                // );
        
                $trans_details = array(
                    "game_trans_id" => $game_trans[0]->game_trans_id,
                    "bet_amount" => $game_trans[0]->bet_amount,
                    "pay_amount" => $data->amount,
                    "win" => false,
                    "response" => $response 
                );
        
                $game_trans_ext = ProviderHelper::createGameTransExt( $gametrans, $game_trans[0]->provider_trans_id, $game_trans[0]->round_id, $data->amount, 1, $data, $response, $responseDetails['requesttosend'], $responseDetails['client_response'], $trans_details);
               
                Helper::saveLog('PP bet initial', 49,json_encode($data) , $response);

            }    
            
        }
        
        return $response;
    }

    public function result(Request $request)
    {
        $enc_body = file_get_contents("php://input");
        parse_str($enc_body, $data);
        $json_encode = json_encode($data, true);
        $data = json_decode($json_encode);

        
        
        Helper::saveLog('PP result request', 49, json_encode($data) ,"result");
        
        // $hash = md5('amount='.$data->amount.'&gameId='.$data->gameId.'&providerId='.$data->providerId.'&reference='.$data->reference.'&roundDetails='.$data->roundDetails.'&roundId='.$data->roundId.'&timestamp='.$data->timestamp.'&userId='.$data->userId.$this->key);
        $dataSort = json_decode($json_encode, true);
        $hash = $this->hashParam($dataSort);
        
        // if($hash != $data->hash){
        //     $response = [
        //         "error" => 5,
        //         "decription" => "Success"
        //     ];
        //     return $response;
        // }
        
        $checkGameTrans = DB::table('game_transactions')->where("round_id","=",$data->roundId)->get();

        $playerId = ProviderHelper::explodeUsername('_',$data->userId);
        $client_details = ProviderHelper::getClientDetails('player_id',$playerId);
        $player_details = Providerhelper::playerDetailsCall($client_details->player_token);
        $game_details = Helper::findGameDetails('game_code', 26, $data->gameId);
        
        $checkExt = ProviderHelper::findGameExt($data->roundId, '2', 'round_id');

        if($checkExt  != 'false'){
            $response_log = array(
                "transactionId" => $checkGameTrans[0]->game_trans_id,
                "currency" => $client_details->default_currency,
                "cash" => number_format($player_details->playerdetailsresponse->balance, 2, '.', ''),
                "bonus" => 0,
                "error" => 0,
                "description" => "Success",
            );

            return $response_log;
        }
        
        $client = new Client([
            'headers' => [ 
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$client_details->client_access_token
            ]
        ]);
        
        $responseDetails = $this->responsetosend($client_details->client_access_token, $client_details->client_api_key, $game_details->game_code, $game_details->game_name, $client_details->client_player_id, $client_details->player_token, $data->amount, $client, $client_details->fund_transfer_url, "credit",$client_details->default_currency );
        
        $game_trans = DB::table('game_transactions')->where("round_id","=",$data->roundId)->get();

        $income = $game_trans[0]->bet_amount - $data->amount;
        $win = 1;
      
        $updateGameTrans = DB::table('game_transactions')
                ->where("round_id","=",$data->roundId)
                ->update([
                    "win" => $win,
                    "pay_amount" => $data->amount,
                    "income" => $income,
                    "entry_id" => 2
                ]);

        if(isset($data->promoCampaignID)){
            $responseDetails = $this->responsetosend($client_details->client_access_token, $client_details->client_api_key, $game_details->game_code, $game_details->game_name, $client_details->client_player_id, $client_details->player_token, $data->promoWinAmount, $client, $client_details->fund_transfer_url, "credit",$client_details->default_currency );

            $gametrans = ProviderHelper::createGameTransaction($client_details->token_id, $game_details->game_id, 0.00, $data->promoWinAmount, 2, 1, null, "Promo Win (prize drop)", 0 - $data->promoWinAmount, $data->reference, $data->roundId);
        }

        $response_log = array(
            "transactionId" => $game_trans[0]->game_trans_id,
            "currency" => $client_details->default_currency,
            "cash" => $responseDetails['client_response']->fundtransferresponse->balance,
            "bonus" => 0,
            "error" => 0,
            "description" => "Success",
        );

        $trans_details = array(
            "game_trans_id" => $game_trans[0]->game_trans_id,
            "bet_amount" => $game_trans[0]->bet_amount,
            "pay_amount" => $data->amount,
            "win" => true,
            "response" => $response_log
        );

        $game_trans_ext = ProviderHelper::createGameTransExt($game_trans[0]->game_trans_id, $game_trans[0]->provider_trans_id, $game_trans[0]->round_id, $data->amount, 2, $data, $response_log, $responseDetails['requesttosend'], $responseDetails['client_response'], $trans_details);

        if(isset($data->promoCampaignID)){
            $game_trans_ext = ProviderHelper::createGameTransExt($game_trans[0]->game_trans_id, $game_trans[0]->provider_trans_id, $data->promoCampaignID, $data->promoWinAmount, 2, $data, $response_log, $responseDetails['requesttosend'], $responseDetails['client_response'], $trans_details);
        }

        $response = array(
            "transactionId" => $game_trans_ext,
            "currency" => $client_details->default_currency,
            "cash" => number_format($responseDetails['client_response']->fundtransferresponse->balance, 2, '.', ''),
            "bonus" => 0,
            "error" => 0,
            "description" => "Success",
        );

        Helper::saveLog('PP result', 49, json_encode($data) , $response);
        
        return $response;
    }

    public function endRound(Request $request)
    {
        $enc_body = file_get_contents("php://input");
        parse_str($enc_body, $data);
        $json_encode = json_encode($data, true);
        $data = json_decode($json_encode);

        Helper::saveLog('PP endRound request', 49, json_encode($data) ,"endRound");

        $hash = md5('gameId='.$data->gameId.'&platform='.$data->platform.'&providerId='.$data->providerId.'&roundId='.$data->roundId.'&userId='.$data->userId.$this->key);
        
        if($hash != $data->hash){
            $response = [
                "error" => 5,
                "decription" => "Success"
            ];
            return $response;
        }

        $playerId = ProviderHelper::explodeUsername('_',$data->userId);
        $client_details = ProviderHelper::getClientDetails('player_id',$playerId);
        $player_details = Providerhelper::playerDetailsCall($client_details->player_token);
   
        $response = array(
            "cash" => number_format($player_details->playerdetailsresponse->balance, 2, '.', ''),
            "bonus" => 0.00,
            "error" => 0,
            "description" => "Success"
        );

        return $response;
    }
    
    public function getBalancePerGame(Request $request)
    {
        $enc_body = file_get_contents("php://input");
        parse_str($enc_body, $data);
        $json_encode = json_encode($data, true);
        $data = json_decode($json_encode);
        
        Helper::saveLog('PP getBalancePerGame request', 49, json_encode($data) ,"getBalancePerGame");

        $playerId = ProviderHelper::explodeUsername('_',$data->userId);
        $client_details = ProviderHelper::getClientDetails('player_id',$playerId);
        $player_details = Providerhelper::playerDetailsCall($client_details->player_token);

       
        $response = [
            "gamesBalances" => [
                
                "gameID" => $data->gameIdList,
                "cash" => number_format($player_details->playerdetailsresponse->balance, 2, '.', ''),
                "bonus" => 0.00
                
            ]
        ];
        return $response;
    }

    public function sessionExpired(Request $request)
    {
        $enc_body = file_get_contents("php://input");
        parse_str($enc_body, $data);
        $json_encode = json_encode($data, true);
        $data = json_decode($json_encode);
        
        Helper::saveLog('PP sessionExpired request', 49, json_encode($data) ,"sessionExpired");

        $dataSort = json_decode($json_encode, true);
        $hash = $this->hashParam($dataSort);

        if($hash != $data->hash){
            $response = [
                "error" => 5,
                "decription" => "Success"
            ];
            return $response;
        }

        $response = array(
            "error" => 0,
            "description" => "Success"
        );

        Helper::saveLog('PP sessionExpired request', 49, json_encode($data) , $response);
        return $response;

    }

    public function refund(Request $request)
    {
        $enc_body = file_get_contents("php://input");
        parse_str($enc_body, $data);
        $json_encode = json_encode($data, true);
        $data = json_decode($json_encode);

        Helper::saveLog('PP refund request', 49, json_encode($data) , "");

        // $hash = md5('amount='.$data->amount.'&gameId='.$data->gameId.'&providerId='.$data->providerId.'&reference='.$data->reference.'&roundDetails='.$data->roundDetails.'&roundId='.$data->roundId.'&timestamp='.$data->timestamp.'&userId='.$data->userId.$this->key);
        $dataSort = json_decode($json_encode, true);
        $hash = $this->hashParam($dataSort);

        if($hash != $data->hash){
            $response = [
                "error" => 5,
                "decription" => "Success"
            ];
            return $response;
        }

        $game_trans = DB::table("game_transactions")->where("round_id","=",$data->roundId)->get();

        // return count($game_trans);
        if(count($game_trans) > 0){

            if($game_trans[0]->win == 4){

                $response = array(
                    "error" => 0,
                    "description" => "Success"
                );
                return $response;
            }

            $game_details = DB::table("games")->where("game_id","=",$game_trans[0]->game_id)->first();
            
            $playerId = ProviderHelper::explodeUsername('_',$data->userId);
            $client_details = ProviderHelper::getClientDetails('player_id',$playerId);
            $player_details = Providerhelper::playerDetailsCall($client_details->player_token);
            // $game_details = Helper::findGameDetails('game_code', 25, $gameDetails->game_code);
    
            $client = new Client([
                'headers' => [ 
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$client_details->client_access_token
                ]
            ]);
            
            
            $bet_amount = $game_trans[0]->bet_amount;
    
    
            $responseDetails = $this->responsetosend($client_details->client_access_token, $client_details->client_api_key, $game_details->game_code, $game_details->game_name, $client_details->client_player_id, $client_details->player_token, $bet_amount, $client, $client_details->fund_transfer_url, "credit",$client_details->default_currency, true );
    
            $refund_update = DB::table('game_transactions')->where("round_id","=",$data->roundId)->update(['win' => '4']);
                    
            //
            $response = array(
                // "transactionId" => $game_trans[0]->game_trans_id,
                "transactionId" => $game_trans[0]->game_trans_id."-".date("his"),
                "error" => 0,
                "description" => "Success"
            );
            
            $trans_details = array(
                "refund" => true,
                "bet_amount" => $bet_amount,
                "response" => $response,
            );
    
            $game_trans_ext = ProviderHelper::createGameTransExt($game_trans[0]->game_trans_id, $game_trans[0]->provider_trans_id, $game_trans[0]->round_id, $bet_amount, 3, $data, $response, $responseDetails['requesttosend'], $responseDetails['client_response'], $trans_details);
    
            Helper::saveLog('PP refund request', 49, json_encode($data) , $response);
            // $response_log = array(
            //     "transactionId" => $game_trans[0]->game_trans_id,
            //     "error" => 0,
            //     "description" => "Success"
            // );
    
            return $response;

        }else{
            $response = array(
                "error" => 0,
                "description" => "Success"
            );
            return $response;
        }

    }

    public function bonusWin(Request $request)
    {
        $enc_body = file_get_contents("php://input");
        parse_str($enc_body, $data);
        $json_encode = json_encode($data, true);
        $data = json_decode($json_encode);

        Helper::saveLog('PP bonus', 49, json_encode($data) , "");

        $game_trans = DB::table("game_transactions")->where("round_id","=",$data->roundId)->first();
        $game_details = DB::table("games")->where("game_id","=",$game_trans->game_id)->first();
        
        $playerId = ProviderHelper::explodeUsername('_',$data->userId);
        $client_details = ProviderHelper::getClientDetails('player_id',$playerId);
        $player_details = Providerhelper::playerDetailsCall($client_details->player_token);

        $client = new Client([
            'headers' => [ 
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$client_details->client_access_token
            ]
        ]);
        
    }

    public function promoWin(Request $request){

        $enc_body = file_get_contents("php://input");
        parse_str($enc_body, $data);
        $json_encode = json_encode($data, true);
        $data = json_decode($json_encode);

        Helper::saveLog('PP promoWin request', 49, json_encode($data) , "");

        $dataSort = json_decode($json_encode, true);
        $hash = "amount=$data->amount&campaignId=$data->campaignId&campaignType=$data->campaignType&currency=$data->currency&providerId=$data->providerId&reference=$data->reference&timestamp=$data->timestamp&userId=$data->userId$this->key";
        $hash = md5($hash);
        if($hash != $data->hash){
            $response = [
                "error" => 5,
                "decription" => "Success"
            ];
            return $response;
        }

        $playerId = ProviderHelper::explodeUsername('_',$data->userId);
        $client_details = ProviderHelper::getClientDetails('player_id',$playerId);
        $player_details = Providerhelper::playerDetailsCall($client_details->player_token);
        
        $client = new Client([
            'headers' => [ 
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$client_details->client_access_token
            ]
        ]);

        $game_details = Helper::findGameDetails('game_code', 26, 'vs25pyramid');
        $tokenId = $client_details->token_id;
        $roundId = $data->campaignId;
        
        $checkGameTrans = DB::table('game_transactions')->where("round_id","=",$roundId)->get();
        $checkExt = ProviderHelper::findGameExt($roundId, '2', 'round_id');
        
        if($checkExt  != 'false'){
            $response_log = array(
                "transactionId" => $checkGameTrans[0]->game_trans_id,
                "currency" => $client_details->default_currency,
                "cash" => number_format($player_details->playerdetailsresponse->balance, 2, '.', ''),
                "bonus" => 0,
                "error" => 0,
                "description" => "Success",
            );

            return $response_log;
        }

        // vs25pyramid
        // Pyramid King
        $responseDetails = $this->responsetosend($client_details->client_access_token, $client_details->client_api_key, "vs25pyramid", "Pyramid King", $client_details->client_player_id, $client_details->player_token, $data->amount, $client, $client_details->fund_transfer_url, "credit",$client_details->default_currency);

        $gametrans = ProviderHelper::createGameTransaction($tokenId, $game_details->game_id, 0.00, $data->amount, 1, 0, "Tournament", "Promo Win ", 0- $data->amount, $data->reference, $roundId);
        
        $response_log = array(
            "transactionId" => $gametrans,
            "currency" => $client_details->default_currency,
            "cash" => number_format($responseDetails['client_response']->fundtransferresponse->balance, 2, '.', ''),
            "bonus" => 0,
            "error" => 0,
            "description" => "Success",
        );

        $game_trans_ext = ProviderHelper::createGameTransExt( $gametrans, $data->reference, $roundId, $data->amount, 1, $data, $response_log, $responseDetails['requesttosend'], $responseDetails['client_response'], "Promo Win Tournament");
        
        $response = array(
            "transactionId" => $game_trans_ext,
            "currency" => $client_details->default_currency,
            "cash" => number_format($responseDetails['client_response']->fundtransferresponse->balance, 2, '.', ''),
            "bonus" => 0,
            "error" => 0,
            "description" => "Success",
        );
        Helper::saveLog('PP promoWin request', 49, json_encode($data) , $response);
        return $response;
    }

    public function jackpotWin(Request $request){
        $enc_body = file_get_contents("php://input");
        parse_str($enc_body, $data);
        $json_encode = json_encode($data, true);
        $data = json_decode($json_encode);

        Helper::saveLog('PP jackpotWin request', 49, json_encode($data) , "");

        $dataSort = json_decode($json_encode, true);
        $hash = $this->hashParam($dataSort);
        if($hash != $data->hash){
            $response = [
                "error" => 5,
                "decription" => "Success"
            ];
            return $response;
        }else{
            return "lahos";
        }

        $game_trans = DB::table("game_transactions")->where("round_id","=",$data->roundId)->first();
        $game_details = DB::table("games")->where("game_id","=",$game_trans->game_id)->first();
        
        $playerId = ProviderHelper::explodeUsername('_',$data->userId);
        $client_details = ProviderHelper::getClientDetails('player_id',$playerId);
        $player_details = Providerhelper::playerDetailsCall($client_details->player_token);

        $client = new Client([
            'headers' => [ 
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$client_details->client_access_token
            ]
        ]);

        $responseDetails = $this->responsetosend($client_details->client_access_token,          $client_details->client_api_key, $game_details->game_code, $game_details->game_name, $client_details->client_player_id, $client_details->player_token, $data->amount, $client, $client_details->fund_transfer_url, "credit", $client_details->default_currency );

        $game_trans = DB::table('game_transactions')->where("round_id","=",$data->roundId)->get();

        $income = $game_trans[0]->bet_amount - $data->amount;
        $win = 1;
        
        $updateGameTrans = DB::table('game_transactions')
            ->where("round_id","=",$data->roundId)
            ->update([
                "win" => $win,
                "pay_amount" => $data->amount,
                "income" => $income,
                "entry_id" => 2,
                "payout_reason" => "Jackpot Win"
            ]);
    
        $response = array(
            "transactionId" => $game_trans[0]->game_trans_id,
            "currency" => $client_details->default_currency,
            "cash" => number_format($responseDetails['client_response']->fundtransferresponse->balance, 2, '.', ''),
            "bonus" => 0,
            "error" => 0,
            "description" => "Success",
        );

        $trans_details = array(
            "game_trans_id" => $game_trans[0]->game_trans_id,
            "bet_amount" => $game_trans[0]->bet_amount,
            "pay_amount" => $data->amount,
            "win" => true,
            "response" => $response
        );

        $game_trans_ext = ProviderHelper::createGameTransExt($game_trans[0]->game_trans_id, $game_trans[0]->provider_trans_id, $game_trans[0]->round_id, $data->amount, 2, $data, $response, $responseDetails['requesttosend'], $responseDetails['client_response'], $trans_details);

        return $response;

    }

   

    public function responsetosend($client_access_token,$client_api_key,$game_code,$game_name,$client_player_id,$player_token,$amount,$client,$fund_transfer_url,$transtype,$currency,$rollback=false){
        $requesttosend = [
            "access_token" => $client_access_token,
            "hashkey" => md5($client_api_key.$client_access_token),
            "type" => "fundtransferrequest",
            "datesent" => Helper::datesent(),
            "gamedetails" => [
            "gameid" => $game_code, // $game_code
            "gamename" => $game_name
            ],
            "fundtransferrequest" => [
                "playerinfo" => [
                "client_player_id" => $client_player_id,
                "token" => $player_token,
                ],
                "fundinfo" => [
                        "gamesessionid" => "",
                        "transactiontype" => $transtype,
                        "transferid" => "",
                        "rollback" => $rollback,
                        "currencycode" => $currency,
                        "amount" => $amount
                ],
            ],
        ];
        
        $guzzle_response = $client->post($fund_transfer_url,
            ['body' => json_encode($requesttosend)]
        );

        $client_response = json_decode($guzzle_response->getBody()->getContents());
        $data = [
            'requesttosend' => $requesttosend,
            'client_response' => $client_response,
        ];
        return $data;
    }
    

    public function hashParam($sortData){
        ksort($sortData);
        $param = "";
        $i = 0;
        foreach($sortData as $key => $item){
            if($key != 'hash'){
                if($i == 0){
                    $param .= $key ."=". $item;
                }else{
                    $param .= "&amp;".$key ."=". $item;
                }
                $i++;
            }
        }
        return $param.$this->key;
        return $hash = md5($param.$this->key);
    }

}
