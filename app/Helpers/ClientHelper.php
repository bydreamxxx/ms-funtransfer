<?php
namespace App\Helpers;
use DB;
use GuzzleHttp\Client;
use Carbon\Carbon;

class ClientHelper
{
	public static function getClientErrorCode($error_code){
		$msg = [
		  1 => 'Client not found',
		  2 => 'Client is disabled',
		  3 => 'Game not found',
		  4 => 'Games is under maintenance',
		  5 => 'Provider not found',
		  6 => 'Provider is under maintenance',
		];
		return $msg[$error_code];
	}

	// 	  "client_id": "34",
 	//    "client_player_id": "1",
 	//    "username": "charity",
 	//    "email": "charoot@hashen.com",
 	//    "display_name": "charity",
 	//    "game_code": "GHG_DREAMS_OF_GOLD",
 	//    "exitUrl": "demo.freebetrnk.com",
 	//    "game_provider": "Oryx Gaming",
 	//    "token": "c761ba7d338c83ed5f6bb6c6393a7c36",
 	//    "lang": "en"
	
	public static function checkClientID($data){

		// Client Filter [NOT FOUND or DEACTIVATED]
		$client = DB::table('clients')->where('client_id', $data['client_id'])->first();
		if($client == '' || $client == null){ return 1; } // Not Found Client
		if($client->status_id != 1 ){ return 2; } // Client is Disabled

		$games = DB::table('games')->where('game_code', $data['game_code'])->first();
		if($games == '' || $games == null){ return 3; }
		if($games->on_maintenance != 0 ){ return 4; } 

		$sub_provider = DB::table('sub_providers')->where('sub_provider_name', $data['game_provider'])->first();
		if($sub_provider == '' || $sub_provider == null){ return 5; }
		if($sub_provider->on_maintenance != 0){ return 6; }

		return 200; // All Good Request May Proceed!
	}
	
}