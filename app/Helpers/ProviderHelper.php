<?php
namespace App\Helpers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use App\Helpers\Helper;
use DB; 

class ProviderHelper{

	
	/**
	 * EVOPLAY ONLY
	 * @param $args [array of data], 
	 * @param $system_key [system key], 
	 * 
	 */
	public static function getSignature(array $args, $system_key)
    {
        $md5 = array();
	    $args = array_filter($args, function($val){ return !($val === null || (is_array($val) && !$val));});
	    foreach ($args as $required_arg) {
	        $arg = $required_arg;
	        if (is_array($arg)) {
	            $md5[] = implode(':', array_filter($arg, function($val){ return !($val === null || (is_array($val) && !$val));}));
	        } else {
	            $md5[] = $arg;
	        }
	    };
	    $md5[] = $system_key;
	    $md5_str = implode('*', $md5);
	    $md5 = md5($md5_str);

	    return $md5;
    }


    /**
	 * GLOBAL
	 * Client PInfo
	 * @return [Object]
	 * @param $[type] [<token, player_id, site_url, username>]
	 * @param $[value] [<value to be searched>]
	 * 
	 */
    public static function getClientDetails($type = "", $value = "") {
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



	/**
	 * GLOBAL
	 * Client Player Details API Call
	 * @return [Object]
	 * @param $[player_token] [<players token>]
	 * @param $[refreshtoken] [<Default False, True token will be requested>]
	 * 
	 */
	public static function playerDetailsCall($player_token, $refreshtoken=false){

		$client_details = ProviderHelper::getClientDetails('token', $player_token);
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
						"token" => $player_token,
						// "playerId" => $client_details->client_player_id,
						"currencyId" => $client_details->currency,
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
               return 'false';
            }
		}else{
			return 'false';
		}
	}
}