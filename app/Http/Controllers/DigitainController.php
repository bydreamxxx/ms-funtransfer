<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\GameRound;
use App\Helpers\GameTransaction;
use App\Helpers\Helper;
use App\Helpers\ProviderHelper;
use App\Helpers\ClientRequestHelper;
use App\Helpers\CallParameters;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client;
use Carbon\Carbon;

use DB;

/**
 *  UPDATED 06-27-20
 *	Api Documentation v3 -> v3.7.0-1
 *	Current State : v3 updating to v3.7.0-1 
 *  @author's NOTE: You cannot win if you dont bet! Bet comes first fellows!
 *	@author's NOTE: roundId is intentionally PREFIXED with RSG to separate from other roundid, safety first!
 *	@method refund method additionals = requests: holdEarlyRefund
 *	@method win method additionals = requests:  returnBetsAmount, bonusTicketId
 *	@method bet method additionals = requests:  checkRefunded, bonusTicketId
 *	@method betwin method additionals = requests:  bonusTicketId,   ,response: playerId, roundId, currencyId
 *	
 */
class DigitainController extends Controller
{
    private $digitain_key = "BetRNK3184223";
    private $operator_id = 'B9EC7C0A';
    private $provider_db_id = 14;
    private $provider_and_sub_name = 'Digitain'; // nothing todo with the provider


    /**
	 *	Verify Signature
	 *	@return  [Bolean = True/False]
	 *
	 */
	public function authMethod($operatorId, $timestamp, $signature){
		$digitain_key = $this->digitain_key;
	    $operator_id = $operatorId;
	    $time_stamp = $timestamp;
	    $message = $time_stamp.$operator_id;
	    $hmac = hash_hmac("sha256", $message, $digitain_key);
		$result = false;
            if($hmac == $signature) {
			    $result = true;
            }
        return $result;
	}

	public function formatBalance($balance){
		return floatval($balance);
	}

	/**
	 *	Create Signature
	 *	@return  [String]
	 *
	 */
	public function createSignature($timestamp){
	    $digitain_key = $this->digitain_key;
	    $operator_id = $this->operator_id;
	    $time_stamp = $timestamp;
	    $message = $time_stamp.$operator_id;
	    $hmac = hash_hmac("sha256", $message, $digitain_key);
	    return $hmac;
	}

	public function noBody(){
		return $response = [
			"timestamp" => date('YmdHisms'),
			"signature" => $this->createSignature(date('YmdHisms')),
			"errorCode" => 17 //RequestParameterMissing
		];
	}

	public function authError(){
		return $response = ["timestamp" => date('YmdHisms'),"signature" => $this->createSignature(date('YmdHisms')),"errorCode" => 12];
	}
	
	public function wrongOperatorID(){
		return $response = ["timestamp" => date('YmdHisms'),"signature" => $this->createSignature(date('YmdHisms')),"errorCode" => 15];
	}

	public function array_has_dupes($array) {
	   return count($array) !== count(array_unique($array));
	}

	/**
	 * Player Detail Request
	 * @return array [Client Player Data]
	 * 
	 */
    public function authenticate(Request $request)
    {	
		$json_data = json_decode(file_get_contents("php://input"), true);
		Helper::saveLog('RSG authenticate - EH', $this->provider_db_id, file_get_contents("php://input"), 'ENDPOINT HIT');
		if($json_data == null){
			return $this->noBody();
		}
		if($json_data['operatorId'] != $this->operator_id){
			return $this->wrongOperatorID();
		}
		if (!$this->authMethod($json_data['operatorId'], $json_data['timestamp'], $json_data['signature'])){
			return $this->authError();
		}
		$client_details = ProviderHelper::getClientDetails('token', $json_data["token"]);	
		if ($client_details == null){
			$response = [
				"timestamp" => date('YmdHisms'),
				"signature" => $this->createSignature(date('YmdHisms')),
				// "token" => $json_data['token'],
				"errorCode" => 2 // SessionNotFound
			];
			Helper::saveLog('RSG authenticate', $this->provider_db_id, file_get_contents("php://input"), $response);
			return $response;
		}
		$token_check = Helper::tokenCheck($json_data["token"]);
		if($token_check != true){
			$response = [
				"timestamp" => date('YmdHisms'),
				"signature" => $this->createSignature(date('YmdHisms')),
				"errorCode" => 3 // SessionExpired!
			];
			Helper::saveLog('RSG authenticate', $this->provider_db_id, file_get_contents("php://input"), $response);
			return $response;
		}
		$client_response = ProviderHelper::playerDetailsCall($json_data["token"]);
		if($client_response == 'false'){
			$response = [
				"timestamp" => date('YmdHisms'),
				"signature" => $this->createSignature(date('YmdHisms')),
				"errorCode" => 999, // client cannot be reached! http errors etc!
			];
			Helper::saveLog('RSG authenticate', $this->provider_db_id, file_get_contents("php://input"), $response);
			return $response;
		}
		if(isset($client_response->playerdetailsresponse->status->code) &&
			     $client_response->playerdetailsresponse->status->code == "200"){

			$dob = isset($client_response->playerdetailsresponse->birthday) ? $client_response->playerdetailsresponse->birthday : '1996-03-01 00:00:00.000';
			$gender_pref = isset($client_response->playerdetailsresponse->gender) ? strtolower($client_response->playerdetailsresponse->gender) : 'male';
			$gender = ['male' => 1,'female' => 2];

			$response = [
				"timestamp" => date('YmdHisms'),
				"signature" => $this->createSignature(date('YmdHisms')),
				"errorCode" => 1,
				"playerId" => $client_details->player_id, // Player ID Here is Player ID in The MW DB, not the client!
				"userName" => $client_response->playerdetailsresponse->accountname,
				// "currencyId" => $client_response->playerdetailsresponse->currencycode,
				"currencyId" => $client_details->default_currency,
				"balance" => $this->formatBalance($client_response->playerdetailsresponse->balance),
				"birthDate" => $dob, // Optional
				"firstName" => $client_response->playerdetailsresponse->firstname, // required
				"lastName" => $client_response->playerdetailsresponse->lastname, // required
				"gender" => $gender[$gender_pref], // Optional
				"email" => $client_response->playerdetailsresponse->email,
				"isReal" => true
			];
		}
		Helper::saveLog('RSG authenticate - SUCCESS', $this->provider_db_id, file_get_contents("php://input"), $response);
		return $response;
	}

	/**
	 * Get the player balance
	 * @author's NOTE [Error codes, 12 = Invalid Signature, 16 = invalid currency type, 999 = general error (HTTP)]
	 * @return  [<json>]
	 * 
	 */
	public function getBalance()
	{
		$json_data = json_decode(file_get_contents("php://input"), true);
		Helper::saveLog('RSG getBalance - EH', $this->provider_db_id, file_get_contents("php://input"), 'ENDPOINT HIT');
		if($json_data == null){
			return $this->noBody();
		}
		if($json_data['operatorId'] != $this->operator_id){ //Wrong Operator Id 
			return $this->wrongOperatorID();
		}
		if(!$this->authMethod($json_data['operatorId'], $json_data['timestamp'], $json_data['signature'])){
			return $this->authError();
		}
		$client_details = ProviderHelper::getClientDetails('token', $json_data["token"]);	
		if ($client_details == null){
			$response = [
				"timestamp" => date('YmdHisms'),
				"signature" => $this->createSignature(date('YmdHisms')),
				"errorCode" => 2 // SessionNotFound
			];
			Helper::saveLog('RSG getBalance', $this->provider_db_id, file_get_contents("php://input"), $response);
			return $response;
		}
		$token_check = Helper::tokenCheck($json_data["token"]);
		if($token_check != true){
			$response = [
				"timestamp" => date('YmdHisms'),
				"signature" => $this->createSignature(date('YmdHisms')),
				"errorCode" => 3 // Token is expired!
			];
			Helper::saveLog('RSG getBalance', $this->provider_db_id, file_get_contents("php://input"), $response);
			return $response;
		}
		$client_response = ProviderHelper::playerDetailsCall($json_data["token"]);
		if($client_response == 'false'){
			$response = [
				"timestamp" => date('YmdHisms'),
				"signature" => $this->createSignature(date('YmdHisms')),
				"errorCode" => 999, // client cannot be reached! http errors etc!
			];
			return $response;
		}
		if($client_details->player_id != $json_data["playerId"]){
			$response = [
				"timestamp" => date('YmdHisms'),
				"signature" => $this->createSignature(date('YmdHisms')),
				"errorCode" => 4, // client cannot be reached! http errors etc!
			];
			return $response;
		}
		if($json_data["currencyId"] == $client_details->default_currency):
			$response = [
				"timestamp" => date('YmdHisms'),
				"signature" => $this->createSignature(date('YmdHisms')),
				"errorCode" => 1,
				"balance" => $this->formatBalance($client_response->playerdetailsresponse->balance),	
			];
		else:
			$response = [
				"timestamp" => date('YmdHisms'),
				"signature" => $this->createSignature(date('YmdHisms')),
				"token" => $json_data['token'],
				"errorCode" => 16, // Error Currency type
			];
		endif;
		return $response;
	}


	/**
	 * Call if Digitain wants a new token!
	 * @author's NOTE [Error codes, 12 = Invalid Signature, 999 = general error (HTTP)]
	 * @return  [<json>]
	 * 
	 */
	public function refreshtoken(){
		Helper::saveLog('RSG refreshtoken - EH', $this->provider_db_id, file_get_contents("php://input"), 'ENDPOINT HIT');
		$json_data = json_decode(file_get_contents("php://input"), true);
		if($json_data == null){ //Wrong Operator Id 
			return $this->noBody();
		}
		if($json_data['operatorId'] != $this->operator_id){ //Wrong Operator Id 
			return $this->wrongOperatorID();
		}
		if(!$this->authMethod($json_data['operatorId'], $json_data['timestamp'], $json_data['signature'])){ 
			return $this->authError();
		}
		$client_details = ProviderHelper::getClientDetails('token', $json_data["token"]);	
		if ($client_details == null){ // SessionNotFound
			$response = ["timestamp" => date('YmdHisms'),"signature" => $this->createSignature(date('YmdHisms')),"errorCode" => 2];
			Helper::saveLog('RSG refreshtoken', $this->provider_db_id, file_get_contents("php://input"), $response);
			return $response;
		}
		$token_check = Helper::tokenCheck($json_data["token"]);
		if($token_check != true){ // SessionExpired!
			$response = ["timestamp" => date('YmdHisms'),"signature" => $this->createSignature(date('YmdHisms')),"errorCode" => 3];
			Helper::saveLog('RSG refreshtoken', $this->provider_db_id, file_get_contents("php://input"), $response);
			return $response;
		}
		if($json_data['changeToken']): // IF TRUE REQUEST ADD NEW TOKEN
			$client_response = ProviderHelper::playerDetailsCall($json_data["token"], true);
			if($client_response):
				$game_details = Helper::getInfoPlayerGameRound($json_data["token"]);
				Helper::savePLayerGameRound($game_details->game_code, $client_response->playerdetailsresponse->refreshtoken, $this->provider_and_sub_name);

				DB::table('player_session_tokens')->insert(
	                        array('player_id' => $client_details->player_id, 
	                        	  'player_token' =>  $client_response->playerdetailsresponse->refreshtoken, 
	                        	  'status_id' => '1')
	            );
				$response = [
					"timestamp" => date('YmdHisms'),
					"signature" => $this->createSignature(date('YmdHisms')),
					"token" => $client_response->playerdetailsresponse->refreshtoken, // Return New Token!
					"errorCode" => 1
				];
			else:
				$response = [
					"timestamp" => date('YmdHisms'),
					"signature" => $this->createSignature(date('YmdHisms')),
					// "token" => $json_data['token'],
					"errorCode" => 999,
				];
			endif;
	 	else:
	 		$response = [
				"timestamp" => date('YmdHisms'),
				"signature" => $this->createSignature(date('YmdHisms')),
				"token" => $json_data['token'], // Return OLD Token
				"errorCode" => 1
			];
 		endif;
 		Helper::saveLog('RSG refreshtoken', $this->provider_db_id, file_get_contents("php://input"), $response);
 		return $response;

	}

	/**
	 * @author's NOTE:
	 * allOrNone - When True, if any of the items fail, the Partner should reject all items NO LOGIC YET!
	 * checkRefunded - no logic yet
	 * ignoreExpiry - no logic yet, expiry should be handle in the refreshToken call
	 * changeBalance - no yet implemented always true (RSG SIDE)
	 * UPDATE 4 filters - Player Low Balance, Currency code dont match, already exist, The playerId was not found
	 * @author's NOTE [Error codes, 12 = Invalid Signature, 6 = Player Low Balance!, 16 = Currency code dont match, 999 = general error (HTTP), 8 = already exist, 4 = The playerId was not found]
	 * 
	 */
	 public function bet(Request $request){
	 	Helper::saveLog('RSG bet - EH', $this->provider_db_id, file_get_contents("php://input"), 'ENDPOINT HIT');
		$json_data = json_decode(file_get_contents("php://input"), true);
		$global_error = 1;

		if($json_data == null){
			return $this->noBody();
		}
		if($json_data['operatorId'] != $this->operator_id){ //Wrong Operator Id 
			return $this->wrongOperatorID();
		}
		if(!$this->authMethod($json_data['operatorId'], $json_data['timestamp'], $json_data['signature'])){ 
			return $this->authError();
		}
		
		$items_allOrNone = array(); // ITEMS TO ROLLBACK IF ONE OF THE ITEMS FAILED!
		$items_array = array(); // ITEMS INFO
		$all_bets_amount = array();
		$duplicate_txid_request = array();

		$global_error = 1;
		$error_encounter = 0;
		# All or none is true

		# Missing Parameters
		if(!isset($json_data['providerId']) || !isset($json_data['allOrNone']) || !isset($json_data['signature']) || !isset($json_data['timestamp']) || !isset($json_data['operatorId']) || !isset($json_data['items'])){
			$response = array(
					 "timestamp" => date('YmdHisms'),
				     "signature" => $this->createSignature(date('YmdHisms')),
					 "errorCode" => 17,
					 "items" => $items_array,
   			);	
			return $response;
		}

		$total_bets = array_sum($all_bets_amount);
		$isset_allbets_amount = 0;
		foreach ($json_data['items'] as $key) { // FOREACH CHECK
		 		if($json_data['allOrNone'] == 'true'){ // IF ANY ITEM FAILED DONT PROCESS IT
		 			# Missing Parameters
					if(!isset($key['info']) || !isset($key['txId']) || !isset($key['betAmount']) || !isset($key['token']) || !isset($key['playerId']) || !isset($key['roundId']) || !isset($key['gameId'])){
						$global_error = 17;
						$error_encounter = 1;
						continue;
					}
					if($isset_allbets_amount == 0){ # Calculate all total bets
						foreach ($json_data['items'] as $key) {
							array_push($all_bets_amount, $key['betAmount']);
							array_push($duplicate_txid_request, $key['txId']);  // Checking for same txId in the call
						}
						$isset_allbets_amount = 1;
					}
		 			$game_details = Helper::findGameDetails('game_code', $this->provider_db_id, $key["gameId"]);
					if($game_details == null){ // Game not found
						$global_error = 11;
						$error_encounter= 1;
						continue;
					}
					$client_details = ProviderHelper::getClientDetails('token', $key["token"]);	
					if ($client_details == null){ // SessionNotFound
						$global_error = 2;
						$error_encounter= 1;
						continue;
					}
					if($client_details != null){ // Wrong Player ID
						if($client_details->player_id != $key["playerId"]){
							$global_error = 4;
							$error_encounter= 1;
							continue;
						}
						if($key['currencyId'] != $client_details->default_currency){
							$global_error = 16;
							$error_encounter= 1; 
							continue;
						}
						$client_player = ProviderHelper::playerDetailsCall($key["token"]);
						if($client_player == 'false'){ // client cannot be reached! http errors etc!
							$global_error = 999;
							$error_encounter = 1; 
							continue;
						}
						if($client_player->playerdetailsresponse->balance < $total_bets){
							$global_error = 6; 
							$error_encounter = 1; 
							continue;
						}
						if($key['ignoreExpiry'] != 'false'){
							$token_check = Helper::tokenCheck($key["token"]);
							if($token_check != true){ // Token is expired!
								$global_error = 3; 
								$error_encounter= 1;
								continue;
							}
			 			}
					}
					// $check_win_exist = $this->findGameTransaction($key['txId']);
					$check_win_exist = ProviderHelper::findGameExt($key['txId'], 1,'transaction_id');
					if($check_win_exist != 'false'){ // Bet Exist!
						$global_error = 8;
						$error_encounter = 1;
						continue;
					} 
					if($this->array_has_dupes($duplicate_txid_request)){
						$global_error = 8; // Duplicate TxId in the call
						$error_encounter = 1;
						continue;
					}
				} // END ALL OR NON
		} // END FOREACH CHECK
		if($error_encounter != 0){ // ELSE PROCEED TO CLIENT TRANSFERING
			$response = array(
					 "timestamp" => date('YmdHisms'),
				     "signature" => $this->createSignature(date('YmdHisms')),
					 "errorCode" => $global_error,
					 "items" => $items_array,
   			);	
			return $response;
		}

		// ALL GOOD
		$items_array = array(); // ITEMS INFO
		foreach ($json_data['items'] as $key){
			# Missing Parameters
			if(!isset($key['info']) || !isset($key['txId']) || !isset($key['betAmount']) || !isset($key['token']) || !isset($key['playerId']) || !isset($key['roundId']) || !isset($key['gameId'])){
				$items_array[] = [
					"info" => $key['info'], 
					"errorCode" => 17, 
					"metadata" => isset($key['metadata']) ? $key['metadata'] : '' 
        	    ]; 
				continue;
			}
			$game_details = Helper::findGameDetails('game_code', $this->provider_db_id, $key["gameId"]);
			if($game_details == null){ // Game not found
				$items_array[] = [
					 "info" => $key['info'], 
					 "errorCode" => 11, 
					 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' 
        	    ]; 
        	    continue;
			}
			$client_details = ProviderHelper::getClientDetails('token', $key["token"]);	
			if($client_details == null){ // SessionNotFound
				$items_array[] = [
					 "info" => $key['info'], 
					 "errorCode" => 2, 
					 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' 
        	    ];  
				continue;
			}
			if($client_details != null){ // SessionNotFound
				if($client_details->player_id != $key["playerId"]){
					$items_array[] = [
						 "info" => $key['info'], 
						 "errorCode" => 4, 
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' 
	        	    ];  
	        	    continue;
				}
				$client_player = ProviderHelper::playerDetailsCall($key["token"]);
				if($client_player == 'false'){ 
					$items_array[] = [
						 "info" => $key['info'], 
						 "errorCode" => 999, 
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' 
	        	    ];   
					continue;
				}
				if($key['currencyId'] != $client_details->default_currency){
	        		$items_array[] = [
						 "info" => $key['info'], 
						 "errorCode" => 16, 
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
	        	    ];   
	        	    continue;
				}
				if($client_player->playerdetailsresponse->balance < $key['betAmount']){
			        $items_array[] = array(
						 "info" => $key['info'], 
						 "errorCode" => 6, 
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
		   			);
		   			continue;
				}
				if($key['ignoreExpiry'] != 'false'){
			 		$token_check = Helper::tokenCheck($key["token"]);
					if($token_check != true){
						$items_array[] = array(
							 "info" => $key['info'], 
							 "errorCode" => 3, 
							 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
			   			);
						continue;
					}
				}
			}
			$check_win_exist = ProviderHelper::findGameExt($key['txId'], 1,'transaction_id');
			if($check_win_exist != 'false'){
				$items_array[] = [
					 "info" => $key['info'],
					 "errorCode" => 8,
					 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' 
        	    ];  
        	    continue;
			} 

			$operation_type = isset($key['operationType']) ? $key['operationType'] : 1;
	 		$payout_reason = 'Bet : '.$this->getOperationType($operation_type);
	 		$win_or_lost = 5; // 0 Lost, 1 win, 3 draw, 4 refund, 5 processing
	 		$method = 1; 
	 	    $token_id = $client_details->token_id;
	 	    if(isset($key['roundId'])){
	 	    	$round_id = $key['roundId'];
	 	    }else{
	 	    	$round_id = 'RSGNOROUNDID';
	 	    }
	 	    if(isset($key['txId'])){
	 	    	$provider_trans_id = $key['txId'];
	 	    }else{
	 	    	$provider_trans_id = 'RSGNOTXID';
	 	    }
	 	    $game_details = Helper::findGameDetails('game_code', $this->provider_db_id, $key['gameId']);	
	 	    $bet_payout = 0; // Bet always 0 payout!
	 	    $income = $key['betAmount'] - $bet_payout;

	 		$game_trans = ProviderHelper::createGameTransaction($token_id, $game_details->game_id, $key['betAmount'],  $bet_payout, $method, $win_or_lost, null, $payout_reason, $income, $provider_trans_id, $round_id);

	   		$game_transextension = ProviderHelper::createGameTransExtV2($game_trans, $provider_trans_id, $round_id, abs($key['betAmount']), 1);

			try {
				 $client_response = ClientRequestHelper::fundTransfer($client_details,abs($key['betAmount']),$game_details->game_code,$game_details->game_name,$game_transextension,$game_trans,'debit');
				 Helper::saveLog('RSG bet CRID = '.$game_trans, $this->provider_db_id, file_get_contents("php://input"), $client_response);
			} catch (\Exception $e) {
				$items_array[] = array(
					 "info" => $key['info'], 
					 "errorCode" => 999, 
					 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
	   			);
				ProviderHelper::updatecreateGameTransExt($game_transextension, 'FAILED', $json_data, 'FAILED', $e->getMessage(), 'FAILED', 'FAILED');
				Helper::saveLog('RSG bet - FATAL ERROR', $this->provider_db_id, json_encode($items_array), Helper::datesent());
	   			continue;
			}

			if(isset($client_response->fundtransferresponse->status->code) 
	            && $client_response->fundtransferresponse->status->code == "200"){
				$items_array[] = [
	    	    	 "externalTxId" => $game_trans, // MW Game Transaction Id
					 "balance" => floatval($client_response->fundtransferresponse->balance),
					 "info" => $key['info'], // Info from RSG, MW Should Return it back!
					 "errorCode" => 1, // No Problem
					 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	    	    ];  
	    	    ProviderHelper::updatecreateGameTransExt($game_transextension,  $json_data, $items_array, $client_response->requestoclient, $client_response, 'SUCCESS', 'NO DATA');
	    	    continue;
			}elseif(isset($client_response->fundtransferresponse->status->code) 
	            && $client_response->fundtransferresponse->status->code == "402"){
				$items_array[] = array(
					 "info" => $key['info'], 
					 "errorCode" => 6, 
					 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
	   			);
	   			continue;
			}else{ // Unknown Response Code
				$items_array[] = array(
					 "info" => $key['info'], 
					 "errorCode" => 999, 
					 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
	   			);
				ProviderHelper::updatecreateGameTransExt($game_transextension, 'FAILED', $items_array, 'FAILED', $client_response, 'FAILED', 'FAILED');
				Helper::saveLog('RSG bet - FATAL ERROR', $this->provider_db_id, $items_array, Helper::datesent());
	   			continue;
			}   

		} // END FOREACH

		$response = array(
			 "timestamp" => date('YmdHisms'),
		     "signature" => $this->createSignature(date('YmdHisms')),
			 "errorCode" => 1,
			 "items" => $items_array,
		);	
		Helper::saveLog('RSG bet - SUCCESS', $this->provider_db_id, file_get_contents("php://input"), $response);
		return $response;
	}



	/**
	 *	
	 * @author's NOTE
	 * @author's NOTE [Error codes, 12 = Invalid Signature, 999 = general error (HTTP), 8 = already exist, 16 = error currency code]	
	 * if incorrect playerId ,incorrect gameId,incorrect roundId,incorrect betTxId, should be return errorCode 7
	 *
	 */
	public function win(Request $request){
		Helper::saveLog('RSG win - EH', $this->provider_db_id, file_get_contents("php://input"), 'ENDPOINT HIT');
		$json_data = json_decode(file_get_contents("php://input"), true);
		if($json_data == null){
			return $this->noBody();
		}
		if($json_data['operatorId'] != $this->operator_id){
			return $this->wrongOperatorID();
		}
		if (!$this->authMethod($json_data['operatorId'], $json_data['timestamp'], $json_data['signature'])){
			return $this->authError();
		}

		// # 1 CHECKER 
		$items_allOrNone = array(); // ITEMS TO ROLLBACK IF ONE OF THE ITEMS FAILED!
		$items_array = array(); // ITEMS INFO
		$all_wins_amount = array();
		$duplicate_txid_request = array();

		# Missing Parameters
		if(!isset($json_data['providerId']) || !isset($json_data['allOrNone']) || !isset($json_data['signature']) || !isset($json_data['timestamp']) || !isset($json_data['operatorId']) || !isset($json_data['items'])){
			$response = array(
					 "timestamp" => date('YmdHisms'),
				     "signature" => $this->createSignature(date('YmdHisms')),
					 "errorCode" => 17,
					 "items" => $items_array,
   			);	
			return $response;
		}

		# All or none is true
		$error_encounter = 0;
	    $datatrans_status = true;
	    $global_error = 1;
	    $isset_allwins_amount = 0;
		foreach ($json_data['items'] as $key) { // FOREACH CHECK
		 		if($json_data['allOrNone'] == 'true'){ // IF ANY ITEM FAILED DONT PROCESS IT
		 			if(!isset($key['info'])  || !isset($key['winAmount']) || !isset($key['playerId']) || !isset($key['roundId']) || !isset($key['gameId'])){
						$global_error = 17;
						$error_encounter = 1;
						continue;
					}
		 			$game_details = Helper::findGameDetails('game_code', $this->provider_db_id, $key["gameId"]);
					if($game_details == null && $error_encounter == 0){ // Game not found
						$global_error = 11;
						$error_encounter= 1;
						continue;
					}
					if($isset_allwins_amount == 0 ){
						foreach ($json_data['items'] as $key) {
							array_push($all_wins_amount, $key['winAmount']);
							array_push($duplicate_txid_request, $key['txId']);  // Checking for same txId in the call
						} # 1 CHECKER
						$isset_allwins_amount = 1;
					}

					if(isset($key['betTxId']) && $key['betTxId'] != ''){// if both playerid and roundid is missing
	 				    $client_details = ProviderHelper::getClientDetails('player_id', $key['playerId']);
	 				    if($client_details == 'false'){
	 				    	$global_error = 2;
							$error_encounter= 1;
							continue;
	 				    }
			 		 	$datatrans = $this->findTransactionRefund($key['betTxId'], 'transaction_id');
			 		 	$transaction_identifier = $key['betTxId'];
	 					$transaction_identifier_type = 'provider_trans_id';
			 		 	if(!$datatrans): // Transaction Not Found!
			 				$global_error = 7;
							$error_encounter= 1;
							continue;	
			 			endif;
			 		}else{ // use originalTxid instead
			 			$datatrans = $this->findTransactionRefund($key['roundId'], 'round_id');
			 			$transaction_identifier = $key['roundId'];
	 					$transaction_identifier_type = 'round_id';
			 			if(!$datatrans): // Transaction Not Found!
			 				$global_error = 7;
							$error_encounter= 1;
							continue;	
			 			else:
			 				$jsonify = json_decode($datatrans->provider_request, true);
			 			    $client_details = ProviderHelper::getClientDetails('player_id', $jsonify['items'][0]['playerId']);
			 			endif;
			 		}
					if ($client_details == null){ // SessionNotFound
						$global_error = 2;
						$error_encounter= 1;
						continue;
					}
					if($client_details != null){ // Wrong Player ID
						if($client_details->player_id != $key["playerId"]){
							$global_error = 4;
							$error_encounter= 1;
							continue;
						}
						if($key['currencyId'] != $client_details->default_currency){
							$global_error = 16;
							$error_encounter= 1; 
							continue;
						}
						$client_player = ProviderHelper::playerDetailsCall($client_details->player_token);
						if($client_player == 'false'){ // client cannot be reached! http errors etc!
							$response = array(
								 "timestamp" => date('YmdHisms'),
							     "signature" => $this->createSignature(date('YmdHisms')),
								 "errorCode" => 999,
								 "items" => $items_array,
				   			);	
							return $response;
						}
					}
					$check_win_exist = $this->gameTransactionEXTLog('provider_trans_id',$key['txId'], 2); 
		 			if($check_win_exist != false){
		 				$global_error = 8; // Duplicate TxId in the call
						$error_encounter = 1;
		        	    continue;
		 			}
					if($this->array_has_dupes($duplicate_txid_request)){
						$global_error = 8; // Duplicate TxId in the call
						$error_encounter = 1;
						continue;
					}
				} // END ALL OR NON
		} // END FOREACH CHECK
		if($error_encounter != 0){ // ELSE PROCEED TO CLIENT TRANSFERING
			$response = array(
					 "timestamp" => date('YmdHisms'),
				     "signature" => $this->createSignature(date('YmdHisms')),
					 "errorCode" => $global_error,
					 "items" => $items_array,
   			);	
			return $response;
		}

		// return $items_array;
		// ALL GOOD
		$items_array = array(); // ITEMS INFO
		foreach ($json_data['items'] as $key){
				if(!isset($key['info']) || !isset($key['winAmount']) || !isset($key['playerId']) || !isset($key['roundId']) || !isset($key['gameId'])){
					$items_array[] = [
						 "info" => $key['info'], // Info from RSG, MW Should Return it back!
						 "errorCode" => 17, //The playerId was not found
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ];  
					continue;
				}
				// if they dont send betxid the send roundid
 				if(isset($key['betTxId']) && $key['betTxId'] != ''){// if both playerid and roundid is missing
 				    $client_details = ProviderHelper::getClientDetails('player_id', $key['playerId']);
 				    if($client_details == 'false'){
 				    	$items_array[] = [
							 "info" => $key['info'], // Info from RSG, MW Should Return it back!
							 "errorCode" => 2,
							 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
		        	    ];  
						continue;
 				    }
		 		 	$datatrans = $this->findTransactionRefund($key['betTxId'], 'transaction_id');
		 		 	$transaction_identifier = $key['betTxId'];
 					$transaction_identifier_type = 'provider_trans_id';
		 		 	if(!$datatrans): // Transaction Not Found!
		 				$items_array[] = [
							 "info" => $key['info'], 
							 "errorCode" => 7, 
							 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' 
		        	    ];  
		        	    continue;	
		 			endif;
		 		}else{ // use originalTxid instead
		 			$datatrans = $this->findTransactionRefund($key['roundId'], 'round_id');
		 			$transaction_identifier = $key['roundId'];
 					$transaction_identifier_type = 'round_id';
		 			if(!$datatrans): // Transaction Not Found!
		 				$items_array[] = [
							 "info" => $key['info'], 
							 "errorCode" => 7, 
							 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' 
		        	    ];  
		        	    continue;	
		 			else:
		 				$jsonify = json_decode($datatrans->provider_request, true);
		 			    $client_details = ProviderHelper::getClientDetails('player_id', $jsonify['items'][0]['playerId']);
		 			endif;
		 		}

		 		if($client_details == null){
		 			$items_array[] = [
						 "info" => $key['info'], // Info from RSG, MW Should Return it back!
						 "errorCode" => 2, //The playerId was not found
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ];  
	        	    continue;
		 		}
		 		$check_win_exist = $this->gameTransactionEXTLog('provider_trans_id',$key['txId'], 2); // if transaction id exist bypass it
	 			if($check_win_exist != false){
	 				$items_array[] = [
						 "info" => $key['info'], // Info from RSG, MW Should Return it back!
						 "errorCode" => 8, 
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ];  
	        	    continue;
	 			}
 				$game_details = Helper::findGameDetails('game_code', $this->provider_db_id, $key["gameId"]);
				if($game_details == null){ // Game not found
					$items_array[] = [
						 "info" => $key['info'],
						 "errorCode" => 11,  // Game Not Found
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' 
	        	    ]; 
	        	    continue;
				}
				if($key['currencyId'] != $client_details->default_currency){
					$items_array[] = [
						 "info" => $key['info'], // Info from RSG, MW Should Return it back!
						 "errorCode" => 16, // Currency code dont match!
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ];   
	        	    continue;
				}

				$game_transextension = ProviderHelper::createGameTransExtV2($datatrans->game_trans_id, $key['txId'], $key['roundId'], abs($key['winAmount']), 2);

				try {
				 $client_response = ClientRequestHelper::fundTransfer($client_details,abs($key['winAmount']),$game_details->game_code,$game_details->game_name,$game_transextension,$datatrans->game_trans_id,'credit');
				 Helper::saveLog('RSG win CRID = '.$datatrans->game_trans_id, $this->provider_db_id, file_get_contents("php://input"), $client_response);
				} catch (\Exception $e) {
				$items_array[] = array(
					 "info" => $key['info'], 
					 "errorCode" => 999, 
					 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
					);
				ProviderHelper::updatecreateGameTransExt($game_transextension, 'FAILED', $json_data, 'FAILED', $e->getMessage(), 'FAILED', 'FAILED');
				Helper::saveLog('RSG win - FATAL ERROR', $this->provider_db_id, json_encode($items_array), Helper::datesent());
					continue;
				}

				if(isset($client_response->fundtransferresponse->status->code) 
				             && $client_response->fundtransferresponse->status->code == "200"){
					if($key['winAmount'] != 0){
		 	  			if($datatrans->bet_amount > $key['winAmount']){
		 	  				$win = 0; // lost
		 	  				$entry_id = 1; //lost
		 	  				$income = $datatrans->bet_amount - $key['winAmount'];
		 	  			}else{
		 	  				$win = 1; //win
		 	  				$entry_id = 2; //win
		 	  				$income = $datatrans->bet_amount - $key['winAmount'];
		 	  			}
	 	  				$updateTheBet = $this->updateBetToWin($key['roundId'], $key['winAmount'], $income, $win, $entry_id);
		 	  		}else{
		 	  			$updateTheBet = $this->updateBetToWin($key['roundId'], $datatrans->pay_amount, $datatrans->income, 0, $datatrans->entry_id);
		 	  		}
		 	  		
		 	  		ProviderHelper::updatecreateGameTransExt($game_transextension,  $json_data, $items_array, $client_response->requestoclient, $client_response, 'SUCCESS', 'NO DATA');

		 	  		if(isset($key['returnBetsAmount']) && $key['returnBetsAmount'] == true){
		 	  			if(isset($key['betTxId'])){
	        	    		$datatrans = $this->findTransactionRefund($key['betTxId'], 'transaction_id');
	        	    	}else{
	        	    		$datatrans = $this->findTransactionRefund($key['roundId'], 'round_id');
	        	    	}
        	    		$gg = json_decode($datatrans->provider_request);
				 		$total_bets = array();
				 		foreach ($gg->items as $gg_tem) {
							array_push($total_bets, $gg_tem->betAmount);
				 		}
				 		$items_array[] = [
		        	    	 "externalTxId" => $datatrans->game_trans_id, // MW Game Transaction Id
							 "balance" => floatval($client_response->fundtransferresponse->balance),
							 "betsAmount" => floatval(array_sum($total_bets)),
							 "info" => $key['info'], // Info from RSG, MW Should Return it back!
							 "errorCode" => 1,
							 "metadata" => isset($key['metadata']) ? $key['metadata'] : '', // Optional but must be here!
		        	    ];
		 	  		}else{
		 	  			$items_array[] = [
		        	    	 "externalTxId" => $datatrans->game_trans_id, // MW Game Transaction Id
							 "balance" => floatval($client_response->fundtransferresponse->balance),
							 "info" => $key['info'], // Info from RSG, MW Should Return it back!
							 "errorCode" => 1,
							 "metadata" => isset($key['metadata']) ? $key['metadata'] : '', // Optional but must be here!
		        	    ];
		 	  		}

				}else{ // Unknown Response Code
					$items_array[] = array(
						 "info" => $key['info'], 
						 "errorCode" => 999, 
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
						);
					ProviderHelper::updatecreateGameTransExt($game_transextension, 'FAILED', $json_data, 'FAILED', $client_response, 'FAILED', 'FAILED');
					Helper::saveLog('RSG win - FATAL ERROR', $this->provider_db_id, $items_array, Helper::datesent());
					continue;
				}    
		} // END FOREACH
		$response = array(
			 "timestamp" => date('YmdHisms'),
		     "signature" => $this->createSignature(date('YmdHisms')),
			 "errorCode" => 1,
			 "items" => $items_array,
		);	
		Helper::saveLog('RSG win - SUCCESS', $this->provider_db_id, file_get_contents("php://input"), $response);
		return $response;
	}

	/**
	 *	
	 * NOTE
	 * Accept Bet and Win At The Same Time!
	 */
	public function betwin(Request $request){
		Helper::saveLog('RSG betwin - EH', $this->provider_db_id, file_get_contents("php://input"), 'ENDPOINT HIT');
		$json_data = json_decode(file_get_contents("php://input"), true);
		if($json_data == null){
			return $this->noBody();
		}
		if($json_data['operatorId'] != $this->operator_id){ //Wrong Operator Id 
			return $this->wrongOperatorID();
		}
		if(!$this->authMethod($json_data['operatorId'], $json_data['timestamp'], $json_data['signature'])){ 
			return $this->authError();
		}
		
		$items_allOrNone = array(); // ITEMS TO ROLLBACK IF ONE OF THE ITEMS FAILED!
		$items_revert_update = array(); // If failed revert changes
		$items_array = array();

		# Missing Parameters
		if(!isset($json_data['providerId']) || !isset($json_data['allOrNone']) || !isset($json_data['signature']) || !isset($json_data['timestamp']) || !isset($json_data['operatorId']) || !isset($json_data['items'])){
			$response = array(
					 "timestamp" => date('YmdHisms'),
				     "signature" => $this->createSignature(date('YmdHisms')),
					 "errorCode" => 17,
					 "items" => $items_array,
   			);	
			return $response;
		}

	 	$error_encounter = 0;
	    $global_error = 1;
		foreach ($json_data['items'] as $key) { // FOREACH CHECK
		 		if($json_data['allOrNone'] == 'true'){ // IF ANY ITEM FAILED DONT PROCESS IT
		 			# Missing item param
					if(!isset($key['txId']) || !isset($key['betAmount']) || !isset($key['winAmount']) || !isset($key['token']) || !isset($key['playerId']) || !isset($key['roundId']) || !isset($key['gameId'])){
						$global_error = 17;
						$error_encounter = 1;
						continue;
					}
		 			$game_details = Helper::findGameDetails('game_code', $this->provider_db_id, $key["gameId"]);
					if($game_details == null){ // Game not found
						$global_error = 11;
						$error_encounter= 1;
						continue;
					}
					if($key['ignoreExpiry'] != 'false'){
				 		$token_check = Helper::tokenCheck($key["token"]);
						if($token_check != true){
							$global_error = 3;
							$error_encounter = 1;
							continue;
						}
					}
					$client_details = ProviderHelper::getClientDetails('token', $key["token"]);	
					if ($client_details == null){ // SessionNotFound
						$global_error = 2;
						$error_encounter= 1;
						continue;
					}
					if($client_details != null){ // Wrong Player ID
						if($client_details->player_id != $key["playerId"]){
							$global_error = 4;
							$error_encounter= 1;
							continue;
						}
						if($key['currencyId'] != $client_details->default_currency){
							$global_error = 16;
							$error_encounter= 1; 
							continue;
						}
						$client_player = ProviderHelper::playerDetailsCall($key["token"]);
						if($client_player == 'false'){ // client cannot be reached! http errors etc!
							$global_error = 999;
							$error_encounter= 1; 
							continue;
						}
					}
					$check_win_exist = $this->gameTransactionEXTLog('provider_trans_id',$key['txId'], 1); 
		 			if($check_win_exist != false){
		 				$global_error = 8; // Duplicate TxId in the call
						$error_encounter = 1;
		        	    continue;
		 			}
		 			$check_win_exist = $this->gameTransactionEXTLog('provider_trans_id',$key['txId'], 2);
		 			if($check_win_exist != false){
		 				$global_error = 8; // Duplicate TxId in the call
						$error_encounter = 1;
		        	    continue;
		 			}
				} // END ALL OR NON
		} // END FOREACH CHECK
		if($error_encounter != 0){ // ELSE PROCEED TO CLIENT TRANSFERING
			$response = array(
					 "timestamp" => date('YmdHisms'),
				     "signature" => $this->createSignature(date('YmdHisms')),
					 "errorCode" => $global_error,
					 "items" => $items_array,
   			);	
			return $response;
		}

		// ALL GOOD
		$items_array = array(); // ITEMS INFO
		foreach ($json_data['items'] as $key){
				# Missing item param
				if(!isset($key['txId']) || !isset($key['betAmount']) || !isset($key['winAmount']) || !isset($key['token']) || !isset($key['playerId']) || !isset($key['roundId']) || !isset($key['gameId']) || !isset($key['betInfo']) || !isset($key['winInfo'])){
					 $items_array[] = [
						 "betInfo" => isset($key['betInfo']) ? $key['betInfo'] : '', // Betinfo
					     "winInfo" => isset($key['winInfo']) ? $key['winInfo'] : '', // IWininfo
						 "errorCode" => 17, //The playerId was not found
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ]; 
					continue;
				}
				$game_details = Helper::findGameDetails('game_code', $this->provider_db_id, $key["gameId"]);
					if($game_details == null){ // Game not found
					$items_array[] = [
						 "betInfo" => $key['betInfo'], // Betinfo
					     "winInfo" => $key['winInfo'], // IWininfo
						 "errorCode" => 11, //The playerId was not found
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ];  
	        	    continue;
				}
				if($key['ignoreExpiry'] != 'false'){
				 		$token_check = Helper::tokenCheck($key["token"]);
						if($token_check != true){
							$items_array[] = [
								 "betInfo" => $key['betInfo'], // Betinfo
							     "winInfo" => $key['winInfo'], // IWininfo
								 "errorCode" => 3, 
								 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
			        	    ];  
							continue;
					}
				}
				$client_details = ProviderHelper::getClientDetails('token', $key["token"]);	
				if($client_details == null){
		 			$items_array[] = [
						 "betInfo" => $key['betInfo'], // Betinfo
					     "winInfo" => $key['winInfo'], // IWininfo
						 "errorCode" => 2, 
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ];  
	        	    continue;
		 		}
		 		if($client_details->player_id != $key["playerId"]){
					$items_array[] = [
						"betInfo" => $key['betInfo'], // Betinfo
					    "winInfo" => $key['winInfo'], // IWininfo
						"errorCode" => 4, 
						"metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ];  
					continue;
				}
				if($key['currencyId'] != $client_details->default_currency){
					$items_array[] = [
						 "betInfo" => $key['betInfo'], // Betinfo
					     "winInfo" => $key['winInfo'], // IWininfo
						 "errorCode" => 16, // Currency code dont match!
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ];   
	        	    continue;
				}
	 			$check_win_exist = $this->gameTransactionEXTLog('provider_trans_id',$key['txId'], 1); 
	 			if($check_win_exist != false){
	 				$items_array[] = [
						 "betInfo" => $key['betInfo'], // Betinfo
					     "winInfo" => $key['winInfo'], // IWininfo
						 "errorCode" => 8, 
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ];  
	        	    continue;
	 			}
	 			$check_win_exist = $this->gameTransactionEXTLog('provider_trans_id',$key['txId'], 2);
	 			if($check_win_exist != false){
	 				$items_array[] = [
						 "betInfo" => $key['betInfo'], // Betinfo
					     "winInfo" => $key['winInfo'], // IWininfo
						 "errorCode" => 8, 
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ]; 
	        	    continue;
	 			}
				if($client_player->playerdetailsresponse->balance < $key['betAmount']){
					$items_array[] = [
						 "betInfo" => $key['betInfo'], // Betinfo
						 "winInfo" => $key['winInfo'], // IWininfo
						 "errorCode" => 6, // Player Low Balance!
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ]; 
				}

				## DEBIT
				$payout_reason = 'Bet : '.$this->getOperationType($key['betOperationType']);
		 		$win_or_lost = 0;
		 		$method = 1;
		 		$income = null; // Sample
		 	    $token_id = $client_details->token_id;
		 	    if(isset($key['roundId'])){
		 	    	$round_id = $key['roundId'];
		 	    }else{
		 	    	$round_id = 1;
		 	    }
		 	    if(isset($key['txId'])){
		 	    	$provider_trans_id = $key['txId'];
		 	    }else{
		 	    	$provider_trans_id = null;
		 	    }

				$game_trans = ProviderHelper::createGameTransaction($token_id, $game_details->game_id, $key['betAmount'],  $key['betAmount'], $method, $win_or_lost, null, $payout_reason, $income, $provider_trans_id, $round_id);

				$game_transextension = ProviderHelper::createGameTransExtV2($game_trans, $key['txId'], $key['roundId'], abs($key['betAmount']), 1);

				try {
				 $client_response = ClientRequestHelper::fundTransfer($client_details,abs($key['betAmount']),$game_details->game_code,$game_details->game_name,$game_transextension,$game_trans,'debit');
				 Helper::saveLog('RSG betwin CRID = '.$game_trans, $this->provider_db_id, file_get_contents("php://input"), $client_response);
				} catch (\Exception $e) {
				$items_array[] = array(
					 "info" => $key['info'], 
					 "errorCode" => 999, 
					 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
					);
				ProviderHelper::updatecreateGameTransExt($game_transextension, 'FAILED', $json_data, 'FAILED', $e->getMessage(), 'FAILED', 'FAILED');
				Helper::saveLog('RSG betwin - FATAL ERROR', $this->provider_db_id, json_encode($items_array), Helper::datesent());
					continue;
				}

				if(isset($client_response->fundtransferresponse->status->code) 
				             && $client_response->fundtransferresponse->status->code == "200"){
					# CREDIT
					$game_transextension2 = ProviderHelper::createGameTransExtV2($game_trans, $key['txId'], $key['roundId'], abs($key['betAmount']), 2);
					$client_response2 = ClientRequestHelper::fundTransfer($client_details,abs($key['winAmount']),$game_details->game_code,$game_details->game_name,$game_transextension2,$game_trans,'credit');
					 Helper::saveLog('RSG betwin CRID = '.$game_trans, $this->provider_db_id, file_get_contents("php://input"), $client_response2);
			 		$payout_reason = 'Win : '.$this->getOperationType($key['winOperationType']);
			 		$win_or_lost = 1;
			 		$method = 2;
			 	    $token_id = $client_details->token_id;
			 	    if(isset($key['roundId'])){
			 	    	$round_id = $key['roundId'];
			 	    }else{
			 	    	$round_id = 1;
			 	    }
			 	    if(isset($key['txId'])){
			 	    	$provider_trans_id = $key['txId'];
			 	    }else{
			 	    	$provider_trans_id = null;
			 	    }
			 	    if(isset($key['betTxId'])){
        	    		$bet_transaction_detail = $this->findGameTransaction($key['betTxId']);
        	    		$bet_transaction = $bet_transaction_detail->bet_amount;
        	    	}else{
        	    		$bet_transaction_detail = $this->findPlayerGameTransaction($key['roundId'], $key['playerId']);
        	    		$bet_transaction = $bet_transaction_detail->bet_amount;
        	    	}
			 	    $income = $bet_transaction - $key['winAmount']; // Sample	
		 	  		$game_details = Helper::findGameDetails('game_code', $this->provider_db_id, $key['gameId']);
					if($key['winAmount'] != 0){
		 	  			if($bet_transaction_detail->bet_amount > $key['winAmount']){
		 	  				$win = 0; // lost
		 	  				$entry_id = 1; //lost
		 	  				$income = $bet_transaction_detail->bet_amount - $key['winAmount'];
		 	  			}else{
		 	  				$win = 1; //win
		 	  				$entry_id = 2; //win
		 	  				$income = $bet_transaction_detail->bet_amount - $key['winAmount'];
		 	  			}
		 	  				$updateTheBet = $this->updateBetToWin($key['roundId'], $key['winAmount'], $income, $win, $entry_id);
		 	  		}
					# CREDIT
					$items_array[] = [
	        	    	 "externalTxId" => $game_trans, // MW Game Transaction Only Save The Last Game Transaction Which is the credit!
						 "balance" => floatval($client_response2->fundtransferresponse->balance),
						 "betInfo" => $key['betInfo'], // Betinfo
						 "winInfo" => $key['winInfo'], // IWininfo
						 "errorCode" => 1,
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ];
	        	    ProviderHelper::updatecreateGameTransExt($game_transextension,  $json_data, $items_array, $client_response->requestoclient, $client_response, 'SUCCESS', 'NO DATA');
	        	    ProviderHelper::updatecreateGameTransExt($game_transextension2,  $json_data, $items_array, $client_response2->requestoclient, $client_response2, 'SUCCESS', 'NO DATA');

				}elseif(isset($client_response->fundtransferresponse->status->code) 
				            && $client_response->fundtransferresponse->status->code == "402"){
					
					$items_array[] = [
						 "betInfo" => $key['betInfo'], // Betinfo
						 "winInfo" => $key['winInfo'], // IWininfo
						 "errorCode" => 6, // Player Low Balance!
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ]; 
	        	    ProviderHelper::updatecreateGameTransExt($game_transextension,  $json_data, $items_array, $client_response->requestoclient, $client_response, 'FAILED', 'NO DATA');
	        	    continue;
				}else{ // Unknown Response Code
					$items_array[] = [
						 "betInfo" => $key['betInfo'], // Betinfo
						 "winInfo" => $key['winInfo'], // IWininfo
						 "errorCode" => 6, // Player Low Balance!
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ]; 
					ProviderHelper::updatecreateGameTransExt($game_transextension,  $json_data, $items_array, $client_response->requestoclient, $client_response, 'FAILED', 'NO DATA');
					Helper::saveLog('RSG betwin - FATAL ERROR', $this->provider_db_id, $items_array, Helper::datesent());
	        	    continue;
				}    
				## DEBIT
		} # END FOREACH
		$response = array(
			 "timestamp" => date('YmdHisms'),
		     "signature" => $this->createSignature(date('YmdHisms')),
			 "errorCode" => 1,
			 "items" => $items_array,
		);
		Helper::saveLog('RSG BETWIN - SUCCESS', $this->provider_db_id, file_get_contents("php://input"), $response);
		return $response;
	}

	/**
	 * UNDERCONSTRUCTION
	 * Refund Find Logs According to gameround, or TransactionID and refund whether it  a bet or win
	 *
	 * refundOriginalBet (No proper explanation on the doc!)	
	 * originalTxtId = either its winTxd or betTxd	
	 * refundround is true = always roundid	
	 * if roundid is missing always originalTxt, same if originaltxtid use roundId
	 *
	 */
	public function refund(Request $request){
		Helper::saveLog('RSG refund - EH', $this->provider_db_id, file_get_contents("php://input"), 'ENDPOINT HIT');
		$json_data = json_decode(file_get_contents("php://input"), true);
		if($json_data == null){
			return $this->noBody();
		}
		if($json_data['operatorId'] != $this->operator_id){ //Wrong Operator Id 
			return $this->wrongOperatorID();
		}
		if(!$this->authMethod($json_data['operatorId'], $json_data['timestamp'], $json_data['signature'])){ 
			return $this->authError();
		}
		$items_allOrNone = array(); // ITEMS TO ROLLBACK IF ONE OF THE ITEMS FAILED!
		$items_revert_update = array(); // If failed revert changes
		$items_array = array();
		$error_encounter = 0;
		$global_error = 1;
		foreach ($json_data['items'] as $key) { // FOREACH CHECK

			if($json_data['allOrNone'] == 'true'){ // IF ANY ITEM FAILED DONT PROCESS IT
 				if(isset($key['roundId']) && $key['roundId'] != ''){// if both playerid and roundid is missing
 				    $client_details = ProviderHelper::getClientDetails('player_id', $key['playerId']);
		 		 	$datatrans = $this->findTransactionRefund($key['roundId'], 'round_id');
 					$transaction_identifier = $key['roundId'];
 					$transaction_identifier_type = 'round_id';
		 		 	if(!$datatrans): // Transaction Not Found!
		 					$datatrans_status = false;
		 			endif;
		 		}else{ // use originalTxid instead
		 			$datatrans = $this->findTransactionRefund($key['originalTxId'], 'transaction_id');
 					$transaction_identifier = $key['originalTxId'];
 					$transaction_identifier_type = 'provider_trans_id';
		 			if(!$datatrans): // Transaction Not Found!
		 					$datatrans_status = false;
		 			endif;
		 			$jsonify = json_decode($datatrans->transaction_detail, true);
		 			$client_details = ProviderHelper::getClientDetails('player_id', $jsonify['items'][0]['playerId']);
		 		}	

		 		if($datatrans == false){
		 			$items_array[] = [
						 "info" => $key['info'],
						 "errorCode" => 7, 
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
				    ];  
		 			$global_error = 7; // Transaction Not Found
					$error_encounter= 1;
					continue;
		 		}
	 		    if($client_details == null){
	 		    	$items_array[] = [
						 "info" => $key['info'],
						 "errorCode" => 2, 
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
				    ];  
					$global_error = 2;
					$error_encounter= 1;
					continue;
	 		    }

	 		    if($client_details != null){
	 		    	if($key['currencyId'] != $client_details->default_currency){
	 		    		$items_array[] = [
							 "info" => $key['info'],
							 "errorCode" =>16, 
							 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
					    ];
						$global_error = 16;
						$error_encounter= 1; 
					}
					$client_player = ProviderHelper::playerDetailsCall($client_details->player_token);
					if($client_player == 'false'){ // client cannot be reached! http errors etc!
						$items_array[] = [
							 "info" => $key['info'],
							 "errorCode" =>999, 
							 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
					    ];
						$global_error = 16;
						$error_encounter= 1; 
						continue;
					}
	 		    }
	 		    $refund_check = $this->gameTransactionEXTLog($transaction_identifier_type, $transaction_identifier, 3);
	 		    if($refund_check != false){
	 		    	$items_array[] = [
							 "info" => $key['info'],
							 "errorCode" =>14, 
							 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
				    ];
				    $global_error = 14; // already refunded
					$error_encounter = 1; 
					continue;
	 		    }
	 		}
	 		
		} // END FOREACH
 		if($error_encounter != 0){ // ELSE PROCEED TO CLIENT TRANSFERING
			$response = array(
					 "timestampa" => date('YmdHisms'),
				     "signature" => $this->createSignature(date('YmdHisms')),
					 "errorCode" => $global_error,
					 "items" => $items_array,
   			);	
			return $response;
		}

		# ALL GOOD
		$items_array = array();
		foreach ($json_data['items'] as $key) { // FOREACH CHECK
				if($key['holdEarlyRefund'] == true){
					$items_array[] = [
						 "info" => $key['info'], // Info from RSG, MW Should Return it back!
						 "errorCode" => 7, // Betwin not found dont hold refundtransaction
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
				    ]; 
				    continue; 
				}
				if(isset($key['roundId']) && $key['roundId'] != ''){// if both playerid and roundid is missing
 				    $client_details = ProviderHelper::getClientDetails('player_id', $key['playerId']);
		 		 	$datatrans = $this->findTransactionRefund($key['roundId'], 'round_id');
 					$transaction_identifier = $key['roundId'];
 					$transaction_identifier_type = 'round_id';
		 		 	if(!$datatrans): // Transaction Not Found!
		 					$datatrans_status = false;
		 			endif;
		 		}else{ // use originalTxid instead
		 			$datatrans = $this->findTransactionRefund($key['originalTxId'], 'transaction_id');
 					$transaction_identifier = $key['originalTxId'];
 					$transaction_identifier_type = 'provider_trans_id';
		 			if(!$datatrans): // Transaction Not Found!
		 					$datatrans_status = false;
		 			endif;
		 			$jsonify = json_decode($datatrans->transaction_detail, true);
		 			$client_details = ProviderHelper::getClientDetails('player_id', $jsonify['items'][0]['playerId']);
		 		}

		 		$refund_check = $this->gameTransactionEXTLog($transaction_identifier_type, $transaction_identifier, 3);
	 		    if($refund_check != false){
	 		    	$items_array[] = [
							 "info" => $key['info'],
							 "errorCode" => 14, 
							 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
				    ];
					continue;
	 		    }

	 			$game_details = Helper::getInfoPlayerGameRound($client_details->player_token);
		 		$gg = json_decode($datatrans->provider_request);
				$amounts_array = array();
	 			foreach ($gg->items as $gg_tem) {
	 				if(isset($gg_tem->betAmount)){
	 					$item = $gg_tem->betAmount; // Bet return as credit
	 				}else{
	 					$item = '-'.$gg_tem->winAmount; // Win return as debit
	 				}
	 				array_push($amounts_array, $item);
		   		}

		   		foreach($amounts_array as $amnts){
		   			if((int)$amnts > 0){
		   				$transactiontype = 'credit'; // Bet Amount should be returned as credit to player
		   			}else{
		   				$transactiontype = 'debit'; // Win Amount should be returned as debit to player
		   			}
		   			$amount = abs($amnts);

		   			// MO GAME FOR THIS MATCH
		   			$round_id = isset($key['roundId']) ? $key['roundId'] : $gg_tem->roundId;
					$round_id = $gg_tem->roundId;
			 		$win = 4; //3 draw, 4 refund
	  				$entry_id = $datatrans->entry_id;

					$updateTheBet = $this->updateBetToWin($key['roundId'], $datatrans->pay_amount, $datatrans->income, $win, $entry_id);

					$game_transextension = ProviderHelper::createGameTransExtV2($datatrans->game_trans_id, $key['txId'], $round_id, abs($amount), 3);
							 	
					try {
					 $client_response = ClientRequestHelper::fundTransfer($client_details,abs($amount),$game_details->game_code,$game_details->game_name,$game_transextension,$datatrans->game_trans_id,$transactiontype,true);
					 Helper::saveLog('RSG refund CRID = '.$datatrans->game_trans_id, $this->provider_db_id, file_get_contents("php://input"), $client_response);
					} catch (\Exception $e) {
					$items_array[] = array(
						 "info" => $key['info'], 
						 "errorCode" => 999, 
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
						);
					ProviderHelper::updatecreateGameTransExt($game_transextension, 'FAILED', $json_data, 'FAILED', $e->getMessage(), 'FAILED', 'FAILED');
					Helper::saveLog('RSG refund - FATAL ERROR', $this->provider_db_id, json_encode($items_array), Helper::datesent());
						continue;
					}

					if(isset($client_response->fundtransferresponse->status->code) 
					             && $client_response->fundtransferresponse->status->code == "200"){
						$items_array[] = [
		        	    	 "externalTxId" => $datatrans->game_trans_id, // MW Game Transaction Id
							 "balance" => floatval($client_response->fundtransferresponse->balance),
							 "info" => $key['info'], // Info from RSG, MW Should Return it back!
							 "errorCode" => 1,
							 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
		        	    ];
						ProviderHelper::updatecreateGameTransExt($game_transextension,  $json_data, $items_array, $client_response->requestoclient, $client_response, 'SUCCESS', 'NO DATA');

					}elseif(isset($client_response->fundtransferresponse->status->code) 
					            && $client_response->fundtransferresponse->status->code == "402"){
						$items_array[] = [
					 	 	"info" => $key['info'],
						 	"errorCode" => 6, // Player Low Balance!
							"metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
		        	    ]; 
		        	    ProviderHelper::updatecreateGameTransExt($game_transextension,  $json_data, $items_array, $client_response->requestoclient, $client_response, 'FAILED', 'NO DATA');
					}else{ // Unknown Response Code
						$items_array[] = [
					 	 	"info" => $key['info'],
						 	"errorCode" => 999, // Player Low Balance!
							"metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
		        	    ]; 
		        	    ProviderHelper::updatecreateGameTransExt($game_transextension,  $json_data, $items_array, $client_response->requestoclient, $client_response, 'FAILED', 'NO DATA');
					}    

				}

		} // END FOREACH

		$response = array(
			 "timestamp" => date('YmdHisms'),
		     "signature" => $this->createSignature(date('YmdHisms')),
			 "errorCode" => 1,
			 "items" => $items_array,
		);	
		return $response;
	}

	/**
	 * Amend Win
	 */
	public function amend(Request $request){
		Helper::saveLog('RSG amend - EH', $this->provider_db_id, file_get_contents("php://input"), 'ENDPOINT HIT');
		$json_data = json_decode(file_get_contents("php://input"), true);
		if($json_data == null){
			return $this->noBody();
		}
		if (!$this->authMethod($json_data['operatorId'], $json_data['timestamp'], $json_data['signature'])){
			return $this->authError();
		}
		if($json_data['operatorId'] != $this->operator_id){
			return $this->wrongOperatorID();
		}
		$items_array = array(); // ITEMS INFO
		# All or none is true
		$error_encounter = 0;
	    $datatrans_status = true;
	    $global_error = 1;
		foreach ($json_data['items'] as $key) { // FOREACH CHECK
		 		if($json_data['allOrNone'] == 'true'){ // IF ANY ITEM FAILED DONT PROCESS IT
						$client_details = ProviderHelper::getClientDetails('player_id', $key['playerId']);
						if($client_details == null){
							$items_array[] = [
								 "info" => $key['info'], // Info from RSG, MW Should Return it back!
								 "errorCode" => 4, //The playerId was not found
								 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
			        	    ];  
							$global_error = 4;
							$error_encounter= 1;
							continue;
						}
						// $checkLog = $this->checkRSGExtLog($key['txId'],$key['roundId'],2);
						$checkLog = ProviderHelper::findGameExt($key['winTxId'], 2, 'transaction_id');
						if($checkLog == 'false'){
							$items_array[] = [
								 "info" => $key['info'], // Info from RSG, MW Should Return it back!
								 "errorCode" => 7, // Win Transaction not found
								 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
			        	    ]; 
			        	    $global_error = 7;
							$error_encounter= 1;
							continue;
						}
						$is_refunded = ProviderHelper::findGameExt($key['txId'], 3, 'transaction_id');
						if($is_refunded != 'false'){
							$items_array[] = [
								 "info" => $key['info'], // Info from RSG, MW Should Return it back!
								 "errorCode" => 8, // transaction already refunded
								 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
			        	    ]; 
			        	    $global_error = 8;
							$error_encounter= 1;
							continue;
						}
						if($key['currencyId'] != $client_details->default_currency){
							$items_array[] = [
								 "info" => $key['info'], // Info from RSG, MW Should Return it back!
								 "errorCode" => 16, // Currency code dont match!
								 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
			        	    ];   	
	        	 	 	    $global_error = 16;
							$error_encounter= 1;
							continue;
						} 
		 		}
	 	}// END FOREACH CHECK
		if($error_encounter != 0){ // ELSE PROCEED TO CLIENT TRANSFERING
			$response = array(
					 "timestamp" => date('YmdHisms'),
				     "signature" => $this->createSignature(date('YmdHisms')),
					 "errorCode" => $global_error,
					 "items" => $items_array,
   			);	
			return $response;
		}


		$items_array = array(); // ITEMS INFO
		// ALL GOOD PROCESS IT
		foreach ($json_data['items'] as $key) {
			$client_details = ProviderHelper::getClientDetails('player_id', $key['playerId']);
			if($client_details == null){
				$items_array[] = [
					 "info" => $key['info'], // Info from RSG, MW Should Return it back!
					 "errorCode" => 4, //The playerId was not found
					 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
        	    ];  
				continue;
			}
			// $checkLog = $this->checkRSGExtLog($key['txId'],$key['roundId'],2);
			$checkLog = ProviderHelper::findGameExt($key['winTxId'], 2, 'transaction_id');
			if($checkLog == 'false'){
				$items_array[] = [
					 "info" => $key['info'], // Info from RSG, MW Should Return it back!
					 "errorCode" => 7, // Win Transaction not found
					 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
        	    ]; 
				continue;
			}
			if($key['currencyId'] != $client_details->default_currency){
				$items_array[] = [
					 "info" => $key['info'], // Info from RSG, MW Should Return it back!
					 "errorCode" => 16, // Currency code dont match!
					 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
        	    ];   	
				continue;
			} 
			$is_refunded = ProviderHelper::findGameExt($key['txId'], 3, 'transaction_id');
			if($is_refunded != 'false'){
				$items_array[] = [
					 "info" => $key['info'], // Info from RSG, MW Should Return it back!
					 "errorCode" => 8, // transaction already refunded
					 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
        	    ]; 
				continue;
			}
			$game_details = Helper::getInfoPlayerGameRound($client_details->player_token);

			$gametransaction_details = $this->findTransactionRefund($key['winTxId'], 'provider_id');
			// 37 Amend Credit,38 Amend Debit 
			if(isset($key['operationType'])){
				if($key['operationType'] == 37){
					$transaction_type = 'credit';
				}elseif($key['operationType'] == 38){
					$transaction_type = 'debit';
				}else{
					$items_array[] = [
						 "info" => $key['info'], // Info from RSG, MW Should Return it back!
						 "errorCode" => 19, // transaction already refunded
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : '' // Optional but must be here!
	        	    ]; 
					continue;
				}
			}
	 		$amount = $key['amendAmount'];

 		    $token_id = $client_details->token_id;
	 	    if(isset($key['roundId'])){
	 	    	$round_id = $key['roundId'];
	 	    }else{
	 	    	$round_id = 'RSGNOROUND';
	 	    }
	 	    if(isset($key['txId'])){
	 	    	$provider_trans_id = $key['txId'];
	 	    }else{
	 	    	$provider_trans_id = 'RSGNOPROVIDERTXID';
	 	    }
	 	    $round_id = $key['roundId'];
  			if($key['operationType'] == 37){ // CREADIT/ADD
				$pay_amount = $gametransaction_details->pay_amount + $amount;
  				$income = $gametransaction_details->bet_amount - $pay_amount;
	 		}else{ // DEBIT/SUBTRACT
	 			$pay_amount = $gametransaction_details->pay_amount - $amount;
  				$income = $gametransaction_details->bet_amount - $pay_amount;
	 		}

	 		if($pay_amount > $gametransaction_details->bet_amount){
	 			$win = 0; //lost
	  				$entry_id = 1; //lost
	 		}else{
	 			$win = 1; //win
  				$entry_id = 2; //win
	 		}

	 		$updateTheBet = $this->updateBetToWin($key['roundId'], $pay_amount, $income, $win, $entry_id);		
 			$game_transextension = ProviderHelper::createGameTransExtV2($gametransaction_details->game_trans_id,$provider_trans_id, $round_id, abs($amount), 3);

	 		try {
			 $client_response = ClientRequestHelper::fundTransfer($client_details,abs( $amount),$game_details->game_code,$game_details->game_name,$game_transextension,$gametransaction_details->game_trans_id,$transaction_type,true);
			 Helper::saveLog('RSG amend CRID = '.$gametransaction_details->game_trans_id, $this->provider_db_id, file_get_contents("php://input"), $client_response);
			} catch (\Exception $e) {
			$items_array[] = array(
				 "info" => $key['info'], 
				 "errorCode" => 999, 
				 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
			);
			ProviderHelper::updatecreateGameTransExt($game_transextension, 'FAILED', $json_data, 'FAILED', $e->getMessage(), 'FAILED', 'FAILED');
			Helper::saveLog('RSG win - FATAL ERROR', $this->provider_db_id, json_encode($items_array), Helper::datesent());
				continue;
			}

			if(isset($client_response->fundtransferresponse->status->code) 
			             && $client_response->fundtransferresponse->status->code == "200"){
				$items_array[] = [
        	    	 "externalTxId" => $gametransaction_details->game_trans_id, // MW Game Transaction Id
					 "balance" => floatval($client_response->fundtransferresponse->balance),
					 "info" => $key['info'], // Info from RSG, MW Should Return it back!
					 "errorCode" => 1,
					 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''// Optional but must be here!
        	    ];
				ProviderHelper::updatecreateGameTransExt($game_transextension,  $json_data, $items_array, $client_response->requestoclient, $client_response, 'SUCCESS', 'NO DATA');

			}elseif(isset($client_response->fundtransferresponse->status->code) 
			            && $client_response->fundtransferresponse->status->code == "402"){
				 $items_array[] = array(
						 "info" => $key['info'], 
						 "errorCode" => 6, 
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
		   		 );
		   		 ProviderHelper::updatecreateGameTransExt($game_transextension,  $json_data, $items_array, $client_response->requestoclient, $client_response, 'SUCCESS', 'NO DATA');
			}else{ // Unknown Response Code
				$items_array[] = array(
						 "info" => $key['info'], 
						 "errorCode" => 999, 
						 "metadata" => isset($key['metadata']) ? $key['metadata'] : ''
		   		);
				ProviderHelper::updatecreateGameTransExt($game_transextension,  'FAILED', 'FAILED', $client_response->requestoclient, $client_response, 'FAILED', 'FAILED');
			}    
		} // END FOREACH
		$response = array(
					 "timestamp" => date('YmdHisms'),
				     "signature" => $this->createSignature(date('YmdHisms')),
					 "errorCode" => 1,
					 "items" => $items_array,
				);	
		Helper::saveLog('RSG amend - SUCCESS', $this->provider_db_id, file_get_contents("php://input"), $response);
		return $response;
	}

	/**
	 * Pull out data from the Game exstension logs!
	 * 
	 */
	public  function checkRSGExtLog($provider_transaction_id,$round_id=false,$type=false){
		if($type&&$round_id){
			$game = DB::table('game_transaction_ext')
				   ->where('provider_trans_id',$provider_transaction_id)
				   ->where('round_id',$round_id)
				   ->where('game_transaction_type',$type)
				   ->first();
		}
		else{
			$game = DB::table('game_transaction_ext')
				    ->where('provider_trans_id',$provider_transaction_id)
				    ->first();
		}
		return $game ? true :false;
	}

	/**
	 * Pull out data from the Game exstension logs!
	 * @param $trans_type = round_id/provider_trans_id
	 * @param $trans_identifier = identifier
	 * @param $type = 1 = lost, 2 = win, 3 = refund
	 * 
	 */
	public  function gameTransactionEXTLog($trans_type,$trans_identifier,$type=false){

		$game = DB::table('game_transaction_ext')
				   ->where($trans_type, $trans_identifier)
				   ->where('game_transaction_type',$type)
				   ->first();
		return $game ? $game :false;
	}

	/**
	 * Create Game Extension Logs bet/Win/Refund
	 * @param [int] $[gametransaction_id] [<ID of the game transaction>]
	 * @param [json array] $[provider_request] [<Incoming Call>]
	 * @param [json array] $[mw_request] [<Outgoing Call>]
	 * @param [json array] $[mw_response] [<Incoming Response Call>]
	 * @param [json array] $[client_response] [<Incoming Response Call>]
	 * 
	 */
	// public  function createRSGTransactionExt($gametransaction_id,$provider_request,$mw_request,$mw_response,$client_response, $transaction_detail,$game_transaction_type, $amount=null, $provider_trans_id=null, $round_id=null){

	// 	$provider_request_details = array();
	// 	foreach($provider_request['items'] as $prd){
	// 		$provider_request_details = $prd;
	// 	}

	// 	// game_transaction_type = 1=bet,2=win,3=refund	
	// 	if($game_transaction_type == 1){
	// 		// $amount = $provider_request_details['bet'];
	// 		$amount = $amount;
	// 	}elseif($game_transaction_type == 2){
	// 		// $amount = $provider_request_details['winAmount'];
	// 		$amount = $amount;
	// 	}elseif($game_transaction_type == 3){
	// 		$amount = $amount;
	// 	}

	// 	$gametransactionext = array(
	// 		"game_trans_id" => $gametransaction_id,
	// 		"provider_trans_id" => $provider_trans_id,
	// 		"round_id" => $round_id,
	// 		"amount" => $amount,
	// 		"game_transaction_type"=>$game_transaction_type,
	// 		"provider_request" => json_encode($provider_request),
	// 		"mw_request"=>json_encode($mw_request),
	// 		"mw_response" =>json_encode($mw_response),
	// 		"client_response" =>json_encode($client_response),
	// 		"transaction_detail" =>json_encode($transaction_detail),
	// 	);
	// 	$gamestransaction_ext_ID = DB::table("game_transaction_ext")->insertGetId($gametransactionext);
	// 	return $gamestransaction_ext_ID;
	// }


	public  function createGameTransExtV2($game_trans_id, $provider_trans_id, $round_id, $amount, $game_type, $provider_request='FAILED', $mw_response='FAILED', $mw_request='FAILED', $client_response='FAILED', $transaction_detail='FAILED', $general_details=null){

		// $provider_request_details = array();
		// foreach($provider_request['items'] as $prd){
		// 	$provider_request_details = $prd;
		// }

		// // game_transaction_type = 1=bet,2=win,3=refund	
		// if($game_transaction_type == 1){
		// 	// $amount = $provider_request_details['bet'];
		// 	$amount = $amount;
		// }elseif($game_transaction_type == 2){
		// 	// $amount = $provider_request_details['winAmount'];
		// 	$amount = $amount;
		// }elseif($game_transaction_type == 3){
		// 	$amount = $amount;
		// }

		$gametransactionext = array(
			"game_trans_id" => $gametransaction_id,
			"provider_trans_id" => $provider_trans_id,
			"round_id" => $round_id,
			"amount" => $amount,
			"game_transaction_type"=>$game_transaction_type,
			"provider_request" => json_encode($provider_request),
			"mw_request"=>json_encode($mw_request),
			"mw_response" =>json_encode($mw_response),
			"client_response" =>json_encode($client_response),
			"transaction_detail" =>json_encode($transaction_detail),
		);

		$gamestransaction_ext_ID = DB::table("game_transaction_ext")->insertGetId($gametransactionext);
		return $gamestransaction_ext_ID;
	}


    /**
	 * Find The Transactions For Refund, Providers Transaction ID
	 * 
	 */
    public  function findTransactionRefund($transaction_id, $type) {

    		$transaction_db = DB::table('game_transactions as gt')
					    	// ->select('gt.*', 'gte.transaction_detail')
					    	->select('*')
						    ->leftJoin("game_transaction_ext AS gte", "gte.game_trans_id", "=", "gt.game_trans_id");
		  
		    if ($type == 'transaction_id') {
				$transaction_db->where([
			 		["gte.provider_trans_id", "=", $transaction_id],
			 	]);
			}
			if ($type == 'round_id') {
				$transaction_db->where([
			 		["gte.round_id", "=", $transaction_id],
			 	]);
			}
			if ($type == 'bet') { // TEST
				$transaction_db->where([
			 		["gt.round_id", "=", $transaction_id],
			 		["gt.payout_reason",'like', '%BET%'],
			 	]);
			}
			if ($type == 'refundbet') { // TEST
				$transaction_db->where([
			 		["gt.round_id", "=", $transaction_id],
			 	]);
			}
			$result= $transaction_db
	 			->latest('token_id')
	 			->first();

			if($result){
				return $result;
			}else{
				return false;
			}
	}

	/**
	 * Find The Transactions For Win/bet, Providers Transaction ID
	 * 
	 */
	public  function findGameTransaction($transaction_id) {
    		$transaction_db = DB::table('game_transactions as gt')
		 				   ->where('gt.provider_trans_id', $transaction_id)
		 				   ->latest()
		 				   ->first();
		   	return $transaction_db ? $transaction_db : false;
	}

	/**
	 * Find win to amend
	 * @param $roundid = roundid, $transaction_type 1=bet, 2=win
	 * 
	 */
	public  function amendWin($roundid, $transaction_type) {
    		$transaction_db = DB::table('game_transactions as gt')
					    	->select('gt.token_id' ,'gte.*', 'gte.transaction_detail')
						    ->leftJoin("game_transaction_ext AS gte", "gte.game_trans_id", "=", "gt.game_trans_id")
						    ->where("gte.game_transaction_type" , $transaction_type) // Win Type
						    ->where("gte.round_id", $roundid)
						    ->first();
			return $transaction_db ? $transaction_db : false;
	}

	/**
	 * Find bet and update to win 
	 *
	 */
	public  function updateBetToWin($round_id, $pay_amount, $income, $win, $entry_id) {
   	    $update = DB::table('game_transactions')
                ->where('round_id', $round_id)
                ->update(['pay_amount' => $pay_amount, 
	        		  'income' => $income, 
	        		  'win' => $win, 
	        		  'entry_id' => $entry_id,
	        		  'transaction_reason' => 'Bet updated to win'
	    		]);
		return ($update ? true : false);
	}

	/**
	 * Find The Transactions For Win/bet, Providers Transaction ID
	 */
	public  function findPlayerGameTransaction($round_id, $player_id) {
	    $player_game = DB::table('game_transactions as gts')
		    		->select('*')
		    		->join('player_session_tokens as pt','gts.token_id','=','pt.token_id')
                    ->join('players as pl','pt.player_id','=','pl.player_id')
                    ->where('pl.player_id', $player_id)
                    ->where('gts.round_id', $round_id)
                    ->first();
        // $json_data = json_encode($player_game);
	    return $player_game;
	}

	/**
	 * Find The Transactions For Refund, Providers Transaction ID
	 * @return  [<string>]
	 * 
	 */
    public  function getOperationType($operation_type) {

    	$operation_types = [
    		'1' => 'General Bet',
    		'2' => 'General Win',
    		'3' => 'Refund',
    		'4' => 'Bonus Bet',
    		'5' => 'Bonus Win',
    		'6' => 'Round Finish',
    		'7' => 'Insurance Bet',
    		'8' => 'Insurance Win',
    		'9' => 'Double Bet',
    		'10' => 'Double Win',
    		'11' => 'Split Bet',
    		'12' => 'Split Win',
    		'13' => 'Ante Bet',
    		'14' => 'Ante Win',
    		'15' => 'General Bet Behind',
    		'16' => 'General Win Behind',
    		'17' => 'Split Bet Behind',
    		'18' => 'Split Win Behind',
    		'19' => 'Double Bet Behind',
    		'20' => 'Double Win Behind',
    		'21' => 'Insurance Bet Behind',
    		'22' => 'Insurance Win Behind',
    		'23' => 'Call Bet',
    		'24' => 'Call Win',
    		'25' => 'Jackpot Bet',
    		'26' => 'Jackpot Win',
    		'27' => 'Tip',
    		'28' => 'Free Bet Win',
    		'29' => 'Free Spin Win',
    		'30' => 'Gift Bet',
    		'31' => 'Gift Win',
    		'32' => 'Deposit',
    		'33' => 'Withdraw',
    		'34' => 'Fee',
    		'35' => 'Win Tournament',
    		'36' => 'Cancel Fee',
    		'37' => 'Amend Credit',
    		'38' => 'Amend Debit',
    		'39' => 'Feature Trigger Bet',
    		'40' => 'Feature Trigger Win',
    	];
    	if(array_key_exists($operation_type, $operation_types)){
    		return $operation_types[$operation_type];
    	}else{
    		return 'Operation Type is unknown!!';
    	}

	}


	/**
	 * Helper method
	 * @return  [<Reversed Data>]
	 * 
	 */
	public function reverseDataBody($requesttosend){

		$reversed_data = $requesttosend;
	    $transaction_to_reverse = $reversed_data['fundtransferrequest']['fundinfo']['transactiontype'];
		$reversed_transaction_type =  $transaction_to_reverse == 'debit' ? 'credit' : 'debit';
		$reversed_data['fundtransferrequest']['fundinfo']['transactiontype'] = $reversed_transaction_type;
		$reversed_data['fundtransferrequest']['fundinfo']['rollback'] = 'true';

		return $reversed_data;
	}

	/**
	 * Client Player Details API Call
	 * @param $[data] [<array of data>]
	 * @param $[refreshtoken] [<Default False, True token will be requested>]
	 * 
	 */
	public function megaRollback($data_to_rollback, $items=[]){

		foreach($data_to_rollback as $rollback){
	    	try {
	    		$client = new Client([
				    'headers' => [ 
					    	'Content-Type' => 'application/json',
					    	'Authorization' => 'Bearer '.$rollback['header']
					    ]
				]);
				$datatosend = $rollback['body'];
				$guzzle_response = $client->post($rollback['url'],
				    ['body' => json_encode($datatosend)]
				);
				$client_response = json_decode($guzzle_response->getBody()->getContents());
				Helper::saveLog('RSG Rollback Succeed', $this->provider_db_id, json_encode($datatosend), $client_response);
				Helper::saveLog('RSG Rollback Succeed', $this->provider_db_id, json_encode($items), $client_response);
	    	} catch (\Exception $e) {
	    		Helper::saveLog('RSG rollback failed  response as item', $this->provider_db_id, json_encode($datatosend), json_encode($items));
	    	}
		}

	}

	/**
	 * Revert the changes made to bet/win transaction
	 * @param $[items_revert_update] [<array of data>]
	 * 
	 */
	public function rollbackChanges($items_revert_update){
		foreach($items_revert_update as $undo):
		     DB::table('game_transactions')
            ->where('game_trans_id', $undo['game_trans_id'])
            ->update(['win' => $undo['win'], 
        		  'income' => $undo['income'], 
        		  'entry_id' =>$undo['entry_id'],
        		  'pay_amount' =>$undo['pay_amount'],
        		  'transaction_reason' => 'Bet updated to win'
    		]);
		endforeach;
	}

	/**
	 * Client Player Details API Call
	 * @return [Object]
	 * @param $[player_token] [<players token>]
	 * @param $[refreshtoken] [<Default False, True token will be requested>]
	 * 
	 */
	public function playerDetailsCall($player_token, $refreshtoken=false){
		$client_details = DB::table("clients AS c")
					 ->select('p.client_id', 'p.player_id', 'p.username', 'p.email', 'p.client_player_id', 'p.language', 'p.currency', 'pst.token_id', 'pst.player_token' , 'c.client_url', 'c.default_currency', 'pst.status_id', 'p.display_name', 'c.client_api_key', 'cat.client_token AS client_access_token', 'ce.player_details_url', 'ce.fund_transfer_url')
					 ->leftJoin("players AS p", "c.client_id", "=", "p.client_id")
					 ->leftJoin("player_session_tokens AS pst", "p.player_id", "=", "pst.player_id")
					 ->leftJoin("client_endpoints AS ce", "c.client_id", "=", "ce.client_id")
					 ->leftJoin("client_access_tokens AS cat", "c.client_id", "=", "cat.client_id")
					 ->where("pst.player_token", "=", $player_token)
					 ->latest('token_id')
					 ->first();
		if($client_details){
			try{
				$client = new Client([
				    'headers' => [ 
				    	'Content-Type' => 'application/json',
				    	'Authorization' => 'Bearer '.$client_details->client_access_token
				    ]
				]);
				$datatosend = ["access_token" => $client_details->client_access_token,
					"hashkey" => md5($client_details->client_api_key.$client_details->client_access_token),
					"type" => "playerdetailsrequest",
					"clientid" => $client_details->client_id,
					"playerdetailsrequest" => [
						"client_player_id" => $client_details->client_player_id,
						"token" => $player_token,
						// "currencyId" => $client_details->currency,
						"gamelaunch" => false,
						"refreshtoken" => $refreshtoken
					]
				];
				$guzzle_response = $client->post($client_details->player_details_url,
				    ['body' => json_encode($datatosend)]
				);
				$client_response = json_decode($guzzle_response->getBody()->getContents());
			 	return $client_response;
            }catch (\Exception $e){
               return false;
            }
		}else{
			return false;
		}
	}

	/**
	 * Client PInfo
	 * @return [Object]
	 * @param $[type] [<token, player_id, site_url, username>]
	 * @param $[value] [<value to be searched>]
	 * 
	 */
	public function _getClientDetails($type = "", $value = "") {
		$query = DB::table("clients AS c")
					 ->select('p.client_id', 'p.player_id', 'p.username', 'p.email', 'p.client_player_id','p.language', 'p.currency', 'pst.token_id', 'pst.player_token' , 'c.client_url', 'c.default_currency', 'pst.status_id', 'p.display_name', 'c.client_api_key', 'cat.client_token AS client_access_token', 'ce.player_details_url', 'ce.fund_transfer_url')
					 ->leftJoin("players AS p", "c.client_id", "=", "p.client_id")
					 ->leftJoin("player_session_tokens AS pst", "p.player_id", "=", "pst.player_id")
					 ->leftJoin("client_endpoints AS ce", "c.client_id", "=", "ce.client_id")
					 ->leftJoin("client_access_tokens AS cat", "c.client_id", "=", "cat.client_id");
					if ($type == 'token') {
						$query->where([
					 		["pst.player_token", "=", $value],
					 		// ["pst.status_id", "=", 1]
					 	]);
					}
					if ($type == 'player_id') {
						$query->where([
					 		["p.player_id", "=", $value],
					 		// ["pst.status_id", "=", 1]
					 	]);
					}
					if ($type == 'site_url') {
						$query->where([
					 		["c.client_url", "=", $value],
					 	]);
					}
					if ($type == 'username') {
						$query->where([
					 		["p.username", $value],
					 	]);
					}
					$result= $query
					 			->latest('token_id')
					 			->first();

			return $result;
	}

	// public function findGameDetails($type, $provider_id, $identification) {
	// 	    $game_details = DB::table("games as g")
	// 			->leftJoin("providers as p","g.provider_id","=","p.provider_id");
				
	// 	    if ($type == 'game_code') {
	// 			$game_details->where([
	// 		 		["g.provider_id", "=", $provider_id],
	// 		 		["g.game_code",'=', $identification],
	// 		 	]);
	// 		}
	// 		$result= $game_details->first();
	//  		return $result;
	// }


	// public static function saveGame_transaction($token_id, $game_id, $bet_amount, $payout, $entry_id,  $win=0, $transaction_reason = null, $payout_reason = null , $income=null, $provider_trans_id=null, $round_id=1) {
	// 	$data = [
	// 				"token_id" => $token_id,
	// 				"game_id" => $game_id,
	// 				"round_id" => $round_id,
	// 				"bet_amount" => $bet_amount,
	// 				"provider_trans_id" => $provider_trans_id,
	// 				"pay_amount" => $payout,
	// 				"income" => $income,
	// 				"entry_id" => $entry_id,
	// 				"win" => $win,
	// 				"transaction_reason" => $transaction_reason,
	// 				"payout_reason" => $payout_reason
	// 			];
	// 	$data_saved = DB::table('game_transactions')->insertGetId($data);
	// 	return $data_saved;
	// }

}


// NOTES DONT DELETE!

// UPDATE ERROR CODES!
// Error code Error description
// 1 No errors were encountered
// 2 Session Not Found
// 3 Session Expired
// 4 Wrong Player Id
// 5 Player Is Blocked
// 6 Low Balance
// 7 Transaction Not Found
// 8 Transaction Already Exists
// 9 Provider Not Allowed For Partner
// 10 Provider's Action Not Found
// 11 Game Not Found
// 12 Wrong API Credentials
// 13 Invalid Method
// 14 Transaction Already Rolled Back
// 15 Wrong Operator Id
// 16 Wrong Currency Id
// 17 Request Parameter Missing
// 18 Invalid Data
// 19 Incorrect Operation Type
// 20 Transaction already won
// 999 General Error


// PREVIOUS AUTH CREDENTIALS
// private $apikey ="321dsfjo34j5olkdsf";
// private $access_token = "123iuysdhfb09875v9hb9pwe8f7yu439jvoiefjs";

// private $digitain_key = "rgstest";
// private $operator_id = '5FB4E74E';

// private $digitain_key = "rgstest";
// private $operator_id = 'D233911A';

// "operatorId":111,
// "timestamp":"202003092113371560",
// "signature":"ba328e6d2358f6d77804e3d342cdee06c2afeba96baada218794abfd3b0ac926",
// "token":"90dbbb443c9b4b3fbcfc59643206a123"
// $digitain_key = "P5rWDliAmIYWKq6HsIPbyx33v2pkZq7l";